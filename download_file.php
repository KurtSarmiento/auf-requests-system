<?php
// download_file.php
session_start();
require_once "db_config.php";

// 1. Authentication Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    die("Access Denied: Please log in.");
}

$file_path = isset($_GET['file']) ? $_GET['file'] : '';
$base_dir = __DIR__ . "/uploads/"; // Assumes files are in a directory named 'uploads'

// 2. Sanitize and Validate File Path
// This is critical to prevent Directory Traversal Attacks (e.g., ../../etc/passwd)
$file_path_sanitized = basename($file_path);
$full_path = $base_dir . $file_path_sanitized;

// Ensure the file exists AND is within the designated upload directory
if (empty($file_path_sanitized) || !file_exists($full_path)) {
    http_response_code(404);
    die("Error: File not found or path is invalid.");
}

// 3. Authorization Check (Check if the file belongs to a request the user can see)
// You may need to adapt this query based on your table structure and roles
$user_id = $_SESSION["user_id"];
$role = $_SESSION["role"];

// This query checks:
// - If the user is the Officer (r.user_id = $user_id) OR
// - If the user is an Adviser reviewing a request from their Organization (for an Adviser role)
// - (You would need a more complex query for Dean/OSAFA/AFO to check if the request is *in* their queue)

$sql = "SELECT r.request_id, u.org_id
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        WHERE r.file_path = ? AND (r.user_id = ?";

// Add organization-based check for Advisers
if ($role === 'Adviser') {
    $user_org_id = $_SESSION["org_id"];
    $sql .= " OR u.org_id = ?)";
} else {
    $sql .= ")";
}

if ($stmt = mysqli_prepare($link, $sql)) {
    if ($role === 'Adviser') {
        mysqli_stmt_bind_param($stmt, "sii", $file_path_sanitized, $user_id, $user_org_id);
    } else {
        mysqli_stmt_bind_param($stmt, "si", $file_path_sanitized, $user_id);
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) === 0) {
        // If the user is not the owner and not a privileged reviewer, deny access.
        http_response_code(403);
        die("Access Denied: You do not have permission to download this file.");
    }
    mysqli_stmt_close($stmt);
} else {
    // Database error
    http_response_code(500);
    die("System Error during authorization check.");
}

// 4. Serve the File Safely
header('Content-Description: File Transfer');
header('Content-Type: ' . mime_content_type($full_path)); // Use a proper MIME type
header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($full_path));
ob_clean();
flush();
readfile($full_path); // Streams the file directly
exit;
?>