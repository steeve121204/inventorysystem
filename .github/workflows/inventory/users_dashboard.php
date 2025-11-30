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
$active_tab = $_GET['tab'] ?? 'dashboard';

// Check if dark mode is set, otherwise default to light mode
$dark_mode = isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] === 'true' : false;

// Helper functions
function table_exists($conn, $table) {
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}

function column_exists($conn, $table, $column) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

// Get products for user (products they can view)
$user_products = mysqli_query($conn, "
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN category c ON p.category_id = c.id 
    ORDER BY p.created_at DESC
");

// Get categories
$categories = mysqli_query($conn, "SELECT * FROM category ORDER BY name");

// Handle POST: add sale (updated without quantity units)
if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['add_sale'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id'] ?? '');
    $quantity = floatval($_POST['quantity'] ?? 0);
    $buyername = mysqli_real_escape_string($conn, $_POST['buyername'] ?? '');
    $total_price = floatval($_POST['total_price'] ?? 0);
    $unit_price = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : null;
    $sale_date_input = $_POST['sale_date'] ?? '';
    
    // Format sale date properly
    $sale_date = '';
    if (!empty($sale_date_input)) {
        $sale_date = date('Y-m-d H:i:s', strtotime($sale_date_input));
    } else {    
        $sale_date = date('Y-m-d H:i:s');
    }
    
    // Validate inputs
    if ($quantity <= 0) {
        $message = 'Quantity must be greater than zero.';
    } else if ($total_price <= 0) {
        $message = 'Total price must be greater than zero.';
    } else if (empty($product_id)) {
        $message = 'Please select a product.';
    } else {
        $product_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
        if (!$product_row) {
            $message = 'Product not found.';
        } else if ($product_row['stock_quantity'] < $quantity) {
            $message = 'Insufficient stock for this sale. Available: ' . $product_row['stock_quantity'];
        } else {
            // Process sale with product
            $unit_price = ($unit_price !== null && $unit_price >= 0) ? $unit_price : (float)$product_row['price'];
            $computed_total = round($unit_price * $quantity, 2);
            
            // Check if product_id column exists in sales table
            $has_product_id = column_exists($conn, 'sales', 'product_id');
            
            $columns = ['user_id','quantity'];
            $values = ["'$user_id'", "'$quantity'"];
            
            // Add product_id if column exists
            if ($has_product_id) {
                $columns[] = 'product_id';
                $values[] = "'$product_id'";
            }
            
            // Determine total column name
            $total_column = 'total_amount';
            if (column_exists($conn, 'sales', 'total_price')) {
                $total_column = 'total_price';
            } elseif (column_exists($conn, 'sales', 'total_amount')) {
                $total_column = 'total_amount';
            }
            
            $columns[] = $total_column;
            $values[] = "'$computed_total'";

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
                // Update stock
                $new_stock = $product_row['stock_quantity'] - $quantity;
                mysqli_query($conn, "UPDATE products SET stock_quantity='$new_stock' WHERE id='$product_id'");
                $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '-$quantity', '{$product_row['stock_quantity']}', '$new_stock', 'Sale recorded by user')";
                mysqli_query($conn, $log_sql);
                
                $message = 'Sale added successfully. Stock updated.';
                // Refresh to show updated data
                header("Location: users_dashboard.php?tab=sales&message=" . urlencode($message));
                exit;
            } else {
                $message = 'Error recording sale: ' . mysqli_error($conn);
                error_log("Sales Insert Error: " . mysqli_error($conn) . " - SQL: " . $sql);
            }
        }
    }
}

// Show message from URL parameter
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
}

// Delete sale (user request)
if (isset($_GET['delete_sale'])) {
    $sale_id = mysqli_real_escape_string($conn, $_GET['delete_sale']);
    
    // Verify sale belongs to user before deleting
    $sale_check = mysqli_query($conn, "SELECT * FROM sales WHERE id='$sale_id' AND user_id='$user_id'");
    if (mysqli_num_rows($sale_check) > 0) {
        $delete_sql = "DELETE FROM sales WHERE id='$sale_id' AND user_id='$user_id'";
        if (mysqli_query($conn, $delete_sql)) {
            $message = 'Sale deleted successfully!';
            $active_tab = 'sales';
        } else {
            $message = 'Error deleting sale: ' . mysqli_error($conn);
        }
    } else {
        $message = 'Sale not found or access denied.';
    }
}

// Get dashboard statistics
$total_sales_user = 0;
$total_revenue_user = 0;
$total_products = mysqli_num_rows($user_products);

// Check if sales table and product_id column exist
$has_sales_table = table_exists($conn, 'sales');
$has_product_id_column = $has_sales_table ? column_exists($conn, 'sales', 'product_id') : false;

if ($has_sales_table) {
    // Determine total column name
    $total_column = 'total_amount';
    if (column_exists($conn, 'sales', 'total_price')) {
        $total_column = 'total_price';
    } elseif (column_exists($conn, 'sales', 'total_amount')) {
        $total_column = 'total_amount';
    }
    
    // Total sales count (only user's sales)
    $total_sales_result = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT COUNT(*) as count FROM sales WHERE user_id='$user_id'"));
    $total_sales_user = $total_sales_result['count'] ?? 0;
    
    // Total revenue (only user's sales)
    $total_revenue_result = mysqli_fetch_assoc(mysqli_query($conn, 
        "SELECT SUM($total_column) as total FROM sales WHERE user_id='$user_id'"));
    $total_revenue_user = $total_revenue_result['total'] ?? 0;
    
    // Recent sales for dashboard (only user's sales)
    if ($has_product_id_column) {
        $recent_sales = mysqli_query($conn, "
            SELECT s.*, p.name as product_name 
            FROM sales s 
            LEFT JOIN products p ON s.product_id = p.id 
            WHERE s.user_id = '$user_id' 
            ORDER BY s.created_at DESC 
            LIMIT 10
        ");
        
        // All sales for sales tab (only user's sales)
        $all_sales = mysqli_query($conn, "
            SELECT s.*, p.name as product_name 
            FROM sales s 
            LEFT JOIN products p ON s.product_id = p.id 
            WHERE s.user_id = '$user_id'
            ORDER BY s.created_at DESC
        ");
    } else {
        // Fallback if product_id column doesn't exist
        $recent_sales = mysqli_query($conn, "
            SELECT s.* 
            FROM sales s 
            WHERE s.user_id = '$user_id' 
            ORDER BY s.created_at DESC 
            LIMIT 10
        ");
        
        $all_sales = mysqli_query($conn, "
            SELECT s.* 
            FROM sales s 
            WHERE s.user_id = '$user_id'
            ORDER BY s.created_at DESC
        ");
    }
} else {
    $recent_sales = false;
    $all_sales = false;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $dark_mode ? 'dark' : 'light' ?>">
<head>
    <title>User Dashboard - Inventory System</title>
    <style>
        /* CSS Variables for Theme Switching */
        :root {
            /* Light Theme Colors */
            --bg-primary: #f8f9fa;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f8fafc;
            --text-primary: #333333;
            --text-secondary: #666666;
            --text-muted: #999999;
            --border-color: #e0e0e0;
            --shadow-color: rgba(0,0,0,0.1);
            --sidebar-bg: #ffffff;
            --header-bg: #ffffff;
            --card-bg: #ffffff;
            --input-bg: #ffffff;
            --modal-bg: #ffffff;
            --toast-bg: #ffffff;
            --success-bg: #d4edda;
            --success-text: #155724;
            --success-border: #c3e6cb;
            --error-bg: #f8d7da;
            --error-text: #721c24;
            --error-border: #f5c6cb;
            --warning-bg: #fff4e6;
            --warning-text: #856404;
            --warning-border: #ffeaa7;
        }

        [data-theme="dark"] {
            /* Dark Theme Colors */
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-tertiary: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: #475569;
            --shadow-color: rgba(0,0,0,0.3);
            --sidebar-bg: #1e293b;
            --header-bg: #1e293b;
            --card-bg: #1e293b;
            --input-bg: #334155;
            --modal-bg: #1e293b;
            --toast-bg: #1e293b;
            --success-bg: #065f46;
            --success-text: #d1fae5;
            --success-border: #047857;
            --error-bg: #7f1d1d;
            --error-text: #fecaca;
            --error-border: #dc2626;
            --warning-bg: #78350f;
            --warning-text: #fef3c7;
            --warning-border: #d97706;
        }

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
        
        body {
            background: var(--bg-primary);
            display: flex;
            min-height: 100vh;
            color: var(--text-primary);
        }
        
        .sidebar {
            width: 250px;
            background: var(--sidebar-bg);
            padding: 20px;
            box-shadow: 2px 0 10px var(--shadow-color);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            border-right: 1px solid var(--border-color);
        }
        
        .sidebar-header {
            padding: 20px 0;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sidebar-header h2 {
            color: var(--text-primary);
            font-size: 1.5em;
        }
        
        .theme-toggle {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            background: var(--bg-tertiary);
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
            color: var(--text-secondary);
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
            background: var(--bg-primary);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: var(--header-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px var(--shadow-color);
            border: 1px solid var(--border-color);
        }
        
        .header h1 {
            color: var(--text-primary);
            font-size: 2em;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Dashboard Styles */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px var(--shadow-color);
            text-align: center;
            transition: transform 0.3s;
            border: 1px solid var(--border-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: var(--text-primary);
        }
        
        .stat-card.total-sales { border-top: 4px solid #3498db; }
        .stat-card.products { border-top: 4px solid #f39c12; }
        .stat-card.total-revenue { border-top: 4px solid #27ae60; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px var(--shadow-color);
            border: 1px solid var(--border-color);
        }
        
        .card h3 {
            margin-bottom: 15px;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sale-item, .product-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sale-item:last-child, .product-item:last-child {
            border-bottom: none;
        }
        
        .sale-info, .product-info {
            flex: 1;
        }
        
        .sale-product, .product-name {
            font-weight: bold;
            color: var(--text-primary);
        }
        
        .sale-details, .product-details {
            color: var(--text-secondary);
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .sale-amount {
            font-weight: bold;
            color: #27ae60;
            font-size: 1.1em;
        }
        
        /* Products Table Styles */
        .table-container {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px var(--shadow-color);
            margin-bottom: 20px;
            overflow-x: auto;
            border: 1px solid var(--border-color);
        }
        
        .product-table, .sales-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .product-table th, .sales-table th {
            background: #4853c8;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .product-table td, .sales-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .product-table tr:hover, .sales-table tr:hover {
            background: var(--bg-tertiary);
        }
        
        .stock-warning {
            color: #e74c3c;
            font-weight: bold;
            background: #ffeaea;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .stock-low {
            color: #f39c12;
            font-weight: bold;
            background: #fff4e6;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .stock-good {
            color: #27ae60;
            font-weight: bold;
            background: #e8f6ef;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
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
        
        .btn-warning {
            background: #f39c12;
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
            box-shadow: 0 4px 15px rgba(72, 83, 200, 0.3);
        }
        
        .action-btn:hover {
            background: #3a45b5;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 83, 200, 0.4);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons .btn {
            padding: 6px 12px;
            font-size: 11px;
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
            background-color: var(--modal-bg);
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 10px 30px var(--shadow-color);
            animation: slideIn 0.3s;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .modal-header h3 {
            color: var(--text-primary);
            font-size: 1.5em;
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 5px;
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: #e74c3c;
        }

        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4853c8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(72, 83, 200, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid var(--border-color);
        }

        .form-actions .btn {
            padding: 12px 25px;
            font-size: 14px;
            min-width: 120px;
        }

        .category-input-container {
            position: relative;
        }

        .category-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--input-bg);
            border: 2px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 150px;
            overflow-y: auto;
            z-index: 100;
            display: none;
            box-shadow: 0 4px 15px var(--shadow-color);
        }

        .category-suggestion {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s;
            color: var(--text-primary);
        }

        .category-suggestion:hover {
            background: var(--bg-tertiary);
        }

        .category-suggestion:last-child {
            border-bottom: none;
        }

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
        
        .message {
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
            font-size: 14px;
        }
        
        .message.error {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid var(--error-border);
        }

        .date-info {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }

        .product-info {
            background: var(--bg-tertiary);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #4853c8;
        }

        .product-info p {
            margin: 5px 0;
            color: var(--text-secondary);
        }

        .product-info strong {
            color: var(--text-primary);
        }

        /* Toast Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .toast {
            background: var(--toast-bg);
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px var(--shadow-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-width: 300px;
            border-left: 4px solid #e74c3c;
            animation: slideInRight 0.3s ease-out;
            border: 1px solid var(--border-color);
        }

        .toast.success {
            border-left-color: #27ae60;
        }

        .toast.warning {
            border-left-color: #f39c12;
        }

        .toast.info {
            border-left-color: #3498db;
        }

        .toast-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toast-icon {
            font-size: 1.2em;
        }

        .toast-message {
            color: var(--text-primary);
            font-size: 14px;
        }

        .toast-actions {
            display: flex;
            gap: 8px;
            margin-left: 15px;
        }

        .toast-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .toast-btn.confirm {
            background: #e74c3c;
            color: white;
        }

        .toast-btn.confirm:hover {
            background: #c0392b;
        }

        .toast-btn.cancel {
            background: #6b7280;
            color: white;
        }

        .toast-btn.cancel:hover {
            background: #4b5563;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast.hide {
            animation: slideOutRight 0.3s ease-in forwards;
        }

        .new-product-highlight {
            background: #e8f5e8 !important;
            animation: highlight 2s ease-out;
        }

        .update-highlight {
            background: #fff9e6 !important;
            animation: updateHighlight 2s ease-out;
        }

        @keyframes highlight {
            0% {
                background: #e8f5e8;
            }
            100% {
                background: transparent;
            }
        }

        @keyframes updateHighlight {
            0% {
                background: #fff9e6;
            }
            100% {
                background: transparent;
            }
        }

        .product-name, .product-category, .product-price, .product-stock, .product-description {
            transition: all 0.3s ease;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }

        .no-data h4 {
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        /* Enhanced Form Styles for Sales */
        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--bg-tertiary);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .form-section h4 {
            margin: 0 0 1rem 0;
            color: var(--text-primary);
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

        .modern-input, .modern-select, .modern-textarea {
            padding: 0.75rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .modern-input:focus, .modern-select:focus, .modern-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
            background: var(--card-bg);
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            margin-top: 1rem;
        }

        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
        }

        .stock-item:not(:last-child) {
            border-bottom: 1px solid var(--border-color);
        }

        .stock-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .stock-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Summary Section */
        .form-section.highlight {
            background: linear-gradient(135deg, var(--bg-tertiary), var(--bg-secondary));
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
            background: var(--card-bg);
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }

        .summary-label {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .summary-value {
            color: var(--text-primary);
            font-weight: 600;
        }

        .summary-value.highlight {
            color: #059669;
            font-size: 1.1rem;
        }

        /* Modern Buttons */
        .modern-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
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
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row, .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-container {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 20px;
            }
            
            .modern-actions {
                flex-direction: column;
            }
            
            .btn-primary, .btn-cancel {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🏠 User Panel</h2>
            <button class="theme-toggle" id="themeToggle" title="Toggle Dark Mode">
                <?= $dark_mode ? '☀️' : '🌙' ?>
            </button>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?tab=dashboard" class="<?= $active_tab == 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a></li>
            <li><a href="?tab=products" class="<?= $active_tab == 'products' ? 'active' : '' ?>">📦 Products</a></li>
            <li><a href="?tab=sales" class="<?= $active_tab == 'sales' ? 'active' : '' ?>">💰 Sales</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div class="header-content">
                <h1>
                    <?php 
                    switch($active_tab) {
                        case 'products': echo '📦 Products Management'; break;
                        case 'sales': echo '💰 Sales Management'; break;
                        default: echo 'User Dashboard';
                    }
                    ?>
                </h1>
                <div class="welcome-message">
                    Welcome, <strong><?= htmlspecialchars($username) ?></strong>!
                </div>
            </div>
            <div class="user-info">
                <a href="logout.php" class="btn btn-danger" id="logoutBtn">Logout</a>
            </div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container" id="toastContainer"></div>

        <?php if ($message && strpos($message, 'successfully') === false): ?>
            <div class="message error" id="statusMessage">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content <?= $active_tab == 'dashboard' ? 'active' : '' ?>">
            <div class="stats-container">
                <div class="stat-card total-sales">
                    <h3>Total Sales</h3>
                    <div class="stat-number"><?= $total_sales_user ?></div>
                    <small>All Time</small>
                </div>
                
                <div class="stat-card products">
                    <h3>Total Products</h3>
                    <div class="stat-number"><?= $total_products ?></div>
                    <small>In System</small>
                </div>
                
                <div class="stat-card total-revenue">
                    <h3>Total Revenue</h3>
                    <div class="stat-number">₱<?= number_format($total_revenue_user, 2) ?></div>
                    <small>All Time</small>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <h3>💰 Recent Sales</h3>
                    <?php if (isset($recent_sales) && mysqli_num_rows($recent_sales) > 0): ?>
                        <?php while($sale = mysqli_fetch_assoc($recent_sales)): ?>
                            <div class="sale-item">
                                <div class="sale-info">
                                    <div class="sale-product">
                                        <?= htmlspecialchars($sale['product_name'] ?? 'Sale #' . $sale['id']) ?>
                                    </div>
                                    <div class="sale-details">
                                        Buyer: <?= htmlspecialchars($sale['buyer_name'] ?? $sale['buyername'] ?? $sale['customer_name'] ?? 'Not specified') ?> | 
                                        Qty: <?= $sale['quantity'] ?> pcs
                                    </div>
                                    <small class="date-info">
                                        <?= date('M j, Y g:i A', strtotime($sale['sale_date'] ?? $sale['created_at'])) ?>
                                    </small>
                                </div>
                                <div class="sale-amount">
                                    ₱<?= number_format($sale['total_amount'] ?? $sale['total_price'] ?? 0, 2) ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No sales recorded yet.</p>
                            <button type="button" class="btn btn-success" onclick="switchTab('sales'); setTimeout(() => showAddSaleModal(), 100);">
                                Record First Sale
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>📦 Recent Products</h3>
                    <?php if(mysqli_num_rows($user_products) > 0): ?>
                        <?php 
                        mysqli_data_seek($user_products, 0);
                        for($i = 0; $i < 5 && $product = mysqli_fetch_assoc($user_products); $i++):
                        ?>
                            <div class="product-item">
                                <div class="product-info">
                                    <div class="product-name">
                                        <?= htmlspecialchars($product['name']) ?>
                                    </div>
                                    <div class="product-details">
                                        Category: <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?> | 
                                        Price: ₱<?= number_format($product['price'], 2) ?>
                                    </div>
                                    <small class="date-info">
                                        Stock: 
                                        <span class="<?= $product['stock_quantity'] == 0 ? 'stock-warning' : ($product['stock_quantity'] < 10 ? 'stock-low' : 'stock-good') ?>">
                                            <?= $product['stock_quantity'] == 0 ? 'Out of Stock' : ($product['stock_quantity'] < 10 ? 'Low Stock' : $product['stock_quantity']) ?>
                                        </span>
                                        | Added: <?= date('M j, Y', strtotime($product['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endfor; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No products available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Products Management Tab -->
        <div id="products" class="tab-content <?= $active_tab == 'products' ? 'active' : '' ?>">
            <!-- Products List Table -->
            <div class="table-container">
                <h3>Product List (Total: <?= $total_products ?> products)</h3>
                
                <?php if (mysqli_num_rows($user_products) > 0): ?>
                    <table class="product-table" id="productsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Description</th>
                                <th>Date Recorded</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php mysqli_data_seek($user_products, 0); ?>
                            <?php while($product = mysqli_fetch_assoc($user_products)): ?>
                                <tr id="product-<?= $product['id'] ?>">
                                    <td><strong>#<?= $product['id'] ?></strong></td>
                                    <td class="product-name"><?= htmlspecialchars($product['name']) ?></td>
                                    <td class="product-category"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></td>
                                    <td class="product-price">₱<?= number_format($product['price'], 2) ?></td>
                                    <td class="product-stock">
                                        <?php
                                        $stock_class = '';
                                        if ($product['stock_quantity'] == 0) {
                                            $stock_class = 'stock-warning';
                                            $stock_text = 'Out of Stock';
                                        } elseif ($product['stock_quantity'] < 10) {
                                            $stock_class = 'stock-low';
                                            $stock_text = 'Low Stock';
                                        } else {
                                            $stock_class = 'stock-good';
                                            $stock_text = $product['stock_quantity'];
                                        }
                                        ?>
                                        <span class="<?= $stock_class ?> product-stock-value" data-stock="<?= $product['stock_quantity'] ?>">
                                            <?= $stock_text ?>
                                        </span>
                                    </td>
                                    <td class="product-description"><?= htmlspecialchars($product['description']) ?></td>
                                    <td><?= date('M j, Y g:i A', strtotime($product['created_at'])) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No products found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sales Management Tab -->
        <div id="sales" class="tab-content <?= $active_tab == 'sales' ? 'active' : '' ?>">
            <button type="button" class="action-btn" onclick="showAddSaleModal()">
                <span>💰</span>
                Record New Sale
            </button>

            <!-- All Sales Records -->
            <?php if ($has_sales_table): ?>
            <div class="table-container">
                <h3>Sales Records (Total: <?= $total_sales_user ?> sales)</h3>
                
                <?php if (isset($all_sales) && mysqli_num_rows($all_sales) > 0): ?>
                    <table class="sales-table" id="salesTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <?php if ($has_product_id_column): ?>
                                <th>Product</th>
                                <?php endif; ?>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Amount</th>
                                <th>Buyer Name</th>
                                <th>Date & Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="salesTableBody">
                            <?php mysqli_data_seek($all_sales, 0); ?>
                            <?php while($sale = mysqli_fetch_assoc($all_sales)): ?>
                                <tr id="sale-<?= $sale['id'] ?>">
                                    <td><strong>#<?= $sale['id'] ?></strong></td>
                                    <?php if ($has_product_id_column): ?>
                                    <td class="sale-product-name"><?= htmlspecialchars($sale['product_name'] ?? 'Manual Entry') ?></td>
                                    <?php endif; ?>
                                    <td class="sale-quantity"><?= $sale['quantity'] ?> pcs</td>
                                    <?php 
                                        $sale_unit_price = ($sale['quantity'] > 0) ? ($sale[$total_column] ?? ($sale['total_amount'] ?? ($sale['total_price'] ?? 0))) / $sale['quantity'] : 0;
                                    ?>
                                    <td class="sale-unit-price">₱<?= number_format($sale_unit_price, 2) ?></td>
                                    <?php 
                                        $sale_total_value = $sale[$total_column] ?? ($sale['total_amount'] ?? ($sale['total_price'] ?? 0));
                                    ?>
                                    <td class="sale-amount-value"><strong>₱<?= number_format($sale_total_value, 2) ?></strong></td>
                                    <td class="sale-buyer-name"><?= htmlspecialchars($sale['buyer_name'] ?? $sale['buyername'] ?? $sale['customer_name'] ?? 'Not specified') ?></td>
                                    <td class="sale-date"><?= date('M j, Y g:i A', strtotime($sale['sale_date'] ?? $sale['created_at'])) ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-danger" onclick="showDeleteSaleToast(<?= $sale['id'] ?>, 'Sale #<?= $sale['id'] ?>')">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No sales records found. <a href="javascript:void(0)" onclick="showAddSaleModal()">Record your first sale</a></p>
                    </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
                <div class="card">
                    <h3>📊 Sales Tracking</h3>
                    <div class="no-data">
                        <h4>Sales table not configured</h4>
                        <p>The sales tracking feature is not available in the current database setup.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Add Sale Modal with Product Selection (without quantity units) -->
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
                            <select name="product_id" class="modern-select" id="productSelect" onchange="updateProductInfo()" required>
                                <option value="">Select from existing products...</option>
                                <?php 
                                $available_products = mysqli_query($conn, "SELECT id, name, stock_quantity, price FROM products ORDER BY name");
                                mysqli_data_seek($available_products, 0); 
                                while($product = mysqli_fetch_assoc($available_products)): 
                                    $stock_status = '';
                                    $disabled = '';
                                    if ($product['stock_quantity'] == 0) {
                                        $stock_status = '❌ Out of Stock';
                                        $disabled = 'disabled';
                                    } elseif ($product['stock_quantity'] < 10) {
                                        $stock_status = '⚠️ Low Stock';
                                    } else {
                                        $stock_status = '✅ In Stock';
                                    }
                                ?>
                                    <option value="<?= $product['id'] ?>" 
                                            data-price="<?= $product['price'] ?>" 
                                            data-stock="<?= $product['stock_quantity'] ?>"
                                            <?= $disabled ?>>
                                        <?= htmlspecialchars($product['name']) ?> | <?= $stock_status ?> | Stock: <?= $product['stock_quantity'] ?> | ₱<?= number_format($product['price'], 2) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
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
                                       placeholder="Enter customer/buyer name">
                            </div>
                            
                            <div class="form-field">
                                <label>🔢 Quantity (pcs)</label>
                                <input type="number" name="quantity" required min="0.001" step="0.001"
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
                            </div>
                            
                            <div class="form-field">
                                <label>💰 Total Price (₱)</label>
                                <input type="number" name="total_price" required step="0.01" min="0.01" 
                                       placeholder="0.00" class="modern-input" id="totalPriceInput"
                                       oninput="updateSummary()">
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-field">
                                <label>📅 Sale Date</label>
                                <input type="datetime-local" name="sale_date" value="<?= date('Y-m-d\TH:i') ?>" 
                                       class="modern-input" required>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Section -->
                    <div class="form-section highlight">
                        <h4>📊 Sale Summary</h4>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <span class="summary-label">Product:</span>
                                <span class="summary-value" id="summaryProduct">-</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Quantity:</span>
                                <span class="summary-value" id="summaryQuantity">0 pcs</span>
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
        // Theme Toggle Functionality
        function toggleTheme() {
            const html = document.documentElement;
            const themeToggle = document.getElementById('themeToggle');
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            // Update theme attribute
            html.setAttribute('data-theme', newTheme);
            
            // Update toggle button icon
            themeToggle.innerHTML = newTheme === 'dark' ? '☀️' : '🌙';
            
            // Save preference in cookie
            document.cookie = `dark_mode=${newTheme === 'dark'}; path=/; max-age=31536000`; // 1 year
            
            // Show theme change toast
            showSuccessToast(`${newTheme === 'dark' ? 'Dark' : 'Light'} mode enabled`);
        }

        // Initialize theme toggle
        document.getElementById('themeToggle').addEventListener('click', toggleTheme);

        // Tab Switching Functionality
        function switchTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show the selected tab content
            const selectedTab = document.getElementById(tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Update URL without page reload
            const newUrl = window.location.pathname + '?tab=' + tabName;
            window.history.pushState({}, '', newUrl);
            
            // Update active state in sidebar
            const menuItems = document.querySelectorAll('.sidebar-menu a');
            menuItems.forEach(item => {
                item.classList.remove('active');
                if (item.getAttribute('href') === '?tab=' + tabName) {
                    item.classList.add('active');
                }
            });
        }

        // Set active tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab') || 'dashboard';
            switchTab(activeTab);
            
            // Set current date and time when page loads
            const now = new Date();
            const localDateTime = now.toISOString().slice(0, 16);
            
            const saleDateInput = document.querySelector('input[name="sale_date"]');
            if (saleDateInput) saleDateInput.value = localDateTime;

            // Add logout button event listener
            document.getElementById('logoutBtn').addEventListener('click', function(e) {
                e.preventDefault();
                showLogoutToast();
            });
        });

        // Modal Functions
        function showAddSaleModal() {
            document.getElementById('addSaleModal').style.display = 'block';
            resetForm();
        }

        function hideAddSaleModal() {
            document.getElementById('addSaleModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addSaleModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'addSaleModal') hideAddSaleModal();
                }
            });
        }

        // Delete Sale Toast
        function showDeleteSaleToast(saleId, saleInfo) {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = 'toast warning';
            toast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">⚠️</span>
                    <span class="toast-message">Are you sure you want to delete "<strong>${saleInfo}</strong>"?</span>
                </div>
                <div class="toast-actions">
                    <button class="toast-btn cancel" onclick="hideToast(this)">Cancel</button>
                    <button class="toast-btn confirm" onclick="confirmDeleteSale(${saleId}, '${saleInfo.replace(/'/g, "\\'")}')">Yes, Delete</button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    hideToast(toast);
                }
            }, 10000);
        }

        // Logout Toast Function
        function showLogoutToast() {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = 'toast warning';
            toast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">🚪</span>
                    <span class="toast-message">Are you sure you want to logout?</span>
                </div>
                <div class="toast-actions">
                    <button class="toast-btn cancel" onclick="hideToast(this)">Cancel</button>
                    <button class="toast-btn confirm" onclick="confirmLogout()">Yes, Logout</button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    hideToast(toast);
                }
            }, 10000);
        }

        function confirmLogout() {
            // Show success toast first
            const toastContainer = document.getElementById('toastContainer');
            
            const successToast = document.createElement('div');
            successToast.className = 'toast success';
            successToast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">👋</span>
                    <span class="toast-message">Logging out...</span>
                </div>
            `;
            
            toastContainer.appendChild(successToast);
            
            // Hide all other toasts
            const toasts = toastContainer.querySelectorAll('.toast');
            toasts.forEach(t => {
                if (t !== successToast) {
                    hideToast(t);
                }
            });
            
            // Redirect after showing success message
            setTimeout(() => {
                hideToast(successToast);
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 300);
            }, 1500);
        }

        // Update product information when product selection changes
        function updateProductInfo() {
            const productSelect = document.getElementById('productSelect');
            const stockDisplay = document.getElementById('stockDisplay');
            const unitPriceInfo = document.getElementById('unitPriceInfo');
            const stockStatus = document.getElementById('stockStatus');
            const summaryProduct = document.getElementById('summaryProduct');
            const unitPriceInput = document.getElementById('unitPriceInput');
            
            if (productSelect.value) {
                // Existing product mode
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const productName = selectedOption.text.split(' | ')[0];
                const price = parseFloat(selectedOption.getAttribute('data-price'));
                const stock = parseInt(selectedOption.getAttribute('data-stock'));
                
                // Update displays
                stockDisplay.textContent = stock + ' pcs';
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
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const unitPrice = parseFloat(unitPriceInput.value) || 0;
            
            if (quantity > 0 && unitPrice > 0) {
                const totalPrice = unitPrice * quantity;
                totalPriceInput.value = totalPrice.toFixed(2);
            }
            
            // Validate stock for existing products
            if (productSelect.value) {
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
                quantityInput.style.borderColor = 'var(--border-color)';
                quantityInput.style.backgroundColor = 'var(--input-bg)';
            }
            
            updateSummary();
        }

        // Update the summary section
        function updateSummary() {
            const productSelect = document.getElementById('productSelect');
            const quantityInput = document.getElementById('quantityInput');
            const totalPriceInput = document.getElementById('totalPriceInput');
            const unitPriceInput = document.getElementById('unitPriceInput');
            
            // Update product name
            if (productSelect.value) {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const productName = selectedOption.text.split(' | ')[0];
                document.getElementById('summaryProduct').textContent = productName;
            } else {
                document.getElementById('summaryProduct').textContent = '-';
            }
            
            // Update quantities and prices
            const quantity = quantityInput.value || '0';
            document.getElementById('summaryQuantity').textContent = quantity + ' pcs';
            document.getElementById('summaryTotal').textContent = totalPriceInput.value ? '₱' + parseFloat(totalPriceInput.value).toFixed(2) : '₱0.00';
            document.getElementById('summaryUnit').textContent = unitPriceInput.value ? '₱' + parseFloat(unitPriceInput.value).toFixed(2) : '₱0.00';
        }

        // Reset form function
        function resetForm() {
            document.getElementById('productSelect').value = '';
            document.getElementById('quantityInput').value = '';
            document.getElementById('totalPriceInput').value = '';
            document.getElementById('unitPriceInput').value = '';
            document.querySelector('input[name="buyername"]').value = '';
            
            updateProductInfo();
            updateSummary();
        }

        function confirmDeleteSale(saleId, saleInfo) {
            // Show success toast first
            const toastContainer = document.getElementById('toastContainer');
            
            const successToast = document.createElement('div');
            successToast.className = 'toast success';
            successToast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">✅</span>
                    <span class="toast-message">Sale "${saleInfo}" deleted successfully!</span>
                </div>
            `;
            
            toastContainer.appendChild(successToast);
            
            // Remove the sale row from the table with animation
            const saleRow = document.getElementById('sale-' + saleId);
            if (saleRow) {
                saleRow.style.transition = 'all 0.3s ease';
                saleRow.style.opacity = '0';
                saleRow.style.transform = 'translateX(-100%)';
                
                setTimeout(() => {
                    saleRow.remove();
                    // Update total sales count
                    const totalSalesElement = document.querySelector('.table-container h3');
                    if (totalSalesElement) {
                        const currentCount = parseInt(totalSalesElement.textContent.match(/\d+/)[0]);
                        totalSalesElement.textContent = totalSalesElement.textContent.replace(/\d+/, currentCount - 1);
                    }
                }, 300);
            }
            
            // Redirect to delete the sale from database
            setTimeout(() => {
                window.location.href = '?tab=sales&delete_sale=' + saleId;
            }, 1000);
            
            // Hide all other toasts
            const toasts = toastContainer.querySelectorAll('.toast');
            toasts.forEach(t => {
                if (t !== successToast) {
                    hideToast(t);
                }
            });
            
            // Auto-hide success toast after 3 seconds
            setTimeout(() => {
                hideToast(successToast);
            }, 3000);
        }

        function hideToast(element) {
            const toast = element.closest('.toast') || element;
            toast.classList.add('hide');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }

        function showSuccessToast(message) {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = 'toast success';
            toast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">✅</span>
                    <span class="toast-message">${message}</span>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                hideToast(toast);
            }, 3000);
        }

        function showErrorToast(message) {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = 'toast warning';
            toast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">❌</span>
                    <span class="toast-message">${message}</span>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                hideToast(toast);
            }, 5000);
        }

        // Form validation for sales
        document.getElementById('addSaleForm')?.addEventListener('submit', function(e) {
            const quantity = document.querySelector('input[name="quantity"]').value;
            const totalPrice = document.querySelector('input[name="total_price"]').value;
            const productId = document.querySelector('select[name="product_id"]').value;
            
            if (!productId) {
                showErrorToast('Please select a product.');
                e.preventDefault();
                return false;
            }
            
            if (quantity <= 0) {
                showErrorToast('Quantity must be greater than zero.');
                e.preventDefault();
                return false;
            }
            
            if (totalPrice <= 0) {
                showErrorToast('Total price must be greater than zero.');
                e.preventDefault();
                return false;
            }
        });

        // Add event listeners for real-time updates
        document.addEventListener('DOMContentLoaded', function() {
            const productSelect = document.getElementById('productSelect');
            const quantityInput = document.getElementById('quantityInput');
            const totalPriceInput = document.getElementById('totalPriceInput');
            const unitPriceInput = document.getElementById('unitPriceInput');
            
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
        });
    </script>
</body>
</html>