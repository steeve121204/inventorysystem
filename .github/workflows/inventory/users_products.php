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
$active_tab = 'products';

// Handle POST: add product
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_product'])) {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $category_input = mysqli_real_escape_string($conn, $_POST['category_input']);
        $price = mysqli_real_escape_string($conn, $_POST['price']);
        $stock_quantity = mysqli_real_escape_string($conn, $_POST['stock_quantity']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $product_date = mysqli_real_escape_string($conn, $_POST['product_date']);

        $category_id = null;
        if (!empty($category_input)) {
            $category_check = mysqli_query($conn, "SELECT id FROM category WHERE name = '$category_input'");
            if (mysqli_num_rows($category_check) > 0) {
                $category_row = mysqli_fetch_assoc($category_check);
                $category_id = $category_row['id'];
            } else {
                $category_sql = "INSERT INTO category (name) VALUES ('$category_input')";
                if (mysqli_query($conn, $category_sql)) {
                    $category_id = mysqli_insert_id($conn);
                }
            }
        }

        if (!empty($product_date)) {
            $sql = "INSERT INTO products (name, category_id, price, stock_quantity, description, created_at) VALUES ('$name', '$category_id', '$price', '$stock_quantity', '$description', '$product_date')";
        } else {
            $sql = "INSERT INTO products (name, category_id, price, stock_quantity, description) VALUES ('$name', '$category_id', '$price', '$stock_quantity', '$description')";
        }

        if (mysqli_query($conn, $sql)) {
            $product_id = mysqli_insert_id($conn);
            if ($product_id) {
                $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '$stock_quantity', 0, '$stock_quantity', 'Product created by user')";
                mysqli_query($conn, $log_sql);
            }
            $message = 'Product added successfully!';
        } else {
            $message = 'Error adding product: ' . mysqli_error($conn);
        }
    }
}

// AJAX update product (similar to admin)
if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['update_product_ajax'])) {
    header('Content-Type: application/json');

    try {
        $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $category_input = mysqli_real_escape_string($conn, $_POST['category_input']);
        $price = mysqli_real_escape_string($conn, $_POST['price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);

        if (empty($name) || empty($price) || !is_numeric($stock_quantity) || $stock_quantity < 0) {
            throw new Exception('Please fill all required fields correctly. Stock must be a positive number.');
        }

        $category_id = null;
        if (!empty($category_input)) {
            $category_check = mysqli_query($conn, "SELECT id FROM category WHERE name = '$category_input'");
            if (mysqli_num_rows($category_check) > 0) {
                $category_row = mysqli_fetch_assoc($category_check);
                $category_id = $category_row['id'];
            } else {
                $category_sql = "INSERT INTO category (name) VALUES ('$category_input')";
                if (mysqli_query($conn, $category_sql)) {
                    $category_id = mysqli_insert_id($conn);
                } else {
                    throw new Exception('Error creating category: ' . mysqli_error($conn));
                }
            }
        }

        $old_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
        if (!$old_product) throw new Exception('Product not found');
        $old_stock = intval($old_product['stock_quantity']);
        $stock_change = $stock_quantity - $old_stock;

        if ($category_id !== null) {
            $sql = "UPDATE products SET name='$name', price='$price', stock_quantity='$stock_quantity', description='$description', category_id='$category_id' WHERE id='$product_id'";
        } else {
            $sql = "UPDATE products SET name='$name', price='$price', stock_quantity='$stock_quantity', description='$description', category_id=NULL WHERE id='$product_id'";
        }

        if (mysqli_query($conn, $sql)) {
            if ($stock_change != 0) {
                $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '$stock_change', '$old_stock', '$stock_quantity', 'Manual product update by user')";
                mysqli_query($conn, $log_sql);
            }
            $updated_product = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.*, COALESCE(c.name, 'Uncategorized') as category_name FROM products p LEFT JOIN category c ON p.category_id = c.id WHERE p.id = '$product_id'"));
            echo json_encode(['success' => true, 'message' => 'Product updated successfully!', 'product' => ['id' => $updated_product['id'], 'name' => $updated_product['name'], 'category_name' => $updated_product['category_name'], 'price' => $updated_product['price'], 'stock_quantity' => intval($updated_product['stock_quantity']), 'description' => $updated_product['description']]]);
        } else {
            throw new Exception('Error updating product: ' . mysqli_error($conn));
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Delete product
if (isset($_GET['delete_product'])) {
    $product_id = mysqli_real_escape_string($conn, $_GET['delete_product']);
    $product_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id='$product_id'"));
    if ($product_info) {
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0");
        $delete_sql = "DELETE FROM products WHERE id='$product_id'";
        if (mysqli_query($conn, $delete_sql)) {
            mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
            $message = 'Product deleted successfully!';
        } else {
            mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
            $message = 'Error deleting product: ' . mysqli_error($conn);
        }
    }
}

// Load data
$categories = mysqli_query($conn, "SELECT * FROM category ORDER BY name");
$all_products = mysqli_query($conn, "SELECT p.*, c.name as category_name FROM products p LEFT JOIN category c ON p.category_id = c.id ORDER BY p.created_at ASC");
$total_products = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM products"))['count'];

?>
<!DOCTYPE html>
<html>
<head>
    <title>User Products - Inventory</title>
    <meta charset="utf-8" />
    <style>
        /* Reuse admin styles */
        * { box-sizing: border-box; }
        body { font-family:Poppins, sans-serif; background:#f8f9fa; margin:0; min-height:100vh; display:flex; }
        .sidebar { width:250px; background:white; padding:20px; box-shadow:2px 0 10px rgba(0,0,0,0.1); height:100vh; position:fixed; }
        .main-content { margin-left:250px; padding:30px; flex:1; }
        .header { background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
        .table-container { background:white; padding:25px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
        .product-table { width:100%; border-collapse:collapse; }
        .product-table th { background:#4853c8; color:white; padding:12px; text-align:left; }
        .product-table td { padding:12px; border-bottom:1px solid #e0e0e0; }
        .btn { padding:8px 15px; border:none; border-radius:5px; background:#3498db; color:white; cursor:pointer; }
        .btn-danger { background:#e74c3c; }
        .btn-success { background:#27ae60; }
        .modal { display:none; position:fixed; left:0; top:0; width:100%; height:100%; background: rgba(0,0,0,0.5); }
        .modal-content { background:white; margin:5% auto; padding:30px; max-width:700px; border-radius:10px; }
        .toast-container { position:fixed; top:20px; right:20px; z-index:1000; }
        .toast { background:white; padding:15px 20px; border-radius:8px; box-shadow:0 4px 15px rgba(0,0,0,0.2); margin-bottom:10px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>🏠 User Panel</h2>
        <ul class="sidebar-menu">
            <li><a href="users_dashboard.php">Dashboard</a></li>
            <li><a href="users_products.php" class="active">Products</a></li>
            <li><a href="users_sales.php">Sales</a></li>
            <li><a href="users_supplies.php">Supplies</a></li>
        </ul>
    </div>
    <div class="main-content">
        <div class="header">
            <div><h1>Products</h1><p>Welcome, <strong><?= htmlspecialchars($username) ?></strong>!</p></div>
            <div><a href="logout.php" class="btn btn-danger">Logout</a></div>
        </div>

        <?php if ($message): ?>
            <div style="margin-bottom:10px; color:#155724; background:#d4edda; border-radius:6px; padding:12px;"> <?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="table-container">
            <button class="btn btn-success" onclick="showAddProductModal()">➕ Add Product</button>
            <button class="btn" onclick="window.location.href='users_products.php'">Refresh</button>
            <?php if (mysqli_num_rows($all_products) > 0): ?>
                <table class="product-table">
                    <thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Description</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody id="productsTableBody">
                        <?php mysqli_data_seek($all_products, 0); while($product = mysqli_fetch_assoc($all_products)): ?>
                        <tr id="product-<?= $product['id'] ?>">
                            <td>#<?= $product['id'] ?></td>
                            <td class="product-name"><?= htmlspecialchars($product['name']) ?></td>
                            <td class="product-category"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></td>
                            <td class="product-price">₱<?= number_format($product['price'], 2) ?></td>
                            <td class="product-stock"><?= $product['stock_quantity'] ?></td>
                            <td class="product-description"><?= htmlspecialchars($product['description']) ?></td>
                            <td><?= date('M j, Y g:i A', strtotime($product['created_at'])) ?></td>
                            <td>
                                <button class="btn" onclick="window.location.href='users_products.php?edit=<?= $product['id'] ?>'">Edit</button>
                                <button class="btn btn-danger" onclick="showDeleteToast('product', <?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')">Delete</button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No products found. <a href="javascript:void(0)" onclick="showAddProductModal()">Add your first product</a></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div id="addProductModal" class="modal">
        <div class="modal-content">
            <h3>Add Product</h3>
            <form action="" method="POST">
                <div>
                    <label>Name</label>
                    <input type="text" name="name" required />
                </div>
                <div>
                    <label>Category</label>
                    <input type="text" name="category_input" />
                </div>
                <div>
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" required />
                </div>
                <div>
                    <label>Stock</label>
                    <input type="number" name="stock_quantity" required />
                </div>
                <div>
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>
                <div style="margin-top:10px;">
                    <button type="button" class="btn btn-danger" onclick="hideAddProductModal()">Cancel</button>
                    <button type="submit" name="add_product" class="btn btn-success">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="editProductModal" class="modal">
        <div class="modal-content">
            <h3>Edit Product</h3>
            <form id="editProductForm">
                <input type="hidden" name="product_id" id="editProductId">
                <div>
                    <label>Name</label>
                    <input type="text" name="name" id="editProductName" required />
                </div>
                <div>
                    <label>Category</label>
                    <input type="text" name="category_input" id="editCategoryInput" />
                </div>
                <div>
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" id="editProductPrice" required />
                </div>
                <div>
                    <label>Stock</label>
                    <input type="number" name="stock_quantity" id="editProductStock" required />
                </div>
                <div>
                    <label>Description</label>
                    <textarea name="description" id="editProductDescription"></textarea>
                </div>
                <div style="margin-top:10px;">
                    <button type="button" class="btn btn-danger" onclick="hideEditProductModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Product</button>
                </div>
            </form>
        </div>
    </div>
