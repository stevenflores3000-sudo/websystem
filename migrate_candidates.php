<?php
session_start();
// Security check: ensure only admins can run this script
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized access.");
}

include 'db_connect.php';

echo "<div style='font-family: sans-serif; padding: 2rem;'>";
echo "<h2>Database Migration Tool</h2>";

// 1. Add the name column to candidate table if it doesn't exist
$checkCol = $conn->query("SHOW COLUMNS FROM candidate LIKE 'name'");
if ($checkCol && $checkCol->num_rows == 0) {
    $conn->query("ALTER TABLE candidate ADD COLUMN name VARCHAR(100) DEFAULT ''");
}
$conn->query("ALTER TABLE candidate MODIFY user_id VARCHAR(50) NULL");

// 2. Copy the names from the old dummy users over to the candidate table
$conn->query("UPDATE candidate c JOIN user u ON c.user_id = u.id SET c.name = u.name WHERE c.user_id LIKE 'CAND-%'");
$updated = $conn->affected_rows;
echo "<p>Copied names to candidate table: <strong>$updated</strong> updated.</p>";

// 3. Detach the candidates from the dummy users
$conn->query("UPDATE candidate SET user_id = NULL WHERE user_id LIKE 'CAND-%'");

// 4. Delete the dummy users from the 'user' table entirely
$deleted = $conn->query("DELETE FROM user WHERE id LIKE 'CAND-%'");
$deleted_count = $conn->affected_rows;

if ($deleted) {
    echo "<p style='color: green;'><strong>Success!</strong> <strong>$deleted_count</strong> old dummy candidates have been moved into the candidate table and permanently removed from the user database.</p>";
} else {
    echo "<p style='color: red;'>Error during migration: " . $conn->error . "</p>";
    echo "<p>If you see an error, please let me know what it says!</p>";
}
echo "</div>";
?>