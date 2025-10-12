<?php
// Initialize the session
session_start();
 
// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

$user_id = $_SESSION["user_id"];
$org_id = $_SESSION["org_id"];
$requests = [];
$error_message = "";

// Function to determine CSS class for status pill
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-100 text-green-800 border-green-500';
        case 'Rejected':
            return 'bg-red-100 text-red-800 border-red-500';
        case 'Awaiting AFO Approval':
            return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        case 'Budget Available':
            return 'bg-purple-100 text-purple-800 border-purple-500 font-bold';
        case 'Pending':
        default:
            return 'bg-blue-100 text-blue-800 border-blue-500';
    }
}

// Prepare the SQL query to select all requests submitted by this officer's organization
// Note: We are now checking the user's organization ID and displaying the final_status.
$sql = "
    SELECT 
        r.request_id, 
        r.title, 
        r.type, 
        r.amount, 
        r.date_submitted,
        r.final_status,
        r.notification_status
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    WHERE 
        u.org_id = ?
    ORDER BY r.date_submitted DESC
";

// Line 70 (The fix is in the SQL above, but the execution starts here)
if ($stmt = mysqli_prepare($link, $sql)) {
    // Bind parameters: 'i' for integer (org_id)
    mysqli_stmt_bind_param($stmt, "i", $org_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) > 0) {
            $requests = mysqli_fetch_all($result, MYSQLI_ASSOC);
        }
    } else {
        $error_message = "ERROR: Could not execute query. " . mysqli_error($link);
    }

    mysqli_stmt_close($stmt);
} else {
    $error_message = "ERROR: Could not prepare statement. " . mysqli_error($link);
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests | AUF System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .status-pill { 
            padding: 4px 10px; 
            border-radius: 9999px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            border: 1px solid; 
            display: inline-block;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Bar -->
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Officer Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b>
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h2 class="text-3xl font-extrabold text-gray-800">My Organization Requests</h2>
            <a href="create_request.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-semibold shadow-md transition duration-150">
                + Submit New Request
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-8 rounded-lg" role="alert">
                <p class="font-bold">Database Error</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php elseif (empty($requests)): ?>
            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-8 mb-8 rounded-xl">
                <p class="text-lg font-semibold">No Requests Found</p>
                <p class="mt-2">Your organization, **<?php echo htmlspecialchars($_SESSION["org_name"] ?? 'Your Org'); ?>**, has not submitted any requests yet.</p>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-2xl overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title / Type</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted On</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Final Status</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notification</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($requests as $request): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['title']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($request['type']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-semibold">
                                <?php echo $request['amount'] !== null ? '₱' . number_format($request['amount'], 2) : 'N/A'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M d, Y', strtotime($request['date_submitted'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="status-pill <?php echo get_status_class($request['final_status']); ?>">
                                    <?php echo htmlspecialchars($request['final_status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="status-pill <?php echo get_status_class($request['notification_status']); ?>">
                                    <?php echo htmlspecialchars($request['notification_status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <a href="request_details.php?id=<?php echo htmlspecialchars($request['request_id']); ?>" class="text-indigo-600 hover:text-indigo-900 font-semibold transition duration-150">
                                    View Details
                                </a>
                                <?php if ($request['final_status'] === 'Pending'): ?>
                                    <!-- Edit/Delete only allowed if the request is still pending for any stage -->
                                    <a href="edit_request.php?id=<?php echo htmlspecialchars($request['request_id']); ?>" class="text-yellow-600 hover:text-yellow-900 transition duration-150 ml-2">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
