<?php
// Initialize the session
session_start();
 
// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Redirect based on role
$role = $_SESSION["role"];

if ($role === 'Officer') {
    // Redirect to the dedicated Officer Dashboard
    header("location: officer_dashboard.php");
    exit;
} else {
    // Redirect all other roles (Adviser, Dean, etc.) to the Admin Dashboard
    header("location: admin_dashboard.php");
    exit;
}
?>