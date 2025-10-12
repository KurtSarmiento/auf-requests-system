<?php
// Initialize the session
session_start();
 
// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Include database connection
require_once "db_config.php";

// Get user and organization info from session
$current_org_id = $_SESSION["org_id"];
$request_id = $_GET['id'] ?? null;
$request = null;

// Function to determine CSS class for status pill (copied from request_list.php)
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-100 text-green-800 border-green-500';
        case 'Rejected':
            return 'bg-red-100 text-red-800 border-red-500';
        case 'Under Review':
            return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        case 'Pending':
        default:
            return 'bg-blue-100 text-blue-800 border-blue-500';
    }
}

// Check if an ID was provided and is numeric
if (!is_numeric($request_id) || $request_id <= 0) {
    $error_message = "Invalid request ID provided.";
} else {
    // Prepare the SQL statement to fetch the specific request
    // IMPORTANT: We join with users to ensure the request belongs to the user's organization for security.
    $sql = "
        SELECT 
            r.*, 
            u.full_name AS submitted_by,
            o.org_name
        FROM 
            requests r
        JOIN 
            users u ON r.user_id = u.user_id
        JOIN 
            organizations o ON u.org_id = o.org_id
        WHERE 
            r.request_id = ? AND u.org_id = ?
    ";

    if ($stmt = mysqli_prepare($link, $sql)) {
        // Bind parameters: integer (request_id), integer (org_id)
        mysqli_stmt_bind_param($stmt, "ii", $param_request_id, $param_org_id);
        $param_request_id = $request_id;
        $param_org_id = $current_org_id;

        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result) == 1) {
                $request = mysqli_fetch_assoc($result);
            } else {
                $error_message = "Request not found or you do not have permission to view it.";
            }
        } else {
            $error_message = "Oops! Something went wrong with the database query.";
        }
        mysqli_stmt_close($stmt);
    } else {
        $error_message = "Database preparation error.";
    }
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Details | AUF System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .detail-label { font-weight: 600; color: #4b5563; } /* gray-700 */
        .detail-value { color: #1f2937; } /* gray-800 */
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Bar -->
    <div class="bg-indigo-800 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Request System</h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <a href="request_list.php" class="hover:text-indigo-200 transition duration-150">My Requests</a>
            <span class="text-sm">Logged in as: <b><?php echo htmlspecialchars($_SESSION["username"]); ?></b></span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">

        <?php if (isset($error_message)): ?>
            <!-- Error Message Display -->
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-8 rounded-lg" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo $error_message; ?></p>
            </div>
            <a href="request_list.php" class="inline-block text-indigo-600 hover:text-indigo-800 font-medium">
                &larr; Back to Request List
            </a>

        <?php elseif ($request): ?>
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                <h2 class="text-3xl font-extrabold text-gray-800">
                    Request #<?php echo htmlspecialchars($request['request_id']); ?>: <?php echo htmlspecialchars($request['title']); ?>
                </h2>
                <a href="request_list.php" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-medium transition duration-150">
                    <!-- Arrow left icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to List
                </a>
            </div>

            <!-- Main Request Details Grid -->
            <div class="bg-white p-6 md:p-10 rounded-xl shadow-2xl mb-8">
                <h3 class="text-xl font-bold text-gray-800 border-b pb-3 mb-5">Summary</h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Status -->
                    <div class="p-4 rounded-lg border-2 <?php echo get_status_class($request['status']); ?>">
                        <p class="text-sm detail-label">Current Status</p>
                        <p class="text-2xl font-bold mt-1"><?php echo htmlspecialchars($request['status']); ?></p>
                    </div>

                    <!-- Type -->
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <p class="text-sm detail-label">Request Type</p>
                        <p class="text-lg detail-value font-medium"><?php echo htmlspecialchars($request['type']); ?></p>
                    </div>

                    <!-- Date Submitted -->
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <p class="text-sm detail-label">Date Submitted</p>
                        <p class="text-lg detail-value font-medium"><?php echo date('M d, Y H:i:s', strtotime($request['date_submitted'])); ?></p>
                    </div>

                    <!-- Organization -->
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <p class="text-sm detail-label">Organization</p>
                        <p class="text-lg detail-value font-medium"><?php echo htmlspecialchars($request['org_name']); ?></p>
                    </div>

                    <!-- Submitted By -->
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <p class="text-sm detail-label">Submitted By</p>
                        <p class="text-lg detail-value font-medium"><?php echo htmlspecialchars($request['submitted_by']); ?></p>
                    </div>

                    <!-- Amount -->
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <p class="text-sm detail-label">Requested/Liquidated Amount</p>
                        <p class="text-lg detail-value font-medium">
                            <?php echo $request['amount'] !== NULL ? '₱' . number_format($request['amount'], 2) : 'N/A'; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Description Section -->
            <div class="bg-white p-6 md:p-10 rounded-xl shadow-2xl mb-8">
                <h3 class="text-xl font-bold text-gray-800 border-b pb-3 mb-5">Detailed Description</h3>
                <div class="prose max-w-none text-gray-700 leading-relaxed whitespace-pre-wrap">
                    <?php echo htmlspecialchars($request['description']); ?>
                </div>
            </div>

            <!-- File Attachments Section (Placeholder for future development) -->
            <div class="bg-white p-6 md:p-10 rounded-xl shadow-2xl">
                <h3 class="text-xl font-bold text-gray-800 border-b pb-3 mb-5">File Attachments (Documents/Images)</h3>
                <div class="text-gray-500 italic p-4 bg-gray-50 rounded-lg">
                    No files have been attached yet. This feature will be implemented in a later step.
                </div>
                <!-- Future loop will go here to list files from the 'files' table -->
            </div>

        <?php endif; ?>
    </div>
</body>
</html>
