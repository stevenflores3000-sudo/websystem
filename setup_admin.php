<?php
session_start();
include 'db_connect.php';

echo "<div style='font-family: sans-serif; padding: 2rem;'>";
echo "<h2>Admin Account Setup</h2>";

// 1. Automatically create the admin table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL
)";
if ($conn->query($createTableQuery)) {
    echo "<p>✓ Admin table checked/created successfully.</p>";
}

// 2. Patch the table if it was created previously without the 'name' column
$checkColumn = $conn->query("SHOW COLUMNS FROM admin LIKE 'name'");
if ($checkColumn && $checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE admin ADD COLUMN name VARCHAR(100) NOT NULL DEFAULT 'System Administrator' AFTER admin_id");
    echo "<p>✓ Admin table patched to include 'name' column.</p>";
}

// 3. Automatically insert the default admin if it doesn't exist
$checkAdmin = $conn->query("SELECT * FROM admin WHERE admin_id = 'admin'");
if ($checkAdmin && $checkAdmin->num_rows == 0) {
    $default_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT INTO admin (admin_id, name, password) VALUES ('admin', 'System Administrator', '$default_hash')");
    echo "<p style='color: green;'><strong>✓ Default admin created!</strong><br>Username: <code>admin</code><br>Password: <code>admin123</code></p>";
} else {
    echo "<p style='color: #0a58ca;'>ℹ Default admin account already exists. No changes made.</p>";
}

echo "<p><br><a href='index.html'>Return to Login</a></p>";
echo "</div>";
?>