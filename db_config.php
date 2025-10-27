 <?php
// Define database credentials
// NOTE: These are default XAMPP settings. If you changed your MySQL password, update it here.
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', ''); 
define('DB_NAME', 'auf_requests_db');

// Attempt to connect to MySQL database
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($link === false) {
    // We die here to stop the script if the database connection fails
    die("ERROR: Could not connect to the database. " . mysqli_connect_error());
}
?>