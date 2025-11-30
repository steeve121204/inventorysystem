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

// Helper: check whether a column exists in a table (for backward compat)
function column_exists($conn, $table, $column) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

// Handle POST: add_supply
if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['add_supply'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['supply_product_id'] ?? '');
    $quantity = intval($_POST['supply_quantity'] ?? 0);
    $supplier = mysqli_real_escape_string($conn, $_POST['supplier'] ?? '');

    if ($quantity <= 0) {
        $message = 'Quantity must be greater than zero.';
    } else {
        $product_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
        if (!$product_row) {
            $message = 'Product not found.';
        } else {
            // Build flexible insert query for supplies: include supply_date and notes if existing in DB
            $columns = ['product_id','user_id','quantity'];
            $values = ["'$product_id'","'$user_id'","'$quantity'"]; 
            if (column_exists($conn, 'supplies', 'supplier') && !empty($supplier)) {
                $columns[] = 'supplier';
                $values[] = "'$supplier'";
            }
            if (column_exists($conn, 'supplies', 'supply_date') && !empty($_POST['supply_date'])) {
                $sd = mysqli_real_escape_string($conn, date('Y-m-d H:i:s', strtotime($_POST['supply_date'])));
                $columns[] = 'supply_date';
                $values[] = "'$sd'";
            }
            if (column_exists($conn, 'supplies', 'supply_notes') && !empty($_POST['supply_notes'])) {
                $notes = mysqli_real_escape_string($conn, $_POST['supply_notes']);
                $columns[] = 'supply_notes';
                $values[] = "'$notes'";
            }
            $sql = "INSERT INTO supplies (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
            if (mysqli_query($conn, $sql)) {
                $new_stock = $product_row['stock_quantity'] + $quantity;
                mysqli_query($conn, "UPDATE products SET stock_quantity='$new_stock' WHERE id='$product_id'");
                $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '$quantity', '{$product_row['stock_quantity']}', '$new_stock', 'Supply recorded by user')";
                mysqli_query($conn, $log_sql);
                $message = 'Supply added successfully.';
            } else {
                $message = 'Error adding supply: ' . mysqli_error($conn);
            }
        }
    }
}

// Handle delete via GET
if ($_SERVER["REQUEST_METHOD"] === 'GET' && isset($_GET['delete_supply']) && table_exists($conn, 'supplies')) {
    $del_id = mysqli_real_escape_string($conn, $_GET['delete_supply']);
    $supply_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM supplies WHERE id='$del_id' AND user_id='$user_id'"));
    if ($supply_row) {
        $product_id = $supply_row['product_id'];
        $qty = intval($supply_row['quantity']);
        $product_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
        if ($product_row) {
            $new_stock = $product_row['stock_quantity'] - $qty;
            if ($new_stock < 0) $new_stock = 0;
            mysqli_query($conn, "UPDATE products SET stock_quantity='$new_stock' WHERE id='$product_id'");
            $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '-$qty', '{$product_row['stock_quantity']}', '$new_stock', 'Supply deleted by user')";
            mysqli_query($conn, $log_sql);
        }
        mysqli_query($conn, "DELETE FROM supplies WHERE id='$del_id'");
        $message = 'Supply deleted and stock adjusted';
    }
}

    // Load products for select and list data
    $products_select = mysqli_query($conn, "SELECT id, name, stock_quantity, price FROM products ORDER BY name");
    $total_supplies_user = 0;
    $all_supplies = [];
    if (table_exists($conn, 'supplies')) {
        $total_supplies_user = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM supplies WHERE user_id='$user_id'"))['count'];
        // prefer supply_date in order if exists
        $order_by = column_exists($conn, 'supplies', 'supply_date') ? 'supply_date DESC' : 'created_at DESC';
        $all_supplies = mysqli_query($conn, "SELECT s.*, p.name as product_name FROM supplies s LEFT JOIN products p ON s.product_id = p.id WHERE s.user_id = '$user_id' ORDER BY $order_by");
    }

    <!-- Add Supply Modal (Admin-style) -->
    <div id="addSupplyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin:0;">📥 Add New Supply</h3>
                <button type="button" class="close-btn" onclick="hideAddSupplyModal()">&times;</button>
            </div>
            <form action="" method="POST" id="addSupplyForm">
                <div class="product-info">
                    <p><strong>Note:</strong> Supplies will increase the current stock of the selected product.</p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>📦 Product *</label>
                        <select name="supply_product_id" id="supplyProductSelect" required class="form-select" onchange="calculateSupplyStock()">
                            <option value="">Select a product to restock...</option>
                            <?php mysqli_data_seek($products_select,0); while($p = mysqli_fetch_assoc($products_select)): ?>
                                <option value="<?= $p['id'] ?>" data-stock="<?= $p['stock_quantity'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> 
                                    (Current Stock: <?= $p['stock_quantity'] ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>🔢 Quantity *</label>
                        <input type="number" name="supply_quantity" id="supplyQuantityInput" required min="1" class="form-input" 
                           placeholder="Enter quantity to add" oninput="calculateSupplyStock()">
                        <div class="form-help-text">Current stock: <span id="supplyStockInfo">0</span></div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Current Stock</label>
                        <div class="price-display">
                            <div class="price-label">Current Stock</div>
                            <div class="price-value" id="currentStockDisplay">0 units</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>New Stock After Supply</label>
                        <div class="price-display">
                            <div class="price-label">New Stock</div>
                            <div class="price-value" id="newStockDisplay">0 units</div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>🏢 Supplier Information</label>
                        <input type="text" name="supplier" class="form-input" 
                           placeholder="Enter supplier name or company">
                        <div class="form-help-text">Where did this supply come from?</div>
                    </div>
                    <div class="form-group">
                        <label>📅 Supply Date</label>
                                <input type="datetime-local" name="supply_date" class="form-input" 
                                    value="<?= date('Y-m-d\\TH:i') ?>">
                        <div class="form-help-text">Date when supply was received</div>
                    </div>
                </div>

                <div class="form-group">
                    <label>📝 Notes (Optional)</label>
                    <textarea name="supply_notes" class="form-textarea" rows="3" placeholder="Any additional information about this supply..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideAddSupplyModal()">❌ Cancel</button>
                    <button type="submit" name="add_supply" class="btn btn-success">✅ Add Supply</button>
                </div>
            </form>
        </div>
    </div>
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

        /* Modal Styles - UPDATED TO MATCH ADMIN */
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
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideIn 0.3s;
            position: relative;
            max-height: 90vh;
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

                /* Form Styles - EXACT MATCH TO ADMIN DASHBOARD */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
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
            border-top: 2px solid #f0f0f0;
        }

        .form-actions .btn {
            padding: 12px 25px;
            font-size: 14px;
            min-width: 120px;
        }

        .form-help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }

        .price-display {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 0.5rem;
            padding: 1rem;
            margin: 1rem 0;
        }

        .price-label {
            color: #0369a1;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .price-value {
            color: #0c4a6e;
            font-size: 1.125rem;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
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
            <li><a href="users_sales.php">💰 Sales</a></li>
            <li><a href="users_supplies.php" class="active">📥 Supplies</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header">
            <div>
                <h1>Supplies Management</h1>
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
                <h3>Total Supplies</h3>
                <div class="stat-number"><?= $total_supplies_user ?></div>
            </div>
        </div>

        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3>Supplies Records</h3>
                <button class="action-btn" onclick="showAddSupplyModal()">
                    <span>📥</span>
                    Add New Supply
                </button>
            </div>
            
            <?php if (is_object($all_supplies) && mysqli_num_rows($all_supplies) > 0): ?>
                <table class="product-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($supply = mysqli_fetch_assoc($all_supplies)): ?>
                        <tr id="supply-<?= $supply['id'] ?>">
                            <td>#<?= $supply['id'] ?></td>
                            <td><?= htmlspecialchars($supply['product_name']) ?></td>
                            <td><?= $supply['quantity'] ?></td>
                            <td><?= htmlspecialchars($supply['supplier'] ?? 'N/A') ?></td>
                            <td><?= date('M j, Y g:i A', strtotime($supply['created_at'])) ?></td>
                            <td>
                                <button class="btn btn-danger" onclick="deleteSupply(<?= $supply['id'] ?>, '<?= htmlspecialchars($supply['product_name']) ?>')">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: #64748b;">
                    <h3 style="color: #475569; margin-bottom: 1rem;">No Supplies Recorded</h3>
                    <p style="margin-bottom: 1.5rem;">Start by adding your first supply.</p>
                    <button class="action-btn" onclick="showAddSupplyModal()">
                        <span>📥</span>
                        Add First Supply
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Removed duplicate modal — using admin-style modal above -->

<script>
// For Supply Modal
function showAddSupplyModal() {
    document.getElementById('addSupplyModal').style.display = 'block';
    // Reset form values
    const productSelect = document.getElementById('supplyProductSelect');
    const qtyInput = document.getElementById('supplyQuantityInput');
    const form = document.getElementById('addSupplyForm');
    if (form) form.reset();
    if (productSelect) productSelect.value = '';
    if (qtyInput) qtyInput.value = '';
    // Set supply date to now in the local datetime-local format
    const supplyDate = document.querySelector('input[name="supply_date"]');
    if (supplyDate) {
        const now = new Date();
        supplyDate.value = now.toISOString().slice(0,16);
    }
    calculateSupplyStock(); // Initialize stock info
}

function hideAddSupplyModal() {
    document.getElementById('addSupplyModal').style.display = 'none';
    const form = document.getElementById('addSupplyForm');
    if (form) form.reset();
}

// Delete confirmation for supplies
function deleteSupply(id, productName) {
    if (confirm('Are you sure you want to delete the supply record for "' + productName + '"?')) {
        window.location.href = 'users_supplies.php?delete_supply=' + id;
    }
}

// sales calculation handled on users_sales.php

// Stock info for supplies
function calculateSupplyStock() {
    const productSelect = document.getElementById('supplyProductSelect') || document.querySelector('select[name="supply_product_id"]');
    const quantityInput = document.getElementById('supplyQuantityInput') || document.querySelector('input[name="supply_quantity"]');
    const stockInfo = document.getElementById('supplyStockInfo');
    const currentStockDisplay = document.getElementById('currentStockDisplay');
    const newStockDisplay = document.getElementById('newStockDisplay');
    
    if (productSelect && productSelect.value) {
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const currentStock = parseInt(selectedOption.getAttribute('data-stock'));
        const quantity = parseInt(quantityInput.value) || 0;
        const newStock = currentStock + quantity;
        
        stockInfo.textContent = currentStock;
        currentStockDisplay.textContent = currentStock + ' units';
        newStockDisplay.textContent = newStock + ' units';
        const submitBtn = document.querySelector('#addSupplyForm button[type="submit"]');
        if (submitBtn) submitBtn.disabled = (quantity <= 0);
    } else {
        stockInfo.textContent = '0';
        currentStockDisplay.textContent = '0 units';
        newStockDisplay.textContent = '0 units';
        const submitBtn = document.querySelector('#addSupplyForm button[type="submit"]');
        if (submitBtn) submitBtn.disabled = false;
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    const saleModal = document.getElementById('addSaleModal');
    const supplyModal = document.getElementById('addSupplyModal');
    
    if (event.target === saleModal) {
        hideAddSaleModal();
    }
    if (event.target === supplyModal) {
        hideAddSupplyModal();
    }
}
// Add event listeners for real-time updates
document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.querySelector('select[name="supply_product_id"]');
    const quantityInput = document.querySelector('input[name="supply_quantity"]');
    
    if (productSelect) {
        productSelect.addEventListener('change', calculateSupplyStock);
    }
    if (quantityInput) {
        quantityInput.addEventListener('input', calculateSupplyStock);
    }
});
</script>
</body>
</html>