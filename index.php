<?php
// Include the database connection file
require_once "db_config.php";

echo "<h1>AUF Request System - Test Connection</h1>";

// Check if the connection was successful
if ($link) {
    echo "<p style='color:green;'>SUCCESS: Connected to the database: " . DB_NAME . "</p>";

    // Simple test query (after you've created the 'requests' table)
    $sql = "SELECT COUNT(*) AS total_requests FROM requests";

    if ($result = mysqli_query($link, $sql)) {
        $row = mysqli_fetch_assoc($result);
        echo "<p>Total requests found in the database: <b>" . $row['total_requests'] . "</b></p>";
    } else {
        echo "<p style='color:red;'>ERROR executing query: " . mysqli_error($link) . "</p>";
    }
    
    // Close connection
    mysqli_close($link);

} else {
    echo "<p style='color:red;'>FAILURE: Database link not established.</p>";
}
?>