<?php
// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

// Get request ID from URL (ensure it's an integer)
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$request = null;
$files = [];
$error_message = "";

// Security check
$is_officer = $_SESSION["role"] === 'Officer';
$org_id = $_SESSION["org_id"];

// ✅ PHP 7.4 compatible: function for status pill
function get_status_class($status) {
    if ($status == 'Approved') return 'bg-green-100 text-green-800 border-green-500';
    elseif ($status == 'Rejected') return 'bg-red-100 text-red-800 border-red-500';
    elseif ($status == 'Awaiting AFO Approval') return 'bg-yellow-100 text-yellow-800 border-yellow-500';
    elseif ($status == 'Budget Available') return 'bg-purple-100 text-purple-800 border-purple-500 font-bold';
    else return 'bg-blue-100 text-blue-800 border-blue-500';
}

// --- 1. Fetch Request Details ---
// ✅ Converted to mysqli_query() for PHP 7.4
$sql = "
    SELECT 
        r.*, 
        u.full_name AS submitted_by_name, 
        o.org_name,
        r.adviser_remark,
        r.dean_remark,
        r.osafa_remark,
        r.afo_remark
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    JOIN
        organizations o ON u.org_id = o.org_id
    WHERE 
        r.request_id = $request_id
";

// ✅ Officer restriction: only view own org’s request
if ($is_officer) {
    $org_id_safe = mysqli_real_escape_string($link, $org_id);
    $sql .= " AND u.org_id = '$org_id_safe'";
}

$result = mysqli_query($link, $sql);

if ($result) {
    if (mysqli_num_rows($result) == 1) {
        $request = mysqli_fetch_assoc($result);
    } else {
        $error_message = "Request not found or you do not have permission to view it.";
    }
} else {
    $error_message = "Database error fetching request: " . mysqli_error($link);
}

// --- 2. Fetch Attached Files ---
if ($request) {
    $sql_files = "
        SELECT 
            file_id, original_file_name, file_name
        FROM 
            files 
        WHERE 
            request_id = $request_id
    ";

    $result_files = mysqli_query($link, $sql_files);

    if ($result_files) {
        $files = mysqli_fetch_all($result_files, MYSQLI_ASSOC);
    } else {
        error_log("Failed to fetch files for request ID $request_id: " . mysqli_error($link));
    }
}

// Close connection
mysqli_close($link);

// Stop execution if request not found
if (!$request && !$error_message) {
    $error_message = "Invalid request ID.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $request ? 'Request #' . htmlspecialchars($request['request_id']) : 'Request Not Found'; ?> | AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .status-pill { padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; border: 1px solid; display: inline-block; }
        .info-box { padding: 1.5rem; border-radius: 0.75rem; background-color: #ffffff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="min-h-screen">
    
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Officer Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <a href="request_list.php" class="hover:text-indigo-200 transition duration-150">My Requests</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b>
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-extrabold text-gray-800">
                <?php echo $request ? 'Request #' . htmlspecialchars($request['request_id']) . ': ' . htmlspecialchars($request['title']) : 'Request Details'; ?>
            </h2>
            <a href="request_list.php" class="text-indigo-600 hover:text-indigo-800 transition duration-150 font-medium flex items-center">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Back to List
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 mb-8 rounded-xl shadow-lg">
                <p class="font-bold text-xl">Access Denied or Error</p>
                <p class="mt-2"><?php echo $error_message; ?></p>
            </div>
        <?php else: ?>

            <!-- Summary Info -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="info-box border-l-4 border-blue-500 bg-blue-50/70">
                    <p class="text-sm font-medium text-gray-500 mb-2">Current Final Status</p>
                    <?php $final_status = htmlspecialchars($request['final_status']); ?>
                    <div class="text-2xl font-bold text-gray-800">
                        <span class="status-pill <?php echo get_status_class($final_status); ?>">
                            <?php echo $final_status; ?>
                        </span>
                    </div>
                </div>

                <div class="info-box">
                    <p class="text-sm font-medium text-gray-500 mb-2">Request Type</p>
                    <p class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($request['type']); ?></p>
                </div>

                <div class="info-box">
                    <p class="text-sm font-medium text-gray-500 mb-2">Requested Amount</p>
                    <p class="text-xl font-bold text-indigo-700">₱<?php echo number_format($request['amount'], 2); ?></p>
                </div>
            </div>

            <!-- Organization + Submitter + Date -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="info-box">
                    <p class="text-sm font-medium text-gray-500 mb-2">Organization</p>
                    <p class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($request['org_name']); ?></p>
                </div>
                <div class="info-box">
                    <p class="text-sm font-medium text-gray-500 mb-2">Submitted By</p>
                    <p class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($request['submitted_by_name']); ?></p>
                </div>
                <div class="info-box">
                    <p class="text-sm font-medium text-gray-500 mb-2">Date Submitted</p>
                    <p class="text-xl font-bold text-gray-800">
                        <?php echo date('M d, Y H:i:s', strtotime($request['date_submitted'])); ?>
                    </p>
                </div>
            </div>

            <!-- Details & Approval Flow -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 info-box h-full">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Detailed Justification</h3>
                    <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($request['description']); ?></p>
                </div>

                <!-- Approval Flow -->
                <div class="info-box border-l-4 border-gray-300 bg-gray-50">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Approval Flow</h3>
                    <div class="space-y-4">
                        <?php 
                        $signatories = [
                            'Adviser' => ['status_col' => 'adviser_status', 'remark_col' => 'adviser_remark'],
                            'Dean' => ['status_col' => 'dean_status', 'remark_col' => 'dean_remark'],
                            'OSAFA Head' => ['status_col' => 'osafa_status', 'remark_col' => 'osafa_remark'],
                            'AFO Head' => ['status_col' => 'afo_status', 'remark_col' => 'afo_remark']
                        ];
                        
                        foreach ($signatories as $role_name => $data): 
                            $status = htmlspecialchars($request[$data['status_col']]);
                            $remark = htmlspecialchars($request[$data['remark_col']] ?? '');
                        ?>
                            <div class="border-b border-gray-200 pb-3">
                                <div class="flex justify-between items-center text-sm">
                                    <p class="font-medium text-gray-700"><?php echo $role_name; ?></p>
                                    <span class="status-pill <?php echo get_status_class($status); ?>"><?php echo $status; ?></span>
                                </div>
                                <?php if (!empty($remark) && $status !== 'Pending'): ?>
                                    <div class="mt-2 p-3 text-xs bg-indigo-50 border-l-4 border-indigo-400 rounded-lg text-gray-700">
                                        <p class="font-semibold mb-1">Comment:</p>
                                        <p><?php echo nl2br($remark); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="pt-4 border-t border-gray-200">
                            <p class="font-semibold text-gray-800 mb-2">Budget Availability</p>
                            <?php $notification_status = htmlspecialchars($request['notification_status']); ?>
                            <span class="status-pill text-sm <?php echo get_status_class($notification_status); ?>">
                                <?php echo $notification_status; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Attachments -->
                <div class="lg:col-span-3 info-box bg-indigo-50 border-indigo-500 border-2">
                    <h3 class="text-xl font-semibold text-indigo-800 mb-4">Supporting Attachments (<?php echo count($files); ?>)</h3>
                    <?php if (!empty($files)): ?>
                        <div class="space-y-3">
                            <?php foreach ($files as $file): ?>
                                <div class="flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200 shadow-sm">
                                    <p class="text-sm text-gray-700 font-medium truncate"><?php echo htmlspecialchars($file['original_file_name']); ?></p>
                                    <a href="download_file.php?fid=<?php echo (int)$file['file_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold" target="_blank" rel="noopener noreferrer">Download</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-green-50 text-green-800 p-4 rounded-lg">
                            <p class="text-sm">No supporting documents attached to this request.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
