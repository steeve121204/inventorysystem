<?php
session_start();
include "db.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] != "user") { 
    header("Location: login.php"); 
    exit; 
}

$message = "";
$user_id = $_SESSION["user_id"] ?? 0;
$username = $_SESSION["username"] ?? 'User';

function table_exists($conn, $table) {
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}

function column_exists($conn, $table, $column) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

// Handle POST: add sale
if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['add_sale'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $buyername = mysqli_real_escape_string($conn, $_POST['buyername'] ?? '');
    $total_price = floatval($_POST['total_price'] ?? 0);
    $unit_price_post = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : null;
    $sale_date_input = $_POST['sale_date'] ?? '';
    $sale_date = '';
    
    // Handle custom product details
    $custom_product_name = mysqli_real_escape_string($conn, $_POST['custom_product_name'] ?? '');
    $custom_product_category = mysqli_real_escape_string($conn, $_POST['custom_product_category'] ?? '');
    $custom_product_description = mysqli_real_escape_string($conn, $_POST['custom_product_description'] ?? '');
    
    if (!empty($sale_date_input)) {
        $sale_date = mysqli_real_escape_string($conn, date('Y-m-d H:i:s', strtotime($sale_date_input)));
    }

    if ($quantity <= 0) {
        $message = 'Quantity must be greater than zero.';
    } else if ($total_price <= 0) {
        $message = 'Total price must be greater than zero.';
    } else {
        if (empty($custom_product_name)) {
    $product_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
    if (!$product_row) {
        $message = 'Product not found.';
    } else if ($product_row['stock_quantity'] < $quantity) {
        $message = 'Insufficient stock for this sale. Available: ' . $product_row['stock_quantity'];
        // Stop further processing
        header("Location: users_sales.php?message=" . urlencode($message));
        exit;
    } else {
        // Continue with sale processing...
    }
}
        // If custom product name is provided, create a new product
        if (!empty($custom_product_name)) {
            // Create new product for custom sale
            $category_id = null;
            if (!empty($custom_product_category)) {
                $category_check = mysqli_query($conn, "SELECT id FROM category WHERE name = '$custom_product_category'");
                if (mysqli_num_rows($category_check) > 0) {
                    $category_row = mysqli_fetch_assoc($category_check);
                    $category_id = $category_row['id'];
                } else {
                    $category_sql = "INSERT INTO category (name) VALUES ('$custom_product_category')";
                    if (mysqli_query($conn, $category_sql)) {
                        $category_id = mysqli_insert_id($conn);
                    }
                }
            }
            
            $unit_price = $unit_price_post ?? ($total_price / $quantity);
            $sql = "INSERT INTO products (name, category_id, price, stock_quantity, description) VALUES ('$custom_product_name', '$category_id', '$unit_price', 0, '$custom_product_description')";
            
            if (mysqli_query($conn, $sql)) {
                $product_id = mysqli_insert_id($conn);
                $message = 'Custom product created and sale recorded successfully.';
            } else {
                $message = 'Error creating custom product: ' . mysqli_error($conn);
            }
        }

        // Process the sale
        if (!empty($product_id)) {
            $product_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
            if (!$product_row) {
                $message = 'Product not found.';
            } else if ($product_row['stock_quantity'] < $quantity && empty($custom_product_name)) {
                $message = 'Insufficient stock for this sale.';
            } else {
                // Process sale with product
                $unit_price = ($unit_price_post !== null && $unit_price_post >= 0) ? $unit_price_post : (float)$product_row['price'];
                $computed_total = round($unit_price * $quantity, 2);
                $total_column = column_exists($conn, 'sales', 'total_amount') ? 'total_amount' : (column_exists($conn, 'sales', 'total_price') ? 'total_price' : 'total_amount');

                $columns = ['product_id','user_id','quantity',$total_column];
                $values = ["'$product_id'", "'$user_id'", "'$quantity'", "'$computed_total'"]; 

                // Handle buyer name column
                $buyer_column = '';
                if (column_exists($conn, 'sales', 'buyername')) {
                    $buyer_column = 'buyername';
                } elseif (column_exists($conn, 'sales', 'buyer_name')) {
                    $buyer_column = 'buyer_name';
                } elseif (column_exists($conn, 'sales', 'customer_name')) {
                    $buyer_column = 'customer_name';
                }
                
                if (!empty($buyer_column) && !empty($buyername)) {
                    $columns[] = $buyer_column;
                    $values[] = "'$buyername'";
                }
                
                if (column_exists($conn, 'sales', 'sale_date') && !empty($sale_date)) {
                    $columns[] = 'sale_date';
                    $values[] = "'$sale_date'";
                }

                $sql = "INSERT INTO sales (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
                
                if (mysqli_query($conn, $sql)) {
                    // Only update stock if it's not a custom product (which has 0 stock)
                    if (empty($custom_product_name)) {
                        $new_stock = $product_row['stock_quantity'] - $quantity;
                        mysqli_query($conn, "UPDATE products SET stock_quantity='$new_stock' WHERE id='$product_id'");
                        $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '-$quantity', '{$product_row['stock_quantity']}', '$new_stock', 'Sale recorded by user')";
                        mysqli_query($conn, $log_sql);
                    }
                    $message = 'Sale added successfully.' . (empty($custom_product_name) ? ' Stock updated.' : ' Custom product created.');
                } else {
                    $message = 'Error adding sale: ' . mysqli_error($conn);
                }
            }
        } else {
            // Process sale without product (manual entry)
            $total_column = column_exists($conn, 'sales', 'total_amount') ? 'total_amount' : (column_exists($conn, 'sales', 'total_price') ? 'total_price' : 'total_amount');

            $columns = ['user_id','quantity',$total_column];
            if (($total_price ?? 0) <= 0 && $unit_price_post !== null && $unit_price_post >= 0) {
                $computed_total = round($unit_price_post * $quantity, 2);
                $values = ["'$user_id'", "'$quantity'", "'$computed_total'"]; 
            } else {
                $values = ["'$user_id'", "'$quantity'", "'$total_price'"]; 
            }

            // Handle buyer name column
            $buyer_column = '';
            if (column_exists($conn, 'sales', 'buyername')) {
                $buyer_column = 'buyername';
            } elseif (column_exists($conn, 'sales', 'buyer_name')) {
                $buyer_column = 'buyer_name';
            } elseif (column_exists($conn, 'sales', 'customer_name')) {
                $buyer_column = 'customer_name';
            }
            
            if (!empty($buyer_column) && !empty($buyername)) {
                $columns[] = $buyer_column;
                $values[] = "'$buyername'";
            }
            
            if (column_exists($conn, 'sales', 'sale_date') && !empty($sale_date)) {
                $columns[] = 'sale_date';
                $values[] = "'$sale_date'";
            }

            $sql = "INSERT INTO sales (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
            
            if (mysqli_query($conn, $sql)) {
                $message = 'Manual sale recorded successfully. (No stock adjustment)';
            } else {
                $message = 'Error adding sale: ' . mysqli_error($conn);
            }
        }
    }
}

// Handle delete via GET
if ($_SERVER["REQUEST_METHOD"] === 'GET' && isset($_GET['delete_sale']) && table_exists($conn, 'sales')) {
    $del_id = mysqli_real_escape_string($conn, $_GET['delete_sale']);
    $sale_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sales WHERE id='$del_id' AND user_id='$user_id'"));
    if ($sale_row) {
        $product_id = $sale_row['product_id'];
        $qty = intval($sale_row['quantity']);
        
        // Only rollback stock if it was linked to a product and had stock
        if (!empty($product_id)) {
            $product_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
            if ($product_row && $product_row['stock_quantity'] > 0) {
                $new_stock = $product_row['stock_quantity'] + $qty;
                mysqli_query($conn, "UPDATE products SET stock_quantity='$new_stock' WHERE id='$product_id'");
                $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '$qty', '{$product_row['stock_quantity']}', '$new_stock', 'Sale deleted by user')";
                mysqli_query($conn, $log_sql);
            }
        }
        
        mysqli_query($conn, "DELETE FROM sales WHERE id='$del_id'");
        $message = 'Sale deleted successfully' . (!empty($product_id) ? ' and stock rolled back' : '');
    }
}

// Load list data
$products_select = mysqli_query($conn, "SELECT id, name, stock_quantity, price FROM products ORDER BY name");
$total_sales_user = 0;
$total_revenue_user = 0;
$total_column = column_exists($conn, 'sales', 'total_amount') ? 'total_amount' : (column_exists($conn, 'sales', 'total_price') ? 'total_price' : 'total_amount');

// Determine buyer name column for display
$buyer_column = '';
if (column_exists($conn, 'sales', 'buyername')) {
    $buyer_column = 'buyername';
} elseif (column_exists($conn, 'sales', 'buyer_name')) {
    $buyer_column = 'buyer_name';
} elseif (column_exists($conn, 'sales', 'customer_name')) {
    $buyer_column = 'customer_name';
}

if (table_exists($conn, 'sales')) {
    $total_sales_user = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM sales WHERE user_id='$user_id'"))['count'];
    $revenue_result = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM($total_column) as total FROM sales WHERE user_id='$user_id'"));
    $total_revenue_user = $revenue_result['total'] ?? 0;
    
    // Build the query with the correct buyer column
    $select_fields = "s.*, p.name as product_name";
    if (!empty($buyer_column)) {
        $select_fields .= ", s.$buyer_column as buyer_name";
    }
    
    $all_sales = mysqli_query($conn, "SELECT $select_fields FROM sales s LEFT JOIN products p ON s.product_id = p.id WHERE s.user_id = '$user_id' ORDER BY s.created_at DESC");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>User Sales - Inventory</title>
    <meta charset="utf-8" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
        }
        
        body {
            background: #f8f9fa;
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 250px;
            background: white;
            padding: 20px;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 20px 0;
            border-bottom: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }
        
        .sidebar-header h2 {
            color: #333;
            font-size: 1.5em;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 10px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #555;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: #4853c8;
            color: white;
        }
        
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 30px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #333;
            font-size: 2em;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #333;
        }
        
        .table-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .product-table th {
            background: #4853c8;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .product-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .btn {
            padding: 8px 15px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-danger {
            background: #e74c3c;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 15px 20px;
            background: #4853c8;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            background: #3a45b5;
            transform: translateY(-2px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 900px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.3s;
            position: relative;
            max-height: 95vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .modal-header h3 {
            color: #333;
            font-size: 1.5em;
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
            padding: 5px;
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: #e74c3c;
        }

        /* Enhanced Form Styles */
        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .form-section h4 {
            margin: 0 0 1rem 0;
            color: #1e293b;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-field {
            display: flex;
            flex-direction: column;
        }

        .form-field.full-width {
            grid-column: 1 / -1;
        }

        .form-field label {
            margin-bottom: 0.5rem;
            color: #374151;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .modern-input, .modern-select, .modern-textarea {
            padding: 0.75rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: white;
        }

        .modern-input:focus, .modern-select:focus, .modern-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: white;
        }

        .field-info {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: #6b7280;
        }

        .stock-info {
            color: #059669;
            font-weight: 500;
        }

        .stock-warning {
            color: #dc2626;
            font-weight: 500;
        }

        /* Stock Info Card */
        .stock-info-card {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-top: 1rem;
        }

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
        }

        .stock-item:not(:last-child) {
            border-bottom: 1px solid #f1f5f9;
        }

        .stock-label {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .stock-value {
            color: #1e293b;
            font-weight: 600;
        }

        /* Summary Section */
        .form-section.highlight {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-color: #bae6fd;
        }

        .summary-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .summary-label {
            color: #475569;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .summary-value {
            color: #1e293b;
            font-weight: 600;
        }

        .summary-value.highlight {
            color: #059669;
            font-size: 1.1rem;
        }

        /* Toggle Switch */
        .toggle-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .toggle-label {
            font-weight: 600;
            color: #374151;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #10b981;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(30px);
        }

        /* Custom Product Fields */
        .custom-product-fields {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
            display: none;
        }

        .custom-product-fields.active {
            display: block;
            animation: slideUp 0.3s ease;
        }

        .custom-product-fields h5 {
            color: #856404;
            margin-bottom: 1rem;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Product Selection Styles */
        .product-selection {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .product-selection select {
            flex: 1;
        }

        .or-divider {
            color: #6b7280;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .custom-product-btn {
            padding: 0.75rem 1.5rem;
            background: #f59e0b;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .custom-product-btn:hover {
            background: #d97706;
            transform: translateY(-1px);
        }

        .custom-product-btn.active {
            background: #dc2626;
        }

        /* Modern Buttons */
        .modern-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .btn-primary {
            padding: 0.75rem 2rem;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-cancel {
            padding: 0.75rem 2rem;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-cancel:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .modern-actions {
                flex-direction: column;
            }
            
            .btn-primary, .btn-cancel {
                width: 100%;
                justify-content: center;
            }
            
            .product-selection {
                flex-direction: column;
                align-items: stretch;
            }
            
            .or-divider {
                text-align: center;
                margin: 0.5rem 0;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(-50px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Badge Styles */
        .sale-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-product {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-custom {
            background: #fff3cd;
            color: #856404;
        }

        .badge-manual {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🏠 User Panel</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="users_dashboard.php">📊 Dashboard</a></li>
            <li><a href="users_products.php">📦 Products</a></li>
            <li><a href="users_sales.php" class="active">💰 Sales</a></li>
            <li><a href="users_supplies.php">📥 Supplies</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header">
            <div>
                <h1>Sales Management</h1>
                <p>Welcome, <strong><?= htmlspecialchars($username) ?></strong>!</p>
            </div>
            <div>
                <a href="logout.php" class="btn btn-danger">Logout</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div style="padding: 15px; margin: 15px 0; border-radius: 8px; text-align: center; font-weight: 500; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="stats-container">
            <div class="stat-card">
                <h3>Total Sales</h3>
                <div class="stat-number"><?= $total_sales_user ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Revenue</h3>
                <div class="stat-number">₱<?= number_format($total_revenue_user, 2) ?></div>
            </div>
        </div>

        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Sales Records</h3>
                <button class="action-btn" onclick="showAddSaleModal()">
                    <span>💰</span>
                    Add New Sale
                </button>
            </div>
            
            <?php if (is_object($all_sales) && mysqli_num_rows($all_sales) > 0): ?>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Price</th>
                            <th>Buyer Name</th>
                            <th>Sale Date</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($sale = mysqli_fetch_assoc($all_sales)): ?>
                        <tr>
                            <td>#<?= $sale['id'] ?></td>
                            <td><?= htmlspecialchars($sale['product_name'] ?? 'Manual Entry') ?></td>
                            <td><?= $sale['quantity'] ?></td>
                            <?php $sale_unit_price = ($sale['quantity'] > 0) ? ($sale[$total_column] ?? ($sale['total_amount'] ?? ($sale['total_price'] ?? 0))) / $sale['quantity'] : 0; ?>
                            <td>₱<?= number_format($sale_unit_price, 2) ?></td>
                            <?php 
                                $sale_total_value = $sale[$total_column] ?? ($sale['total_amount'] ?? ($sale['total_price'] ?? 0));
                            ?>
                            <td><strong>₱<?= number_format($sale_total_value, 2) ?></strong></td>
                            <td>
                                <?php 
                                // Display buyer name from the correct column
                                $buyer_display = 'Not specified';
                                if (!empty($sale['buyer_name'])) {
                                    $buyer_display = htmlspecialchars($sale['buyer_name']);
                                } elseif (!empty($sale['buyername'])) {
                                    $buyer_display = htmlspecialchars($sale['buyername']);
                                } elseif (!empty($sale['customer_name'])) {
                                    $buyer_display = htmlspecialchars($sale['customer_name']);
                                } elseif (!empty($sale['buyername'])) {
                                    $buyer_display = htmlspecialchars($sale['buyername']);
                                }
                                echo $buyer_display;
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($sale['sale_date'])): ?>
                                    <?= date('M j, Y g:i A', strtotime($sale['sale_date'])) ?>
                                <?php else: ?>
                                    <?= date('M j, Y g:i A', strtotime($sale['created_at'])) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($sale['product_id'])): ?>
                                    <span class="sale-type-badge badge-manual">Manual</span>
                                <?php else: ?>
                                    <span class="sale-type-badge badge-product">Product</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-danger" onclick="deleteSale(<?= $sale['id'] ?>, '<?= htmlspecialchars($sale['product_name'] ?? 'Manual Sale') ?>')">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: #64748b;">
                    <h3 style="color: #475569; margin-bottom: 1rem;">No Sales Recorded</h3>
                    <p style="margin-bottom: 1.5rem;">Start by adding your first sale.</p>
                    <button class="action-btn" onclick="showAddSaleModal()">
                        <span>💰</span>
                        Add First Sale
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Add Sale Modal -->
    <div id="addSaleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>💳 Record New Sale</h3>
                <button class="close-btn" onclick="hideAddSaleModal()">&times;</button>
            </div>
            
            <div class="form-container">
                <form action="" method="POST" id="addSaleForm">
                    <!-- Product Selection Section -->
                    <div class="form-section">
                        <h4>📦 Product Selection</h4>
                        
                        <div class="product-selection">
                            
                         // REPLACE the existing product dropdown with:
<select name="product_id" class="modern-select" id="productSelect" onchange="updateProductInfo()">
    <option value="">Select from existing products...</option>
    <?php mysqli_data_seek($products_select,0); while($p = mysqli_fetch_assoc($products_select)): ?>
        <?php
        $stock_status = '';
        $disabled = '';
        if ($p['stock_quantity'] == 0) {
            $stock_status = '❌ Out of Stock';
            $disabled = 'disabled';
        } elseif ($p['stock_quantity'] < 10) {
            $stock_status = '⚠️ Low Stock';
        } else {
            $stock_status = '✅ In Stock';
        }
        ?>
        <option value="<?= $p['id'] ?>" 
                data-price="<?= $p['price'] ?>" 
                data-stock="<?= $p['stock_quantity'] ?>"
                <?= $disabled ?>>
            <?= htmlspecialchars($p['name']) ?> | <?= $stock_status ?> | Stock: <?= $p['stock_quantity'] ?> | ₱<?= number_format($p['price'], 2) ?>
        </option>
    <?php endwhile; ?>
</select>
                            
                            <span class="or-divider">OR</span>
                            
                            <button type="button" class="custom-product-btn" id="customProductBtn" onclick="toggleCustomProduct()">
                                <span>✨</span>
                                Add Custom Product
                            </button>
                        </div>
                        
                        <!-- Custom Product Fields -->
                        <div class="custom-product-fields" id="customProductFields">
                            <h5>🆕 New Product Details</h5>
                            <div class="form-grid">
                                <div class="form-field">
                                    <label>Product Name *</label>
                                    <input type="text" name="custom_product_name" class="modern-input" 
                                           placeholder="Enter product name" id="customProductName">
                                </div>
                                <div class="form-field">
                                    <label>Category</label>
                                    <input type="text" name="custom_product_category" class="modern-input" 
                                           placeholder="Enter category (optional)">
                                </div>
                            </div>
                            <div class="form-field full-width">
                                <label>Description</label>
                                <textarea name="custom_product_description" class="modern-textarea" rows="2" 
                                          placeholder="Product description (optional)"></textarea>
                            </div>
                            <div class="field-info">
                                <span>💡 This will create a new product in the system with 0 stock.</span>
                            </div>
                        </div>
                        
                        <!-- Product Information Card -->
                        <div class="stock-info-card" id="productInfoCard">
                            <div class="stock-item">
                                <span class="stock-label">Available Stock:</span>
                                <span class="stock-value" id="stockDisplay">-</span>
                            </div>
                            <div class="stock-item">
                                <span class="stock-label">Unit Price:</span>
                                <span class="stock-value" id="unitPriceInfo">-</span>
                            </div>
                            <div class="stock-item">
                                <span class="stock-label">Stock Status:</span>
                                <span class="stock-value" id="stockStatus">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sale Details Section -->
                    <div class="form-section">
                        <h4>💰 Sale Details</h4>
                        <div class="form-grid">
                            <div class="form-field">
                                <label>👤 Customer/Buyer Name</label>
                                <input type="text" name="buyername" class="modern-input" 
                                       placeholder="Enter customer/buyer name (optional)">
                                <div class="field-info">
                                    <span>This name will be displayed in the sales list</span>
                                </div>
                            </div>
                            
                            <div class="form-field">
                                <label>🔢 Quantity *</label>
                                <input type="number" name="quantity" required min="1" 
                                       placeholder="Enter quantity" oninput="calculateTotalPrice()" 
                                       class="modern-input" id="quantityInput">
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-field">
                                <label>💵 Unit Price (₱)</label>
                                <input type="number" name="unit_price" step="0.01" min="0" 
                                       placeholder="0.00" class="modern-input" id="unitPriceInput" 
                                       oninput="calculateTotalPrice()">
                                <div class="field-info">
                                    <span id="priceHelpText">Edit unit price here (defaults to product price)</span>
                                </div>
                            </div>
                            
                            <div class="form-field">
                                <label>💰 Total Price (₱) *</label>
                                <input type="number" name="total_price" required step="0.01" min="0.01" 
                                       placeholder="0.00" class="modern-input" id="totalPriceInput"
                                       oninput="updateSummary()">
                                <div class="field-info">
                                    <span>Calculated automatically based on quantity and unit price</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-field">
                                <label>📅 Sale Date *</label>
                                <input type="datetime-local" name="sale_date" value="<?= date('Y-m-d\TH:i') ?>" 
                                       class="modern-input">
                            </div>
                            <div class="form-field">
                                <label>🏷️ Sale Type</label>
                                <div class="price-display">
                                    <div class="price-label">Current Selection</div>
                                    <div class="price-value" id="saleTypeDisplay">Existing Product</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Section -->
                    <div class="form-section highlight">
                        <h4>📊 Sale Summary</h4>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <span class="summary-label">Sale Type:</span>
                                <span class="summary-value" id="summaryType">Existing Product</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Product:</span>
                                <span class="summary-value" id="summaryProduct">-</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Quantity:</span>
                                <span class="summary-value" id="summaryQuantity">0</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Unit Price:</span>
                                <span class="summary-value" id="summaryUnit">₱0.00</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Total Amount:</span>
                                <span class="summary-value highlight" id="summaryTotal">₱0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions modern-actions">
                        <button type="button" class="btn-cancel" onclick="hideAddSaleModal()">
                            <span>❌</span> Cancel
                        </button>
                        <button type="submit" name="add_sale" class="btn-primary" id="submitBtn">
                            <span>💾</span> Record Sale
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
// Modal Functions
function showAddSaleModal() {
    document.getElementById('addSaleModal').style.display = 'block';
    resetForm();
}

function hideAddSaleModal() {
    document.getElementById('addSaleModal').style.display = 'none';
}

function resetForm() {
    document.getElementById('productSelect').value = '';
    document.getElementById('quantityInput').value = '';
    document.getElementById('totalPriceInput').value = '';
    document.getElementById('unitPriceInput').value = '';
    document.querySelector('input[name="buyername"]').value = '';
    document.getElementById('customProductName').value = '';
    document.querySelector('input[name="custom_product_category"]').value = '';
    document.querySelector('textarea[name="custom_product_description"]').value = '';
    
    // Reset custom product fields
    const customBtn = document.getElementById('customProductBtn');
    const customFields = document.getElementById('customProductFields');
    customBtn.classList.remove('active');
    customFields.classList.remove('active');
    customBtn.innerHTML = '<span>✨</span> Add Custom Product';
    
    updateProductInfo();
    updateSummary();
}

// Toggle custom product fields
function toggleCustomProduct() {
    const customBtn = document.getElementById('customProductBtn');
    const customFields = document.getElementById('customProductFields');
    const productSelect = document.getElementById('productSelect');
    
    if (customBtn.classList.contains('active')) {
        // Switch to existing product mode
        customBtn.classList.remove('active');
        customFields.classList.remove('active');
        customBtn.innerHTML = '<span>✨</span> Add Custom Product';
        productSelect.disabled = false;
        document.getElementById('saleTypeDisplay').textContent = 'Existing Product';
        document.getElementById('summaryType').textContent = 'Existing Product';
    } else {
        // Switch to custom product mode
        customBtn.classList.add('active');
        customFields.classList.add('active');
        customBtn.innerHTML = '<span>📦</span> Use Existing Product';
        productSelect.disabled = true;
        productSelect.value = '';
        document.getElementById('saleTypeDisplay').textContent = 'Custom Product';
        document.getElementById('summaryType').textContent = 'Custom Product';
        updateProductInfo();
    }
    updateSummary();
}

// Update product information when product selection changes
function updateProductInfo() {
    const productSelect = document.getElementById('productSelect');
    const stockDisplay = document.getElementById('stockDisplay');
    const unitPriceInfo = document.getElementById('unitPriceInfo');
    const stockStatus = document.getElementById('stockStatus');
    const summaryProduct = document.getElementById('summaryProduct');
    const customBtn = document.getElementById('customProductBtn');
    const unitPriceInput = document.getElementById('unitPriceInput');
    
    const isCustomMode = customBtn.classList.contains('active');
    
    if (isCustomMode) {
        // Custom product mode
        const customName = document.getElementById('customProductName').value;
        stockDisplay.textContent = 'N/A (New Product)';
        unitPriceInfo.textContent = 'Set below →';
        stockStatus.textContent = '🆕 Custom Product';
        stockStatus.className = 'stock-value stock-info';
        summaryProduct.textContent = customName || 'New Product';
        
        // Enable unit price input for custom products
        if (unitPriceInput) unitPriceInput.disabled = false;
        
    } else if (productSelect.value) {
        // Existing product mode
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const productName = selectedOption.text.split(' | ')[0];
        const price = parseFloat(selectedOption.getAttribute('data-price'));
        const stock = parseInt(selectedOption.getAttribute('data-stock'));
        
        // Update displays
        stockDisplay.textContent = stock + ' units';
        unitPriceInfo.textContent = '₱' + price.toFixed(2);
        summaryProduct.textContent = productName;
        
        // Update stock status
        if (stock > 10) {
            stockStatus.textContent = '✅ In Stock';
            stockStatus.className = 'stock-value stock-info';
        } else if (stock > 0) {
            stockStatus.textContent = '⚠️ Low Stock';
            stockStatus.className = 'stock-value stock-warning';
        } else {
            stockStatus.textContent = '❌ Out of Stock';
            stockStatus.className = 'stock-value stock-warning';
        }
        
        // Set unit price input value to default price
        if (unitPriceInput) {
            unitPriceInput.value = price.toFixed(2);
        }

        // Auto-calculate total if quantity is entered
        calculateTotalPrice();
    } else {
        // No product selected
        stockDisplay.textContent = '-';
        unitPriceInfo.textContent = '-';
        stockStatus.textContent = '-';
        summaryProduct.textContent = '-';
        
        if (unitPriceInput) unitPriceInput.value = '';
    }
    updateSummary();
}

// Calculate total price
function calculateTotalPrice() {
    const productSelect = document.getElementById('productSelect');
    const quantityInput = document.getElementById('quantityInput');
    const totalPriceInput = document.getElementById('totalPriceInput');
    const unitPriceInput = document.getElementById('unitPriceInput');
    const customBtn = document.getElementById('customProductBtn');
    
    const isCustomMode = customBtn.classList.contains('active');
    const quantity = parseInt(quantityInput.value) || 0;
    const unitPrice = parseFloat(unitPriceInput.value) || 0;
    
    if (quantity > 0 && unitPrice > 0) {
        const totalPrice = unitPrice * quantity;
        totalPriceInput.value = totalPrice.toFixed(2);
    }
    
    // Validate stock for existing products
    if (!isCustomMode && productSelect.value) {
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const stock = parseInt(selectedOption.getAttribute('data-stock'));
        
        if (quantity > stock) {
            quantityInput.style.borderColor = '#ef4444';
            quantityInput.style.backgroundColor = '#fef2f2';
        } else {
            quantityInput.style.borderColor = '#10b981';
            quantityInput.style.backgroundColor = '#f0fdf4';
        }
    } else {
        quantityInput.style.borderColor = '#e5e7eb';
        quantityInput.style.backgroundColor = 'white';
    }
    
    updateSummary();
}

// Update the summary section
function updateSummary() {
    const productSelect = document.getElementById('productSelect');
    const quantityInput = document.getElementById('quantityInput');
    const totalPriceInput = document.getElementById('totalPriceInput');
    const unitPriceInput = document.getElementById('unitPriceInput');
    const customBtn = document.getElementById('customProductBtn');
    const customName = document.getElementById('customProductName');
    
    const isCustomMode = customBtn.classList.contains('active');
    
    // Update product name
    if (isCustomMode) {
        document.getElementById('summaryProduct').textContent = customName.value || 'New Product';
        document.getElementById('summaryType').textContent = 'Custom Product';
    } else if (productSelect.value) {
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const productName = selectedOption.text.split(' | ')[0];
        document.getElementById('summaryProduct').textContent = productName;
        document.getElementById('summaryType').textContent = 'Existing Product';
    } else {
        document.getElementById('summaryProduct').textContent = '-';
        document.getElementById('summaryType').textContent = 'Existing Product';
    }
    
    // Update quantities and prices
    document.getElementById('summaryQuantity').textContent = quantityInput.value || '0';
    document.getElementById('summaryTotal').textContent = totalPriceInput.value ? '₱' + parseFloat(totalPriceInput.value).toFixed(2) : '₱0.00';
    document.getElementById('summaryUnit').textContent = unitPriceInput.value ? '₱' + parseFloat(unitPriceInput.value).toFixed(2) : '₱0.00';
}

// Delete confirmation
function deleteSale(id, productName) {
    if (confirm('Are you sure you want to delete the sale record for "' + productName + '"?')) {
        window.location.href = 'users_sales.php?delete_sale=' + id;
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('addSaleModal');
    if (event.target === modal) {
        hideAddSaleModal();
    }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners for real-time updates
    const productSelect = document.getElementById('productSelect');
    const quantityInput = document.getElementById('quantityInput');
    const totalPriceInput = document.getElementById('totalPriceInput');
    const unitPriceInput = document.getElementById('unitPriceInput');
    const customName = document.getElementById('customProductName');
    
    if (productSelect) {
        productSelect.addEventListener('change', updateProductInfo);
    }
    if (quantityInput) {
        quantityInput.addEventListener('input', calculateTotalPrice);
    }
    if (totalPriceInput) {
        totalPriceInput.addEventListener('input', updateSummary);
    }
    if (unitPriceInput) {
        unitPriceInput.addEventListener('input', calculateTotalPrice);
    }
    if (customName) {
        customName.addEventListener('input', updateSummary);
    }
});
</script>
</body>
</html>