<?php
session_start();

error_log("Session data: " . print_r($_SESSION, true));

if (!isset($_SESSION["role"])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION["role"] != "admin") {
    header("Location: users_dashboard.php");
    exit;
}

include "db.php";

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$total_products = 0;
$total_sales = 0;
$total_revenue = 0;
$today_sales = 0;
$today_revenue = 0;
$low_stock = 0;
$out_of_stock = 0;
$total_suppliers = 0;
$message = "";
$active_tab = $_GET['tab'] ?? 'dashboard';
$dark_mode = isset($_COOKIE['dark_mode']) ? $_COOKIE['dark_mode'] === 'true' : false;

function table_exists($conn, $table) {
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $res && mysqli_num_rows($res) > 0;
}

function column_exists($conn, $table, $column) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && mysqli_num_rows($res) > 0;
}

try {
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM products");
    if ($result) {
        $total_products = mysqli_fetch_assoc($result)['count'];
    }
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM products WHERE stock_quantity < 10 AND stock_quantity > 0");
    if ($result) {
        $low_stock = mysqli_fetch_assoc($result)['count'];
    }
    
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM products WHERE stock_quantity = 0");
    if ($result) {
        $out_of_stock = mysqli_fetch_assoc($result)['count'];
    }
    
    if (table_exists($conn, 'sales')) {
    $total_column = 'total_amount';
        if (column_exists($conn, 'sales', 'total_price')) {
            $total_column = 'total_price';
        }
        
        $sales_result = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count, SUM($total_column) as total FROM sales"));
        $total_sales = $sales_result['count'] ?? 0;
        $total_revenue = $sales_result['total'] ?? 0;
        
        $today = date('Y-m-d');
        $today_sales_result = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count, SUM($total_column) as total FROM sales WHERE DATE(created_at) = '$today'"));
        $today_sales = $today_sales_result['count'] ?? 0;
        $today_revenue = $today_sales_result['total'] ?? 0;
        
        $recent_sales = mysqli_query($conn, "
            SELECT s.*, COALESCE(p.name, s.product_name) as product_name, u.username as user_name 
            FROM sales s 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN products p ON s.product_id = p.id 
            ORDER BY s.created_at DESC 
            LIMIT 10
        ");
        
        $all_sales = mysqli_query($conn, "
            SELECT s.*, COALESCE(p.name, s.product_name) as product_name, u.username as user_name 
            FROM sales s 
            LEFT JOIN users u ON s.user_id = u.id 
            LEFT JOIN products p ON s.product_id = p.id 
            ORDER BY s.created_at DESC
        ");
    } else {
        $recent_sales = false;
        $all_sales = false;
    }
    
    if (table_exists($conn, 'supplier')) {
        $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM supplier");
        if ($result) {
            $total_suppliers = mysqli_fetch_assoc($result)['count'];
        }
        
        $recent_suppliers = mysqli_query($conn, "
            SELECT s.*, COALESCE(p.name, s.suppliedproduct) as suppliedproduct_name
            FROM supplier s
            LEFT JOIN products p ON LOWER(p.name) = LOWER(s.suppliedproduct)
            ORDER BY s.created_at DESC 
            LIMIT 5
        ");
        
        $all_suppliers = mysqli_query($conn, "
            SELECT s.*, COALESCE(p.name, s.suppliedproduct) as suppliedproduct_name
            FROM supplier s
            LEFT JOIN products p ON LOWER(p.name) = LOWER(s.suppliedproduct)
            ORDER BY s.created_at DESC
        ");
    } else {
        $recent_suppliers = false;
        $all_suppliers = false;
    }
    
    $recent_products = mysqli_query($conn, "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN category c ON p.category_id = c.id 
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    
    $all_products = mysqli_query($conn, "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN category c ON p.category_id = c.id 
        ORDER BY p.created_at ASC
    ");
    
    $categories = mysqli_query($conn, "SELECT * FROM category ORDER BY name");
    
} catch (Exception $e) {
    error_log("Error fetching data: " . $e->getMessage());
    $message = "Error loading data: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (isset($_POST['add_product'])) {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $category_input = mysqli_real_escape_string($conn, $_POST['category_input'] ?? '');
        // If category select is disabled and new_category is provided, use it
        if (empty($category_input) && !empty($_POST['new_category'])) {
            $category_input = mysqli_real_escape_string($conn, $_POST['new_category']);
        }

        // Find or create category id for the supplier's category input
        $category_id = null;
        if (!empty($category_input)) {
            $category_check = mysqli_query($conn, "SELECT id FROM category WHERE name = '$category_input' LIMIT 1");
            if ($category_check && mysqli_num_rows($category_check) > 0) {
                $category_row = mysqli_fetch_assoc($category_check);
                $category_id = $category_row['id'];
            } else {
                $category_sql = "INSERT INTO category (name) VALUES ('$category_input')";
                if (mysqli_query($conn, $category_sql)) {
                    $category_id = mysqli_insert_id($conn);
                }
            }
        }
        $price = mysqli_real_escape_string($conn, $_POST['price']);
        $stock_quantity = mysqli_real_escape_string($conn, $_POST['stock_quantity']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $product_date = mysqli_real_escape_string($conn, $_POST['product_date']);
        $user_id = $_SESSION['user_id'] ?? 1;

        if (empty($name) || empty($price) || !is_numeric($stock_quantity)) {
            $message = "Please fill all required fields correctly.";
        } else {
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
                $sql = "INSERT INTO products (name, category_id, price, stock_quantity, description, created_at) 
                        VALUES ('$name', " . ($category_id ? "'$category_id'" : "NULL") . ", '$price', '$stock_quantity', '$description', '$product_date')";
            } else {
                $sql = "INSERT INTO products (name, category_id, price, stock_quantity, description) 
                        VALUES ('$name', " . ($category_id ? "'$category_id'" : "NULL") . ", '$price', '$stock_quantity', '$description')";
            }
            
            if (mysqli_query($conn, $sql)) {
                $product_id = mysqli_insert_id($conn);
                $message = "Product added successfully!";
                
                $all_products = mysqli_query($conn, "
                    SELECT p.*, c.name as category_name 
                    FROM products p 
                    LEFT JOIN category c ON p.category_id = c.id 
                    ORDER BY p.created_at ASC
                ");
            } else {
                $message = "Error adding product: " . mysqli_error($conn);
            }
        }
    }
    
    if (isset($_POST['add_sale'])) {
        $product_id = mysqli_real_escape_string($conn, $_POST['product_id'] ?? '');
        $quantity = floatval($_POST['quantity'] ?? 0);
        $buyername = mysqli_real_escape_string($conn, $_POST['buyername'] ?? '');
        $total_price = floatval($_POST['total_price'] ?? 0);
        $unit_price = isset($_POST['unit_price']) ? floatval($_POST['unit_price']) : null;
        $sale_date_input = $_POST['sale_date'] ?? '';
        $user_id = $_SESSION['user_id'];
        

        $sale_date = '';
        if (!empty($sale_date_input)) {
            $sale_date = date('Y-m-d H:i:s', strtotime($sale_date_input));
        } else {    
            $sale_date = date('Y-m-d H:i:s');
        }
        

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
      
                $unit_price = ($unit_price !== null && $unit_price >= 0) ? $unit_price : (float)$product_row['price'];
                $computed_total = round($unit_price * $quantity, 2);
                
      
                $has_product_id = column_exists($conn, 'sales', 'product_id');
                
                $columns = ['user_id','quantity'];
                $values = ["'$user_id'", "'$quantity'"];
           
                if ($has_product_id) {
                    $columns[] = 'product_id';
                    $values[] = "'$product_id'";
                }

                if (column_exists($conn, 'sales', 'product_name')) {
                    $columns[] = 'product_name';
                    $values[] = "'" . mysqli_real_escape_string($conn, $product_row['name']) . "'";
                }
                
  
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
                    $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id', '-$quantity', '{$product_row['stock_quantity']}', '$new_stock', 'Sale recorded by admin')";
                    mysqli_query($conn, $log_sql);
                    
                    $message = 'Sale added successfully. Stock updated.';
                    // Refresh to show updated data
                    header("Location: admin_dashboard.php?tab=sales&message=" . urlencode($message));
                    exit;
                } else {
                    $message = 'Error recording sale: ' . mysqli_error($conn);
                    error_log("Sales Insert Error: " . mysqli_error($conn) . " - SQL: " . $sql);
                }
            }
        }
    }
    
    if (isset($_POST['add_supplier'])) {
        $suppliername = mysqli_real_escape_string($conn, $_POST['suppliername'] ?? '');
        $suppliedproduct = mysqli_real_escape_string($conn, $_POST['suppliedproduct'] ?? '');
        $quantity = $_POST['quantity'] ?? 0;
        $price = $_POST['price'] ?? 0;
        $supplier_date_input = $_POST['supplier_date'] ?? '';
        
        error_log("Supplier Form Data - Name: $suppliername, Product: $suppliedproduct, Quantity: $quantity, Price: $price");
        
        $quantity_int = intval($quantity);
        $price_float = floatval($price);
        
        $supplier_date = '';
        if (!empty($supplier_date_input)) {
            $supplier_date = date('Y-m-d H:i:s', strtotime($supplier_date_input));
        } else {
            $supplier_date = date('Y-m-d H:i:s');
        }
        
        if (empty(trim($suppliername))) {
            $message = 'Supplier name is required.';
        } else if (empty(trim($suppliedproduct))) {
            $message = 'Supplied product is required.';
        } else if ($quantity_int <= 0) {
            $message = 'Quantity must be greater than zero. Received: ' . $quantity . ' (converted to: ' . $quantity_int . ')';
        } else if ($price_float <= 0) {
            $message = 'Price must be greater than zero. Received: ' . $price . ' (converted to: ' . $price_float . ')';
        } else {
            // Build supplier insert with optional category column if available
            $supplier_columns = [
                'suppliername', 'suppliedproduct', 'quantity', 'price', 'date', 'created_at'
            ];
            $supplier_values = [
                "'$suppliername'", "'$suppliedproduct'", "'$quantity_int'", "'$price_float'", "'$supplier_date'", "'$supplier_date'"
            ];

            $sql = "INSERT INTO supplier (" . implode(', ', $supplier_columns) . ") VALUES (" . implode(', ', $supplier_values) . ")";
            
            try {
                if (mysqli_query($conn, $sql)) {
                  
                    $supplier_id = mysqli_insert_id($conn);
                    $message = 'Supplier added successfully!';

                 
                    if (table_exists($conn, 'products')) {
                        $suppliedproduct_escaped = mysqli_real_escape_string($conn, $suppliedproduct);
                        $product_check_q = mysqli_query($conn, "SELECT id, stock_quantity, price FROM products WHERE LOWER(name) = LOWER('$suppliedproduct_escaped') LIMIT 1");
                        $prev_stock = 0;
                        if ($product_check_q && mysqli_num_rows($product_check_q) > 0) {
                            $prod_row = mysqli_fetch_assoc($product_check_q);
                            $product_id = $prod_row['id'];
                            $prev_stock = intval($prod_row['stock_quantity']);
                            $new_stock = $prev_stock + $quantity_int;
                           
                            $update_product_sql = "UPDATE products SET stock_quantity='$new_stock'";
                          
                            if ($price_float > 0) {
                                $update_product_sql .= ", price='$price_float'";
                            }
                            if (!empty($supplier_date)) {
                                $update_product_sql .= ", created_at='$supplier_date'";
                            }
                            $update_product_sql .= " WHERE id='$product_id'";
                            mysqli_query($conn, $update_product_sql);
                            $message .= ' Product stock updated.';
                        } else {
                      
                            $created_at_clause = '';
                            if (!empty($supplier_date)) {
                                $created_at_clause = ", '$supplier_date'";
                            } else {
                                $created_at_clause = '';
                            }
                       
                            $insert_fields = ["name", "price", "stock_quantity"];
                            $insert_values = ["'" . $suppliedproduct_escaped . "'", "'$price_float'", "'$quantity_int'"];
                            $insert_fields[] = 'created_at';
                            $insert_fields[] = 'description';
                            $insert_values[] = (!empty($supplier_date) ? "'" . $supplier_date . "'" : "NOW()");
                            $insert_values[] = "''";
                            $insert_product_sql = "INSERT INTO products (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
                            if (mysqli_query($conn, $insert_product_sql)) {
                                $product_id = mysqli_insert_id($conn);
                                $message .= ' New product created from supplier record.';
                                $prev_stock = 0;
                                $new_stock = $quantity_int;
                            }
                        }

                        // Add inventory log for the supplier transaction if inventory_logs table exists
                        if (table_exists($conn, 'inventory_logs') && isset($product_id)) {
                            $user_id_log = $_SESSION['user_id'] ?? 1;
                            $notes = 'Supply added by ' . $suppliername;
                            $log_sql = "INSERT INTO inventory_logs (product_id, users_id, quantity_change, previous_stock, new_stock, notes) VALUES ('$product_id', '$user_id_log', '+$quantity_int', '$prev_stock', '" . ($new_stock ?? $quantity_int) . "', '" . mysqli_real_escape_string($conn, $notes) . "')";
                            mysqli_query($conn, $log_sql);
                        }
                        // Re-query products to reflect changes on the page
                        $all_products = mysqli_query($conn, "
                            SELECT p.*, c.name as category_name 
                            FROM products p 
                            LEFT JOIN category c ON p.category_id = c.id 
                            ORDER BY p.created_at ASC
                        ");
                    }

                    header("Location: admin_dashboard.php?tab=suppliers&message=" . urlencode($message));
                    exit;
                } else {
                    $message = 'Error adding supplier: ' . mysqli_error($conn);
                }
            } catch (Exception $e) {
                $message = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['update_product_ajax'])) {
        header('Content-Type: application/json');
        try {
            $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
            $name = mysqli_real_escape_string($conn, $_POST['name']);
            $category_input = mysqli_real_escape_string($conn, $_POST['category_input']);
            $price = mysqli_real_escape_string($conn, $_POST['price']);
            $stock_quantity = intval($_POST['stock_quantity']);
            $description = mysqli_real_escape_string($conn, $_POST['description']);

            if (empty($name) || empty($price) || $stock_quantity < 0) {
                throw new Exception('Please fill all required fields correctly.');
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
                    }
                }
            }

            if ($category_id !== null) {
                $sql = "UPDATE products SET name='$name', price='$price', stock_quantity='$stock_quantity', 
                        description='$description', category_id='$category_id' WHERE id='$product_id'";
            } else {
                $sql = "UPDATE products SET name='$name', price='$price', stock_quantity='$stock_quantity', 
                        description='$description', category_id=NULL WHERE id='$product_id'";
            }
            
            if (mysqli_query($conn, $sql)) {
                $updated_product = mysqli_fetch_assoc(mysqli_query($conn, "
                    SELECT p.*, COALESCE(c.name, 'Uncategorized') as category_name 
                    FROM products p 
                    LEFT JOIN category c ON p.category_id = c.id 
                    WHERE p.id = '$product_id'
                "));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Product updated successfully!',
                    'product' => $updated_product
                ]);
            } else {
                throw new Exception('Error updating product: ' . mysqli_error($conn));
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['update_sale_ajax'])) {
        header('Content-Type: application/json');
        try {
            $sale_id = mysqli_real_escape_string($conn, $_POST['sale_id']);
            $buyername = mysqli_real_escape_string($conn, $_POST['buyername'] ?? '');
            $product_name = mysqli_real_escape_string($conn, $_POST['product_name'] ?? '');
            $quantity = intval($_POST['quantity'] ?? 0);
            $total_price = floatval($_POST['total_price'] ?? 0);
            $sale_date_input = $_POST['sale_date'] ?? '';

            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than zero.');
            } else if ($total_price <= 0) {
                throw new Exception('Total price must be greater than zero.');
            } else if (empty($product_name)) {
                throw new Exception('Product name is required.');
            }

            $sale_date = '';
            if (!empty($sale_date_input)) {
                $sale_date = date('Y-m-d H:i:s', strtotime($sale_date_input));
            } else {
                $sale_date = date('Y-m-d H:i:s');
            }

            $sale_check = mysqli_query($conn, "SELECT * FROM sales WHERE id='$sale_id'");
            if (mysqli_num_rows($sale_check) == 0) {
                throw new Exception('Sale not found.');
            }

            $total_column = 'total_amount';
            if (column_exists($conn, 'sales', 'total_price')) {
                $total_column = 'total_price';
            } elseif (column_exists($conn, 'sales', 'total_amount')) {
                $total_column = 'total_amount';
            }
            
            $buyer_column = '';
            if (column_exists($conn, 'sales', 'buyer_name')) {
                $buyer_column = 'buyer_name';
            } elseif (column_exists($conn, 'sales', 'buyername')) {
                $buyer_column = 'buyername';
            } elseif (column_exists($conn, 'sales', 'customer_name')) {
                $buyer_column = 'customer_name';
            }
            
            $date_column = '';
            if (column_exists($conn, 'sales', 'sale_date')) {
                $date_column = 'sale_date';
            } elseif (column_exists($conn, 'sales', 'created_at')) {
                $date_column = 'created_at';
            }

            $updates = ["quantity='$quantity'", "$total_column='$total_price'"];
            
            if (!empty($buyer_column)) {
                $updates[] = "$buyer_column='$buyername'";
            }
            
            if (!empty($date_column)) {
                $updates[] = "$date_column='$sale_date'";
            }
            
            if (column_exists($conn, 'sales', 'product_name')) {
                $updates[] = "product_name='$product_name'";
            }

            $sql = "UPDATE sales SET " . implode(', ', $updates) . " WHERE id='$sale_id'";

            if (mysqli_query($conn, $sql)) {
                $updated_sale = mysqli_fetch_assoc(mysqli_query($conn, "SELECT s.*, COALESCE(p.name, s.product_name) as product_name, u.username as user_name FROM sales s LEFT JOIN users u ON s.user_id = u.id LEFT JOIN products p ON s.product_id = p.id WHERE s.id='$sale_id'"));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Sale updated successfully!',
                    'sale' => [
                        'id' => $updated_sale['id'],
                        'product_name' => $updated_sale['product_name'] ?? 'Sale #' . $updated_sale['id'],
                        'buyer_name' => $updated_sale['buyer_name'] ?? $updated_sale['buyername'] ?? $updated_sale['customer_name'] ?? 'Not specified',
                        'quantity' => $updated_sale['quantity'],
                        'total_price' => $updated_sale['total_price'] ?? $updated_sale['total_amount'] ?? 0,
                        'sale_date' => $updated_sale['sale_date'] ?? $updated_sale['created_at'],
                        'user_name' => $updated_sale['user_name'] ?? 'Unknown'
                    ]
                ]);
            } else {
                throw new Exception('Error updating sale: ' . mysqli_error($conn));
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_SERVER["REQUEST_METHOD"] === 'POST' && isset($_POST['update_supplier_ajax'])) {
        header('Content-Type: application/json');
        try {
            $supplier_id = mysqli_real_escape_string($conn, $_POST['supplier_id']);
            $suppliername = mysqli_real_escape_string($conn, $_POST['suppliername'] ?? '');
            $suppliedproduct = mysqli_real_escape_string($conn, $_POST['suppliedproduct'] ?? '');
            $quantity = intval($_POST['quantity'] ?? 0);
            $price = floatval($_POST['price'] ?? 0);
            $supplier_date_input = $_POST['supplier_date'] ?? '';

            if (empty($suppliername)) {
                throw new Exception('Supplier name is required.');
            } else if (empty($suppliedproduct)) {
                throw new Exception('Supplied product is required.');
            } else if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than zero.');
            } else if ($price <= 0) {
                throw new Exception('Price must be greater than zero.');
            }

            $supplier_date = '';
            if (!empty($supplier_date_input)) {
                $supplier_date = date('Y-m-d H:i:s', strtotime($supplier_date_input));
            } else {
                $supplier_date = date('Y-m-d H:i:s');
            }

            $supplier_check = mysqli_query($conn, "SELECT * FROM supplier WHERE id='$supplier_id'");
            if (mysqli_num_rows($supplier_check) == 0) {
                throw new Exception('Supplier not found.');
            }

            $sql = "UPDATE supplier SET 
                    suppliername='$suppliername', 
                    suppliedproduct='$suppliedproduct', 
                    quantity='$quantity', 
                    price='$price', 
                    date='$supplier_date',
                    created_at='$supplier_date' 
                    WHERE id='$supplier_id'";

            if (mysqli_query($conn, $sql)) {
                $updated_supplier = mysqli_fetch_assoc(mysqli_query($conn, "SELECT s.* FROM supplier s WHERE s.id='$supplier_id'"));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Supplier updated successfully!',
                    'supplier' => $updated_supplier
                ]);
            } else {
                throw new Exception('Error updating supplier: ' . mysqli_error($conn));
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}

if (isset($_GET['delete_product'])) {
    $product_id = mysqli_real_escape_string($conn, $_GET['delete_product']);
    $delete_sql = "DELETE FROM products WHERE id='$product_id'";
    
    if (mysqli_query($conn, $delete_sql)) {
        $message = "Product deleted successfully!";
        $all_products = mysqli_query($conn, "
            SELECT p.*, c.name as category_name 
            FROM products p 
            LEFT JOIN category c ON p.category_id = c.id 
            ORDER BY p.created_at ASC
        ");
    } else {
        $message = "Error deleting product: " . mysqli_error($conn);
    }
}

if (isset($_GET['delete_sale']) && table_exists($conn, 'sales')) {
    $sale_id = mysqli_real_escape_string($conn, $_GET['delete_sale']);
    $delete_sql = "DELETE FROM sales WHERE id='$sale_id'";
    
    if (mysqli_query($conn, $delete_sql)) {
        $message = "Sale deleted successfully!";
        $all_sales = mysqli_query($conn, "
            SELECT s.*, u.username as user_name 
            FROM sales s 
            LEFT JOIN users u ON s.user_id = u.id 
            ORDER BY s.created_at DESC
        ");
    } else {
        $message = "Error deleting sale: " . mysqli_error($conn);
    }
}

if (isset($_GET['delete_supplier']) && table_exists($conn, 'supplier')) {
    $supplier_id = mysqli_real_escape_string($conn, $_GET['delete_supplier']);
    $delete_sql = "DELETE FROM supplier WHERE id='$supplier_id'";
    
    if (mysqli_query($conn, $delete_sql)) {
        $message = "Supplier deleted successfully!";
        $all_suppliers = mysqli_query($conn, "
            SELECT s.*, COALESCE(p.name, s.suppliedproduct) as suppliedproduct_name
            FROM supplier s
            LEFT JOIN products p ON LOWER(p.name) = LOWER(s.suppliedproduct)
            ORDER BY s.created_at DESC
        ");
    } else {
        $message = "Error deleting supplier: " . mysqli_error($conn);
    }
}

// Get products for sales dropdown
$available_products = mysqli_query($conn, "SELECT id, name, stock_quantity, price FROM products ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $dark_mode ? 'dark' : 'light' ?>">
<head>
    <title>Admin Dashboard - Inventory System</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* Enhanced CSS for better form styling */
        :root {
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

        /* Mobile menu toggle */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.6em;
            cursor: pointer;
            padding: 8px 10px;
            border-radius: 8px;
            color: var(--text-secondary);
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
        
        .stat-card.products { border-top: 4px solid #3498db; }
        .stat-card.sales { border-top: 4px solid #27ae60; }
        .stat-card.revenue { border-top: 4px solid #f39c12; }
        .stat-card.suppliers { border-top: 4px solid #9b59b6; }
        
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
        
        .sale-item, .product-item, .supplier-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sale-item:last-child, .product-item:last-child, .supplier-item:last-child {
            border-bottom: none;
        }
        
        .sale-info, .product-info, .supplier-info {
            flex: 1;
        }
        
        .sale-product, .product-name, .supplier-name {
            font-weight: bold;
            color: var(--text-primary);
        }
        
        .sale-details, .product-details, .supplier-details {
            color: var(--text-secondary);
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .sale-amount {
            font-weight: bold;
            color: #27ae60;
            font-size: 1.1em;
        }
        
        .table-container {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px var(--shadow-color);
            margin-bottom: 20px;
            overflow-x: auto;
            border: 1px solid var(--border-color);
        }
        
        .product-table, .sales-table, .suppliers-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .product-table th, .sales-table th, .suppliers-table th {
            background: #4853c8;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .product-table td, .sales-table td, .suppliers-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .product-table tr:hover, .sales-table tr:hover, .suppliers-table tr:hover {
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
        
        .btn-purple {
            background: #9b59b6;
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
        
        .action-btn.purple {
            background: #9b59b6;
            box-shadow: 0 4px 15px rgba(155, 89, 182, 0.3);
        }
        
        .action-btn.purple:hover {
            background: #8e44ad;
            box-shadow: 0 6px 20px rgba(155, 89, 182, 0.4);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons .btn {
            padding: 6px 12px;
            font-size: 11px;
        }

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
        
        .message.success {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid var(--success-border);
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

        .product-info, .supplier-info {
            background: var(--bg-tertiary);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #4853c8;
        }

        .product-info p, .supplier-info p {
            margin: 5px 0;
            color: var(--text-secondary);
        }

        .product-info strong, .supplier-info strong {
            color: var(--text-primary);
        }

        .form-help-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
            font-style: italic;
        }

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

        .new-product-highlight, .new-supplier-highlight {
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

        .product-name, .product-category, .product-price, .product-stock, .product-description,
        .supplier-name, .supplier-product, .supplier-quantity, .supplier-price {
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

        @media (max-width: 768px) {
            .sidebar {
                width: 80%;
                height: 100vh;
                position: fixed;
                left: -100%;
                top: 0;
                z-index: 1000;
                transition: left 0.25s ease-in-out;
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

            .menu-toggle {
                display: inline-flex;
                align-items: center;
            }

            /* When sidebar has .open class, bring it in */
            .sidebar.open {
                left: 0;
            }

            /* Header stacking on small screens */
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .header .user-info {
                display: flex;
                gap: 10px;
                width: 100%;
                justify-content: space-between;
            }

            .header h1 { font-size: 1.25em; }
            .stats-container { margin-bottom: 15px; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🏠 Admin Panel</h2>
            <button class="theme-toggle" id="themeToggle" title="Toggle Dark Mode">
                <?= $dark_mode ? '☀️' : '🌙' ?>
            </button>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?tab=dashboard" class="<?= $active_tab == 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a></li>
            <li><a href="?tab=products" class="<?= $active_tab == 'products' ? 'active' : '' ?>">📦 Products</a></li>
            <li><a href="?tab=sales" class="<?= $active_tab == 'sales' ? 'active' : '' ?>">💰 Sales Records</a></li>
            <li><a href="?tab=suppliers" class="<?= $active_tab == 'suppliers' ? 'active' : '' ?>">🏭 Suppliers</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header">
            <div class="header-content">
                <h1>
                    <?php 
                    switch($active_tab) {
                        case 'products': echo '📦 Products Management'; break;
                        case 'sales': echo '💰 Sales Records'; break;
                        case 'suppliers': echo '🏭 Suppliers Management'; break;
                        default: echo 'Admin Dashboard';
                    }
                    ?>
                </h1>
                <div class="welcome-message">
                    Welcome, <strong><?= $_SESSION["username"] ?></strong>!
                </div>
            </div>
            <div class="user-info">
                <button class="menu-toggle" id="sidebarToggle" aria-label="Toggle Sidebar">☰</button>
                <a href="logout.php" class="btn btn-danger" id="logoutBtn">Logout</a>
            </div>
        </div>

        <div class="toast-container" id="toastContainer"></div>

        <?php if ($message): ?>
            <div class="message <?= strpos($message, 'successfully') !== false ? 'success' : 'error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div id="dashboard" class="tab-content <?= $active_tab == 'dashboard' ? 'active' : '' ?>">
            <div class="stats-container">
                <div class="stat-card products">
                    <h3>Total Products</h3>
                    <div class="stat-number"><?= $total_products ?></div>
                </div>
                
                <div class="stat-card sales">
                    <h3>Total Sales</h3>
                    <div class="stat-number"><?= $total_sales ?></div>
                </div>
                
                <div class="stat-card revenue">
                    <h3>Total Revenue</h3>
                    <div class="stat-number">₱<?= number_format($total_revenue, 2) ?></div>
                </div>
                
                <div class="stat-card suppliers">
                    <h3>Total Suppliers</h3>
                    <div class="stat-number"><?= $total_suppliers ?></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <h3>💰 Recent Sales</h3>
                    <?php if (isset($recent_sales) && $recent_sales && mysqli_num_rows($recent_sales) > 0): ?>
                        <?php while($sale = mysqli_fetch_assoc($recent_sales)): ?>
                            <div class="sale-item">
                                <div class="sale-info">
                                    <div class="sale-product">
                                        <?= htmlspecialchars($sale['product_name'] ?? 'Sale #' . $sale['id']) ?>
                                    </div>
                                    <div class="sale-details">
                                        Buyer: <?= htmlspecialchars($sale['buyer_name'] ?? $sale['buyername'] ?? $sale['customer_name'] ?? 'Not specified') ?> | 
                                        Qty: <?= $sale['quantity'] ?> | 
                                        By: <?= htmlspecialchars($sale['user_name'] ?? 'Unknown') ?>
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
                    <h3>🏭 Recent Suppliers</h3>
                    <?php if (isset($recent_suppliers) && $recent_suppliers && mysqli_num_rows($recent_suppliers) > 0): ?>
                        <?php while($supplier = mysqli_fetch_assoc($recent_suppliers)): ?>
                            <div class="supplier-item">
                                <div class="supplier-info">
                                    <div class="supplier-name">
                                        <?= htmlspecialchars($supplier['suppliername']) ?>
                                    </div>
                                    <div class="supplier-details">
                                        Product: <?= htmlspecialchars($supplier['suppliedproduct']) ?> | 
                                        Qty: <?= $supplier['quantity'] ?>
                                    </div>
                                    <small class="date-info">
                                        Price: ₱<?= number_format($supplier['price'], 2) ?> | 
                                        Added: <?= date('M j, Y g:i A', strtotime($supplier['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <p>No suppliers recorded yet.</p>
                            <button type="button" class="btn btn-purple" onclick="switchTab('suppliers'); setTimeout(() => showAddSupplierModal(), 100);">
                                Add First Supplier
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="products" class="tab-content <?= $active_tab == 'products' ? 'active' : '' ?>">
            <button type="button" class="action-btn" onclick="showAddProductModal()">
                <span>➕</span>
                Add New Product
            </button>

            <div class="table-container">
                <h3>Product List (Total: <?= $total_products ?> products)</h3>
                
                <?php if (mysqli_num_rows($all_products) > 0): ?>
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php mysqli_data_seek($all_products, 0); ?>
                            <?php while($product = mysqli_fetch_assoc($all_products)): ?>
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
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn" onclick="showEditProductModal(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name']) ?>', '<?= htmlspecialchars($product['category_name'] ?? '') ?>', <?= $product['price'] ?>, <?= $product['stock_quantity'] ?>, '<?= htmlspecialchars($product['description']) ?>')">Edit</button>
                                            <button class="btn btn-danger" onclick="showDeleteToast(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name']) ?>')">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No products found. <a href="javascript:void(0)" onclick="showAddProductModal()">Add your first product</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="sales" class="tab-content <?= $active_tab == 'sales' ? 'active' : '' ?>">
            <button type="button" class="action-btn" onclick="showAddSaleModal()">
                <span>💰</span>
                Record New Sale
            </button>

            <div class="table-container">
                <h3>Sales Records (Total: <?= $total_sales ?> sales)</h3>
                
                <?php if (table_exists($conn, 'sales') && isset($all_sales) && mysqli_num_rows($all_sales) > 0): ?>
                    <table class="sales-table" id="salesTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Product</th>
                                <th>Buyer</th>
                                <th>Quantity</th>
                                <th>Total Amount</th>
                                <th>Recorded By</th>
                                <th>Date & Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="salesTableBody">
                            <?php mysqli_data_seek($all_sales, 0); ?>
                            <?php while($sale = mysqli_fetch_assoc($all_sales)): ?>
                                <tr id="sale-<?= $sale['id'] ?>">
                                    <td><strong>#<?= $sale['id'] ?></strong></td>
                                    <td class="sale-product-name"><?= htmlspecialchars($sale['product_name'] ?? 'Sale #' . $sale['id']) ?></td>
                                    <td class="sale-buyer-name"><?= htmlspecialchars($sale['buyer_name'] ?? $sale['buyername'] ?? $sale['customer_name'] ?? 'Not specified') ?></td>
                                    <td class="sale-quantity"><?= $sale['quantity'] ?></td>
                                    <td class="sale-amount-value"><strong>₱<?= number_format($sale['total_amount'] ?? $sale['total_price'] ?? 0, 2) ?></strong></td>
                                    <td class="sale-user-name"><?= htmlspecialchars($sale['user_name'] ?? 'Unknown') ?></td>
                                    <td class="sale-date"><?= date('M j, Y g:i A', strtotime($sale['sale_date'] ?? $sale['created_at'])) ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn" onclick="showEditSaleModal(
                                                <?= $sale['id'] ?>, 
                                                '<?= htmlspecialchars($sale['product_name'] ?? '') ?>', 
                                                '<?= htmlspecialchars($sale['buyer_name'] ?? $sale['buyername'] ?? $sale['customer_name'] ?? '') ?>', 
                                                <?= $sale['quantity'] ?>, 
                                                <?= $sale['total_amount'] ?? $sale['total_price'] ?? 0 ?>, 
                                                '<?= date('Y-m-d\TH:i', strtotime($sale['sale_date'] ?? $sale['created_at'])) ?>'
                                            )">Edit</button>
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
        </div>

        <div id="suppliers" class="tab-content <?= $active_tab == 'suppliers' ? 'active' : '' ?>">
            <button type="button" class="action-btn purple" onclick="showAddSupplierModal()">
                <span>🏭</span>
                Add New Supplier
            </button>

            <div class="table-container">
                <h3>Suppliers List (Total: <?= $total_suppliers ?> suppliers)</h3>
                
                <?php if (table_exists($conn, 'supplier') && isset($all_suppliers) && mysqli_num_rows($all_suppliers) > 0): ?>
                    <table class="suppliers-table" id="suppliersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Supplier Name</th>
                                <th>Supplied Product</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="suppliersTableBody">
                            <?php mysqli_data_seek($all_suppliers, 0); ?>
                            <?php while($supplier = mysqli_fetch_assoc($all_suppliers)): ?>
                                <tr id="supplier-<?= $supplier['id'] ?>">
                                    <td><strong>#<?= $supplier['id'] ?></strong></td>
                                    <td class="supplier-name"><?= htmlspecialchars($supplier['suppliername']) ?></td>
                                    <td class="supplier-product"><?= htmlspecialchars($supplier['suppliedproduct_name'] ?? $supplier['suppliedproduct']) ?></td>
                                    <td class="supplier-quantity"><?= $supplier['quantity'] ?></td>
                                    <td class="supplier-price">₱<?= number_format($supplier['price'], 2) ?></td>
                                    <td><?= date('M j, Y g:i A', strtotime($supplier['date'])) ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn" onclick="showEditSupplierModal(
                                                <?= $supplier['id'] ?>, 
                                                '<?= htmlspecialchars($supplier['suppliername']) ?>', 
                                                '<?= htmlspecialchars($supplier['suppliedproduct']) ?>', 
                                                <?= $supplier['quantity'] ?>, 
                                                <?= $supplier['price'] ?>, 
                                                '<?= date('Y-m-d\TH:i', strtotime($supplier['date'])) ?>'
                                            )">Edit</button>
                                            <button class="btn btn-danger" onclick="showDeleteSupplierToast(<?= $supplier['id'] ?>, '<?= htmlspecialchars($supplier['suppliername']) ?>')">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <p>No suppliers found. <a href="javascript:void(0)" onclick="showAddSupplierModal()">Add your first supplier</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Enhanced Add Sale Modal with Product Selection (like user dashboard) -->
    <div id="addSaleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>💳 Record New Sale</h3>
                <button class="close-btn" onclick="hideAddSaleModal()">&times;</button>
            </div>
            
            <div class="form-container">
                <form action="" method="POST" id="addSaleForm">
                    <input type="hidden" name="add_sale" value="1">
                    <!-- Product Selection Section -->
                    <div class="form-section">
                        <h4>📦 Product Selection</h4>
                        
                        <div class="product-selection">
                            <select name="product_id" class="modern-select" id="productSelect" onchange="updateProductInfo()" required>
                                <option value="">Select from existing products...</option>
                                <?php 
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
                        <button type="submit" class="btn-primary" id="submitBtn">
                            <span>💾</span> Record Sale
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Sale Modal -->
    <div id="editSaleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ Edit Sale Record</h3>
                <button class="close-btn" onclick="hideEditSaleModal()">&times;</button>
            </div>
            <form id="editSaleForm">
                <input type="hidden" name="sale_id" id="editSaleId">
                
                <div class="product-info">
                    <p><strong>Sale ID:</strong> #<span id="editSaleIdDisplay"></span></p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Buyer Name (Optional)</label>
                        <input type="text" name="buyername" id="editSaleBuyer" placeholder="Enter buyer name">
                    </div>
                    <div class="form-group">
                        <label>Product Purchased *</label>
                        <input type="text" name="product_name" id="editSaleProduct" placeholder="Enter product name" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" id="editSaleQuantity" min="1" placeholder="Enter quantity" required>
                    </div>
                    <div class="form-group">
                        <label>Total Price (₱) *</label>
                        <input type="number" name="total_price" id="editSaleTotalPrice" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Sale Date and Time *</label>
                    <input type="datetime-local" name="sale_date" id="editSaleDate" required>
                    <div class="form-help-text">Select the date and time when this sale occurred</div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="hideEditSaleModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Sale</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div id="addProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>➕ Add New Product</h3>
                <button class="close-btn" onclick="hideAddProductModal()">&times;</button>
            </div>
            <form action="" method="POST" id="addProductForm">
                <input type="hidden" name="add_product" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" placeholder="Enter product name" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <div class="category-input-container">
                            <select name="category_input" id="categoryInput" class="modern-select" required>
                                <option value="">Select a category...</option>
                                <?php if (isset($categories) && $categories): ?>
                                    <?php mysqli_data_seek($categories, 0); ?>
                                    <?php while($cat = mysqli_fetch_assoc($categories)): ?>
                                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-help-text">Or type a new category name below</div>
                            <input type="text" name="new_category" id="newCategoryInput" placeholder="Or type new category name" 
                                   style="margin-top: 8px;" oninput="toggleCategorySelect()">
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Price (₱) *</label>
                        <input type="number" name="price" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Stock Quantity *</label>
                        <input type="number" name="stock_quantity" placeholder="0" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Date *</label>
                        <input type="datetime-local" name="product_date" id="productDate" required>
                        <div class="form-help-text">Select the date and time when this product was recorded</div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" placeholder="Enter product description..." rows="3"></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="hideAddProductModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="editProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ Edit Product</h3>
                <button class="close-btn" onclick="hideEditProductModal()">&times;</button>
            </div>
            <form id="editProductForm">
                <input type="hidden" name="product_id" id="editProductId">
                
                <div class="product-info">
                    <p><strong>Product ID:</strong> #<span id="editProductIdDisplay"></span></p>
                    <p><strong>Date Recorded:</strong> <span id="editProductDate"></span></p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" id="editProductName" placeholder="Enter product name" required>
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <div class="category-input-container">
                            <select name="category_input" id="editCategoryInput" class="modern-select" required>
                                <option value="">Select a category...</option>
                                <?php if (isset($categories) && $categories): ?>
                                    <?php mysqli_data_seek($categories, 0); ?>
                                    <?php while($cat = mysqli_fetch_assoc($categories)): ?>
                                        <option value="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-help-text">Or type a new category name below</div>
                            <input type="text" name="new_category" id="editNewCategoryInput" placeholder="Or type new category name" 
                                   style="margin-top: 8px;" oninput="toggleEditCategorySelect()">
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Price (₱) *</label>
                        <input type="number" name="price" id="editProductPrice" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Stock Quantity *</label>
                        <input type="number" name="stock_quantity" id="editProductStock" placeholder="0" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="editProductDescription" placeholder="Enter product description..." rows="4"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="hideEditProductModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Supplier Modal -->
    <div id="addSupplierModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🏭 Add New Supplier</h3>
                <button class="close-btn" onclick="hideAddSupplierModal()">&times;</button>
            </div>
            <form action="" method="POST" id="addSupplierForm">
                <input type="hidden" name="add_supplier" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>Supplier Name *</label>
                        <input type="text" name="suppliername" placeholder="Enter supplier name" required>
                    </div>
                    <div class="form-group">
                        <label>Supplied Product *</label>
                        <input type="text" name="suppliedproduct" placeholder="Enter supplied product" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" min="1" placeholder="Enter quantity" required>
                    </div>
                    <div class="form-group">
                        <label>Price (₱) *</label>
                        <input type="number" name="price" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Supplier Date *</label>
                    <input type="datetime-local" name="supplier_date" id="supplierDate" required>
                    <div class="form-help-text">Select the date and time when this supplier was recorded</div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="hideAddSupplierModal()">Cancel</button>
                    <button type="submit" class="btn btn-purple">Add Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Supplier Modal -->
    <div id="editSupplierModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ Edit Supplier</h3>
                <button class="close-btn" onclick="hideEditSupplierModal()">&times;</button>
            </div>
            <form id="editSupplierForm">
                <input type="hidden" name="supplier_id" id="editSupplierId">
                
                <div class="supplier-info">
                    <p><strong>Supplier ID:</strong> #<span id="editSupplierIdDisplay"></span></p>
                    <p><strong>Date Added:</strong> <span id="editSupplierDateDisplay"></span></p>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Supplier Name *</label>
                        <input type="text" name="suppliername" id="editSupplierName" placeholder="Enter supplier name" required>
                    </div>
                    <div class="form-group">
                        <label>Supplied Product *</label>
                        <input type="text" name="suppliedproduct" id="editSupplierProduct" placeholder="Enter supplied product" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" id="editSupplierQuantity" min="1" placeholder="Enter quantity" required>
                    </div>
                    <div class="form-group">
                        <label>Price (₱) *</label>
                        <input type="number" name="price" id="editSupplierPrice" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Supplier Date *</label>
                    <input type="datetime-local" name="supplier_date" id="editSupplierDateInput" required>
                    <div class="form-help-text">Select the date and time when this supplier was recorded</div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="hideEditSupplierModal()">Cancel</button>
                    <button type="submit" class="btn btn-purple">Update Supplier</button>
                </div>
            </form>
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
        }

        // Initialize theme toggle
        document.getElementById('themeToggle').addEventListener('click', toggleTheme);

        // Sidebar toggle for mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            if (!sidebar) return;
            sidebar.classList.toggle('open');
        }

        // Attach sidebar toggle event
        const sidebarToggleBtn = document.getElementById('sidebarToggle');
        if (sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', toggleSidebar);
        }

        // Close sidebar when clicking a menu item on mobile
        const sidebarMenuLinks = document.querySelectorAll('.sidebar-menu a');
        sidebarMenuLinks.forEach(link => {
            link.addEventListener('click', function() {
                const sidebar = document.querySelector('.sidebar');
                if (window.innerWidth <= 768 && sidebar) sidebar.classList.remove('open');
            });
        });

        // Close sidebar when clicking outside of it (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            if (!sidebar) return;
            const isSidebarOpen = sidebar.classList.contains('open');
            const clickedInsideSidebar = !!e.target.closest('.sidebar');
            const clickedSidebarToggle = !!e.target.closest('#sidebarToggle');
            if (window.innerWidth <= 768 && isSidebarOpen && !clickedInsideSidebar && !clickedSidebarToggle) {
                sidebar.classList.remove('open');
            }
        });

        // Remove sidebar open class on larger screens
        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        });

        // Category selection toggle functions
        function toggleCategorySelect() {
            const newCategoryInput = document.getElementById('newCategoryInput');
            const categorySelect = document.getElementById('categoryInput');
            
            if (newCategoryInput.value.trim() !== '') {
                categorySelect.disabled = true;
                categorySelect.required = false;
                newCategoryInput.required = true;
            } else {
                categorySelect.disabled = false;
                categorySelect.required = true;
                newCategoryInput.required = false;
            }
        }

        function toggleEditCategorySelect() {
            const newCategoryInput = document.getElementById('editNewCategoryInput');
            const categorySelect = document.getElementById('editCategoryInput');
            
            if (newCategoryInput.value.trim() !== '') {
                categorySelect.disabled = true;
                categorySelect.required = false;
                newCategoryInput.required = true;
            } else {
                categorySelect.disabled = false;
                categorySelect.required = true;
                newCategoryInput.required = false;
            }
        }

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
            
            const productDateInput = document.getElementById('productDate');
            if (productDateInput) productDateInput.value = localDateTime;

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

        function showAddProductModal() {
            document.getElementById('addProductModal').style.display = 'block';
            // Set current date and time as default
            const now = new Date();
            const localDateTime = now.toISOString().slice(0, 16);
            document.getElementById('productDate').value = localDateTime;
            
            // Reset category inputs
            document.getElementById('categoryInput').disabled = false;
            document.getElementById('categoryInput').required = true;
            document.getElementById('newCategoryInput').required = false;
            document.getElementById('newCategoryInput').value = '';
        }

        function hideAddProductModal() {
            document.getElementById('addProductModal').style.display = 'none';
        }

        function showAddSupplierModal() {
            document.getElementById('addSupplierModal').style.display = 'block';
            const now = new Date();
            const localDateTime = now.toISOString().slice(0, 16);
            document.getElementById('supplierDate').value = localDateTime;
        }

        function hideAddSupplierModal() {
            document.getElementById('addSupplierModal').style.display = 'none';
        }

        function showEditProductModal(productId, productName, categoryName, price, stockQuantity, description) {
            // Populate the form with existing data
            document.getElementById('editProductId').value = productId;
            document.getElementById('editProductIdDisplay').textContent = productId;
            document.getElementById('editProductName').value = productName;
            document.getElementById('editProductPrice').value = price;
            document.getElementById('editProductStock').value = stockQuantity;
            document.getElementById('editProductDescription').value = description || '';
            
            // Set category - check if it exists in dropdown, otherwise use new category input
            const categorySelect = document.getElementById('editCategoryInput');
            const newCategoryInput = document.getElementById('editNewCategoryInput');
            
            let categoryExists = false;
            for (let i = 0; i < categorySelect.options.length; i++) {
                if (categorySelect.options[i].value === categoryName) {
                    categorySelect.value = categoryName;
                    categoryExists = true;
                    break;
                }
            }
            
            if (!categoryExists && categoryName) {
                newCategoryInput.value = categoryName;
                categorySelect.disabled = true;
                categorySelect.required = false;
                newCategoryInput.required = true;
            } else {
                newCategoryInput.value = '';
                categorySelect.disabled = false;
                categorySelect.required = true;
                newCategoryInput.required = false;
            }
            
            // Set current date for display
            const now = new Date();
            document.getElementById('editProductDate').textContent = now.toLocaleString();
            
            // Show the modal
            document.getElementById('editProductModal').style.display = 'block';
        }

        function hideEditProductModal() {
            document.getElementById('editProductModal').style.display = 'none';
        }

        function showEditSaleModal(saleId, productName, buyerName, quantity, totalPrice, saleDate) {
            document.getElementById('editSaleId').value = saleId;
            document.getElementById('editSaleIdDisplay').textContent = saleId;
            document.getElementById('editSaleProduct').value = productName || '';
            document.getElementById('editSaleBuyer').value = buyerName || '';
            document.getElementById('editSaleQuantity').value = quantity;
            document.getElementById('editSaleTotalPrice').value = totalPrice;
            document.getElementById('editSaleDate').value = saleDate;
            document.getElementById('editSaleModal').style.display = 'block';
        }

        function hideEditSaleModal() {
            document.getElementById('editSaleModal').style.display = 'none';
        }

        function showEditSupplierModal(supplierId, supplierName, suppliedProduct, quantity, price, supplierDate) {
            document.getElementById('editSupplierId').value = supplierId;
            document.getElementById('editSupplierIdDisplay').textContent = supplierId;
            document.getElementById('editSupplierName').value = supplierName;
            document.getElementById('editSupplierProduct').value = suppliedProduct;
            document.getElementById('editSupplierQuantity').value = quantity;
            document.getElementById('editSupplierPrice').value = price;
            // Set the input value and the display text (separate elements)
            const inputEl = document.getElementById('editSupplierDateInput');
            const displayEl = document.getElementById('editSupplierDateDisplay');
            if (inputEl) inputEl.value = supplierDate;
            if (displayEl) displayEl.textContent = supplierDate ? new Date(supplierDate).toLocaleString() : '';

            document.getElementById('editSupplierModal').style.display = 'block';
        }

        function hideEditSupplierModal() {
            document.getElementById('editSupplierModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['addSaleModal', 'addProductModal', 'editProductModal', 'editSaleModal', 'addSupplierModal', 'editSupplierModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    if (modalId === 'addSaleModal') hideAddSaleModal();
                    if (modalId === 'addProductModal') hideAddProductModal();
                    if (modalId === 'editProductModal') hideEditProductModal();
                    if (modalId === 'editSaleModal') hideEditSaleModal();
                    if (modalId === 'addSupplierModal') hideAddSupplierModal();
                    if (modalId === 'editSupplierModal') hideEditSupplierModal();
                }
            });
        }

        // Update product information when product selection changes
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
            
            // Validate stock for existing products - FIXED: Always use CSS variables for colors
            if (productSelect.value) {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const stock = parseInt(selectedOption.getAttribute('data-stock'));
                
                if (quantity > stock) {
                    quantityInput.style.borderColor = '#ef4444'; // Keep red for error
                    quantityInput.style.backgroundColor = 'var(--error-bg)'; // Use CSS variable
                } else {
                    quantityInput.style.borderColor = 'var(--border-color)'; // Reset to default border
                    quantityInput.style.backgroundColor = 'var(--input-bg)'; // Reset to default background
                }
            } else {
                // Reset to default styles using CSS variables
                quantityInput.style.borderColor = 'var(--border-color)';
                quantityInput.style.backgroundColor = 'var(--input-bg)';
            }
            
            updateSummary();
        }

        function updateProductInfo() {
            const productSelect = document.getElementById('productSelect');
            const stockDisplay = document.getElementById('stockDisplay');
            const unitPriceInfo = document.getElementById('unitPriceInfo');
            const stockStatus = document.getElementById('stockStatus');
            const summaryProduct = document.getElementById('summaryProduct');
            const unitPriceInput = document.getElementById('unitPriceInput');
            const quantityInput = document.getElementById('quantityInput');
            
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

                // Reset quantity input styles when product changes - FIXED: Always use CSS variables
                if (quantityInput) {
                    quantityInput.style.borderColor = 'var(--border-color)';
                    quantityInput.style.backgroundColor = 'var(--input-bg)';
                }

                // Auto-calculate total if quantity is entered
                calculateTotalPrice();
            } else {
                // No product selected - reset all styles
                stockDisplay.textContent = '-';
                unitPriceInfo.textContent = '-';
                stockStatus.textContent = '-';
                summaryProduct.textContent = '-';
                
                if (unitPriceInput) unitPriceInput.value = '';
                if (quantityInput) {
                    quantityInput.style.borderColor = 'var(--border-color)';
                    quantityInput.style.backgroundColor = 'var(--input-bg)';
                }
            }
            updateSummary();
        }

        // Also update the resetForm function to properly reset styles
        function resetForm() {
            const productSelect = document.getElementById('productSelect');
            const quantityInput = document.getElementById('quantityInput');
            const totalPriceInput = document.getElementById('totalPriceInput');
            const unitPriceInput = document.getElementById('unitPriceInput');
            
            productSelect.value = '';
            quantityInput.value = '';
            totalPriceInput.value = '';
            unitPriceInput.value = '';
            
            // Reset input styles using CSS variables
            quantityInput.style.borderColor = 'var(--border-color)';
            quantityInput.style.backgroundColor = 'var(--input-bg)';
            
            document.querySelector('input[name="buyername"]').value = '';
            
            updateProductInfo();
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

        // Delete Product Toast
        function showDeleteToast(productId, productName) {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = 'toast warning';
            toast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">⚠️</span>
                    <span class="toast-message">Are you sure you want to delete "<strong>${productName}</strong>"?</span>
                </div>
                <div class="toast-actions">
                    <button class="toast-btn cancel" onclick="hideToast(this)">Cancel</button>
                    <button class="toast-btn confirm" onclick="confirmDelete(${productId}, '${productName.replace(/'/g, "\\'")}')">Yes, Delete</button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    hideToast(toast);
                }
            }, 10000);
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

        // Delete Supplier Toast
        function showDeleteSupplierToast(supplierId, supplierName) {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = 'toast warning';
            toast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">⚠️</span>
                    <span class="toast-message">Are you sure you want to delete "<strong>${supplierName}</strong>"?</span>
                </div>
                <div class="toast-actions">
                    <button class="toast-btn cancel" onclick="hideToast(this)">Cancel</button>
                    <button class="toast-btn confirm" onclick="confirmDeleteSupplier(${supplierId}, '${supplierName.replace(/'/g, "\\'")}')">Yes, Delete</button>
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

        function confirmDelete(productId, productName) {
            // Show success toast first
            const toastContainer = document.getElementById('toastContainer');
            
            const successToast = document.createElement('div');
            successToast.className = 'toast success';
            successToast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">✅</span>
                    <span class="toast-message">Product "${productName}" deleted successfully!</span>
                </div>
            `;
            
            toastContainer.appendChild(successToast);
            
            // Remove the product row from the table with animation
            const productRow = document.getElementById('product-' + productId);
            if (productRow) {
                productRow.style.transition = 'all 0.3s ease';
                productRow.style.opacity = '0';
                productRow.style.transform = 'translateX(-100%)';
                
                setTimeout(() => {
                    productRow.remove();
                    // Update total products count
                    const totalProductsElement = document.querySelector('.table-container h3');
                    if (totalProductsElement) {
                        const currentCount = parseInt(totalProductsElement.textContent.match(/\d+/)[0]);
                        totalProductsElement.textContent = totalProductsElement.textContent.replace(/\d+/, currentCount - 1);
                    }
                }, 300);
            }
            
            // Redirect to delete the product from database
            setTimeout(() => {
                window.location.href = '?tab=products&delete_product=' + productId;
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

        function confirmDeleteSupplier(supplierId, supplierName) {
            const toastContainer = document.getElementById('toastContainer');
            
            const successToast = document.createElement('div');
            successToast.className = 'toast success';
            successToast.innerHTML = `
                <div class="toast-content">
                    <span class="toast-icon">✅</span>
                    <span class="toast-message">Supplier "${supplierName}" deleted successfully!</span>
                </div>
            `;
            
            toastContainer.appendChild(successToast);
            
            const supplierRow = document.getElementById('supplier-' + supplierId);
            if (supplierRow) {
                supplierRow.style.transition = 'all 0.3s ease';
                supplierRow.style.opacity = '0';
                supplierRow.style.transform = 'translateX(-100%)';
                
                setTimeout(() => {
                    supplierRow.remove();
                    const totalSuppliersElement = document.querySelector('#suppliers .table-container h3');
                    if (totalSuppliersElement) {
                        const currentCount = parseInt(totalSuppliersElement.textContent.match(/\d+/)[0]);
                        totalSuppliersElement.textContent = totalSuppliersElement.textContent.replace(/\d+/, currentCount - 1);
                    }
                }, 300);
            }
            
            setTimeout(() => {
                window.location.href = '?tab=suppliers&delete_supplier=' + supplierId;
            }, 1000);
            
            const toasts = toastContainer.querySelectorAll('.toast');
            toasts.forEach(t => {
                if (t !== successToast) {
                    hideToast(t);
                }
            });
            
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

        // AJAX form submissions
        document.getElementById('editProductForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('update_product_ajax', '1');
            
            // Use new category if provided, otherwise use selected category
            const newCategoryInput = document.getElementById('editNewCategoryInput');
            const categorySelect = document.getElementById('editCategoryInput');
            
            if (newCategoryInput.value.trim() !== '') {
                formData.set('category_input', newCategoryInput.value.trim());
            } else {
                formData.set('category_input', categorySelect.value);
            }
            
            const productId = document.getElementById('editProductId').value;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Updating...';
            submitBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response received:', data);
                
                if (data.success) {
                    updateProductRow(productId, data.product);
                    showSuccessToast(data.message || 'Product updated successfully!');
                    setTimeout(() => {
                        hideEditProductModal();
                    }, 1500);
                } else {
                    showErrorToast(data.message || 'Error updating product');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorToast('Network error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });

        document.getElementById('editSaleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('update_sale_ajax', '1');
            
            const saleId = document.getElementById('editSaleId').value;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Updating...';
            submitBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response received:', data);
                
                if (data.success) {
                    updateSaleRow(saleId, data.sale);
                    showSuccessToast(data.message || 'Sale updated successfully!');
                    setTimeout(() => {
                        hideEditSaleModal();
                    }, 1500);
                } else {
                    showErrorToast(data.message || 'Error updating sale');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorToast('Network error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });

        document.getElementById('editSupplierForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('update_supplier_ajax', '1');
            
            const supplierId = document.getElementById('editSupplierId').value;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.textContent = 'Updating...';
            submitBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response received:', data);
                
                if (data.success) {
                    updateSupplierRow(supplierId, data.supplier);
                    showSuccessToast(data.message || 'Supplier updated successfully!');
                    setTimeout(() => {
                        hideEditSupplierModal();
                    }, 1500);
                } else {
                    showErrorToast(data.message || 'Error updating supplier');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorToast('Network error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });

        function updateProductRow(productId, productData) {
            const row = document.getElementById('product-' + productId);
            if (!row) {
                console.error('Product row not found for ID:', productId);
                return;
            }
            
            try {
                if (row.querySelector('.product-name')) {
                    row.querySelector('.product-name').textContent = productData.name;
                }
                if (row.querySelector('.product-category')) {
                    row.querySelector('.product-category').textContent = productData.category_name || 'Uncategorized';
                }
                if (row.querySelector('.product-price')) {
                    row.querySelector('.product-price').textContent = '₱' + parseFloat(productData.price).toFixed(2);
                }
                if (row.querySelector('.product-description')) {
                    row.querySelector('.product-description').textContent = productData.description || '';
                }
                
                const stockElement = row.querySelector('.product-stock-value');
                if (stockElement) {
                    stockElement.setAttribute('data-stock', productData.stock_quantity);
                    
                    let stockClass = '';
                    let stockText = '';
                    
                    if (productData.stock_quantity == 0) {
                        stockClass = 'stock-warning';
                        stockText = 'Out of Stock';
                    } else if (productData.stock_quantity < 10) {
                        stockClass = 'stock-low';
                        stockText = 'Low Stock';
                    } else {
                        stockClass = 'stock-good';
                        stockText = productData.stock_quantity.toString();
                    }
                    
                    stockElement.className = stockClass + ' product-stock-value';
                    stockElement.textContent = stockText;
                }
                
                const editButton = row.querySelector('.action-buttons .btn:first-child');
                if (editButton) {
                    editButton.setAttribute('onclick', `showEditProductModal(${productId}, '${productData.name.replace(/'/g, "\\'")}', '${(productData.category_name || '').replace(/'/g, "\\'")}', ${productData.price}, ${productData.stock_quantity}, '${(productData.description || '').replace(/'/g, "\\'")}')`);
                }
                
                const deleteButton = row.querySelector('.btn-danger');
                if (deleteButton) {
                    deleteButton.setAttribute('onclick', `showDeleteToast(${productId}, '${productData.name.replace(/'/g, "\\'")}')`);
                }
                
                row.classList.add('update-highlight');
                setTimeout(() => {
                    row.classList.remove('update-highlight');
                }, 2000);
            } catch (error) {
                console.error('Error updating product row:', error);
                showErrorToast('Error updating product display');
            }
        }

        function updateSaleRow(saleId, saleData) {
            const row = document.getElementById('sale-' + saleId);
            if (!row) {
                console.error('Sale row not found for ID:', saleId);
                return;
            }
            
            try {
                if (row.querySelector('.sale-product-name')) {
                    row.querySelector('.sale-product-name').textContent = saleData.product_name;
                }
                if (row.querySelector('.sale-buyer-name')) {
                    row.querySelector('.sale-buyer-name').textContent = saleData.buyer_name;
                }
                if (row.querySelector('.sale-quantity')) {
                    row.querySelector('.sale-quantity').textContent = saleData.quantity;
                }
                if (row.querySelector('.sale-amount-value')) {
                    row.querySelector('.sale-amount-value').innerHTML = '<strong>₱' + parseFloat(saleData.total_price).toFixed(2) + '</strong>';
                }
                if (row.querySelector('.sale-user-name')) {
                    row.querySelector('.sale-user-name').textContent = saleData.user_name || 'Unknown';
                }
                if (row.querySelector('.sale-date')) {
                    const saleDate = new Date(saleData.sale_date);
                    row.querySelector('.sale-date').textContent = saleDate.toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric', 
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true 
                    });
                }
                
                const editButton = row.querySelector('.action-buttons .btn:first-child');
                if (editButton) {
                    const saleDateFormatted = new Date(saleData.sale_date).toISOString().slice(0, 16);
                    editButton.setAttribute('onclick', `showEditSaleModal(${saleId}, '${saleData.product_name.replace(/'/g, "\\'")}', '${saleData.buyer_name.replace(/'/g, "\\'")}', ${saleData.quantity}, ${saleData.total_price}, '${saleDateFormatted}')`);
                }
                
                row.classList.add('update-highlight');
                setTimeout(() => {
                    row.classList.remove('update-highlight');
                }, 2000);
            } catch (error) {
                console.error('Error updating sale row:', error);
                showErrorToast('Error updating sale display');
            }
        }

        function updateSupplierRow(supplierId, supplierData) {
            const row = document.getElementById('supplier-' + supplierId);
            if (!row) {
                console.error('Supplier row not found for ID:', supplierId);
                return;
            }
            
            try {
                if (row.querySelector('.supplier-name')) {
                    row.querySelector('.supplier-name').textContent = supplierData.suppliername;
                }
                if (row.querySelector('.supplier-product')) {
                    row.querySelector('.supplier-product').textContent = supplierData.suppliedproduct;
                }
                if (row.querySelector('.supplier-quantity')) {
                    row.querySelector('.supplier-quantity').textContent = supplierData.quantity;
                }
                if (row.querySelector('.supplier-price')) {
                    row.querySelector('.supplier-price').textContent = '₱' + parseFloat(supplierData.price).toFixed(2);
                }
                if (row.querySelector('.supplier-date')) {
                    const supplierDate = new Date(supplierData.date);
                    row.querySelector('.supplier-date').textContent = supplierDate.toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric', 
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true 
                    });
                }
                
                const editButton = row.querySelector('.action-buttons .btn:first-child');
                if (editButton) {
                    const supplierDateFormatted = new Date(supplierData.date).toISOString().slice(0, 16);
                    editButton.setAttribute('onclick', `showEditSupplierModal(${supplierId}, '${supplierData.suppliername.replace(/'/g, "\\'")}', '${supplierData.suppliedproduct.replace(/'/g, "\\'")}', ${supplierData.quantity}, ${supplierData.price}, '${supplierDateFormatted}')`);
                }
                
                const deleteButton = row.querySelector('.btn-danger');
                if (deleteButton) {
                    deleteButton.setAttribute('onclick', `showDeleteSupplierToast(${supplierId}, '${supplierData.suppliername.replace(/'/g, "\\'")}')`);
                }
                
                row.classList.add('update-highlight');
                setTimeout(() => {
                    row.classList.remove('update-highlight');
                }, 2000);
            } catch (error) {
                console.error('Error updating supplier row:', error);
                showErrorToast('Error updating supplier display');
            }
        }

        // UX: disable submit and show processing toast for add forms to reduce perceived delays
        function handleFormProcessing(form, message) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.textContent;
                submitBtn.textContent = message || 'Processing...';
                // Restore original button text after a tiny delay if needed
                const timer = setTimeout(() => {
                    submitBtn.textContent = originalText;
                }, 3000);

                return function restore() {
                    clearTimeout(timer);
                    submitBtn.textContent = originalText;
                };
            }
            return function () {};
        }

        // UX: add processing handlers to add forms
        const addSaleFormEl = document.getElementById('addSaleForm');
        if (addSaleFormEl) {
            addSaleFormEl.addEventListener('submit', function(e) {
                // only show processing when form is valid
                if (!this.checkValidity()) return;
                handleFormProcessing(this, 'Recording...');
                showSuccessToast('Processing sale...');
            });
        }
        const addProductFormEl = document.getElementById('addProductForm');
        if (addProductFormEl) {
            addProductFormEl.addEventListener('submit', function(e) {
                if (!this.checkValidity()) return;
                handleFormProcessing(this, 'Saving...');
                showSuccessToast('Saving product...');
            });
        }
        const addSupplierFormEl = document.getElementById('addSupplierForm');
        if (addSupplierFormEl) {
            addSupplierFormEl.addEventListener('submit', function(e) {
                if (!this.checkValidity()) return;
                handleFormProcessing(this, 'Saving...');
                showSuccessToast('Saving supplier...');
            });
        }

        // Form validation for sales
        document.getElementById('addSaleForm')?.addEventListener('submit', function(e) {
            const quantity = this.querySelector('input[name="quantity"]').value;
            const totalPrice = this.querySelector('input[name="total_price"]').value;
            const productId = this.querySelector('select[name="product_id"]').value;
            
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

        // Form validation for product category
        document.getElementById('addProductForm')?.addEventListener('submit', function(e) {
            const categorySelect = this.querySelector('#categoryInput');
            const newCategoryInput = this.querySelector('#newCategoryInput');
            
            if (categorySelect.disabled && newCategoryInput.value.trim() === '') {
                showErrorToast('Please enter a category name or select from the dropdown.');
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
            
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('tab') && urlParams.get('tab') === 'products') {
                const successMessage = document.querySelector('.message.success');
                if (successMessage && successMessage.textContent.includes('added')) {
                    const rows = document.querySelectorAll('#productsTableBody tr');
                    if (rows.length > 0) {
                        const lastRow = rows[rows.length - 1];
                        lastRow.classList.add('new-product-highlight');
                    }
                }
            }
            
            if (urlParams.has('tab') && urlParams.get('tab') === 'suppliers') {
                const successMessage = document.querySelector('.message.success');
                if (successMessage && successMessage.textContent.includes('added')) {
                    const rows = document.querySelectorAll('#suppliersTableBody tr');
                    if (rows.length > 0) {
                        const lastRow = rows[rows.length - 1];
                        lastRow.classList.add('new-supplier-highlight');
                    }
                }
            }
        });

        document.addEventListener('click', function(event) {
            const toastContainer = document.getElementById('toastContainer');
            const toasts = toastContainer.querySelectorAll('.toast');
            
            toasts.forEach(toast => {
                if (!toast.contains(event.target) && !event.target.matches('.btn-danger')) {
                    hideToast(toast);
                }
            });
        });
    </script>
</body>
</html>