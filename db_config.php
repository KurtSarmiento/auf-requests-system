<?php
// Define database credentials
// NOTE: These are default XAMPP settings. If you changed your MySQL password, update it here.
define('DB_SERVER', 'sql104.infinityfree.com');
define('DB_USERNAME', 'if0_40151522');
define('DB_PASSWORD', '4AQrSjzpnJt5xl'); 
define('DB_NAME', 'if0_40151522_aufrequestssystem');

// Attempt to connect to MySQL database
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($link === false) {
    // We die here to stop the script if the database connection fails
    die("ERROR: Could not connect to the database. " . mysqli_connect_error());
}
?>
