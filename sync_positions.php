<?php
include 'db_connect.php';

// Ensure the table exists
$conn->query("CREATE TABLE IF NOT EXISTS election_position (id INT AUTO_INCREMENT PRIMARY KEY, election_id VARCHAR(50), title VARCHAR(100), max_votes INT DEFAULT 1)");
$checkMax = $conn->query("SHOW COLUMNS FROM election_position LIKE 'max_votes'");
if ($checkMax && $checkMax->num_rows == 0) {
    $conn->query("ALTER TABLE election_position ADD COLUMN max_votes INT DEFAULT 1");
}

// Insert missing positions from the candidate table into the election_position table
$query = "
    INSERT INTO election_position (election_id, title)
    SELECT DISTINCT c.election_id, c.position_title
    FROM candidate c
    LEFT JOIN election_position ep 
        ON c.election_id = ep.election_id 
        AND c.position_title = ep.title
    WHERE ep.id IS NULL AND c.position_title != ''
";

$conn->query($query);
$added = $conn->affected_rows;

echo "<div style='font-family: sans-serif; padding: 2rem;'>";
echo "<h2 style='color: #0a58ca;'>Position Sync Complete!</h2>";
echo "<p>Successfully found and added <strong>$added</strong> missing positions from your candidates into the <code>election_position</code> table.</p>";
echo "<p>You can now go back to your Admin Portal, and they will all appear properly in the 'Manage Positions' list and dropdowns.</p>";
echo "</div>";
?>