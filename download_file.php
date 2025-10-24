<?php
// download_file.php
session_start();
require_once "db_config.php";

// Require login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$file_id = isset($_GET['fid']) ? (int)$_GET['fid'] : 0;
if ($file_id <= 0) {
    http_response_code(400);
    echo "Invalid file id.";
    exit;
}

// Fetch file record and request owner/org
$sql = "
    SELECT f.file_path, f.file_name, f.original_file_name, r.request_id, u.org_id AS submitter_org_id
    FROM files f
    JOIN requests r ON f.request_id = r.request_id
    JOIN users u ON r.user_id = u.user_id
    WHERE f.file_id = ?
    LIMIT 1
";
if (!$stmt = mysqli_prepare($link, $sql)) {
    http_response_code(500);
    echo "Server error.";
    exit;
}
mysqli_stmt_bind_param($stmt, "i", $file_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $file_path, $file_name, $original_name, $request_id, $submitter_org_id);
if (!mysqli_stmt_fetch($stmt)) {
    mysqli_stmt_close($stmt);
    http_response_code(404);
    echo "File not found.";
    exit;
}
mysqli_stmt_close($stmt);

// Permission checks:
// Officers can download files only for requests from their organization.
// Signatories (Adviser/Dean/OSAFA/AFO) can download files they are reviewing.
$role = $_SESSION['role'] ?? '';
if ($role === 'Officer') {
    $user_org = $_SESSION['org_id'] ?? null;
    if ($user_org === null || (int)$user_org !== (int)$submitter_org_id) {
        http_response_code(403);
        echo "You do not have permission to download this file.";
        exit;
    }
} else {
    $admin_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
    if (!in_array($role, $admin_roles)) {
        http_response_code(403);
        echo "You do not have permission to download this file.";
        exit;
    }
}

// Resolve actual path: prefer stored file_path, fallback to uploads/<file_name>
$paths_to_try = [];
if (!empty($file_path)) $paths_to_try[] = $file_path;
if (!empty($file_name)) $paths_to_try[] = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file_name;

// Find existing file
$real_path = null;
foreach ($paths_to_try as $p) {
    if (file_exists($p) && is_readable($p)) {
        $real_path = $p;
        break;
    }
}

if (!$real_path) {
    http_response_code(404);
    echo "File not found on server.";
    exit;
}

// Stream file with headers
$mime = @mime_content_type($real_path) ?: 'application/octet-stream';
$basename = $original_name ?: basename($real_path);
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($basename)) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($real_path));
readfile($real_path);
exit;
?>