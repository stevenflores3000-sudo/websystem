<?php
session_start();
include 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $admin_id = strtolower(trim($_POST['admin_id']));
    $password = $_POST['password'];

    // 1. Automatically create the admin table if it doesn't exist
    $createTableQuery = "CREATE TABLE IF NOT EXISTS admin (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id VARCHAR(50) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL
    )";
    $conn->query($createTableQuery);

    // 1.5. Patch the table if it was created previously without the 'name' column
    $checkColumn = $conn->query("SHOW COLUMNS FROM admin LIKE 'name'");
    if ($checkColumn && $checkColumn->num_rows == 0) {
        $conn->query("ALTER TABLE admin ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT 'System Administrator' AFTER admin_id");
    }

    // 2. Automatically insert the default admin if it doesn't exist
    $checkAdmin = $conn->query("SELECT * FROM admin WHERE admin_id = 'admin'");
    if ($checkAdmin && $checkAdmin->num_rows == 0) {
        $default_hash = password_hash('admin123', PASSWORD_DEFAULT);
        
        // Check if an 'id' column exists to provide a fallback value. 
        // This prevents "Duplicate entry ''" errors if the table is missing AUTO_INCREMENT.
        $checkId = $conn->query("SHOW COLUMNS FROM admin LIKE 'id'");
        if ($checkId && $checkId->num_rows > 0) {
            $fallback_id = mt_rand(100000, 999999);
            $conn->query("INSERT INTO admin (id, admin_id, name, password) VALUES ('$fallback_id', 'admin', 'System Administrator', '$default_hash')");
        } else {
            $conn->query("INSERT INTO admin (admin_id, name, password) VALUES ('admin', 'System Administrator', '$default_hash')");
        }
    }

    // 3. Verify Admin Credentials
    // Verify Admin Credentials against the database
    $stmt = $conn->prepare("SELECT * FROM admin WHERE admin_id = ?");
    $stmt->bind_param('s', $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();

        if (password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            // Set session variables
            $_SESSION['user_id']   = $admin['id'];
            $_SESSION['admin_id']  = $admin['admin_id'];
            $_SESSION['user_name'] = $admin['name'];
            $_SESSION['role']      = 'admin';

            $stmt->close();
            header("Location: index.html?admin=success&name=" . urlencode($admin['name']));
            exit();
        } else {
            $stmt->close();
            header("Location: index.html?error=wrong_password&role=admin");
            exit();
        }
    } else {
        $stmt->close();
        header("Location: index.html?error=not_found&role=admin");
        exit();
    }
} else {
    header("Location: index.html");
    exit();
}
?>