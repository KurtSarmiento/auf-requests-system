<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is NOT an Officer (i.e., they are a Signatory)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] === 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

$current_role = $_SESSION["role"];

// Determine the SQL column name corresponding to the current user's role
$role_column = match($current_role) {
    'Adviser' => 'adviser_status',
    'Dean' => 'dean_status',
    'OSAFA' => 'osafa_status',
    'AFO' => 'afo_status',
    default => null,
};

// If the role is not recognized or column is missing, stop processing
if (!$role_column) {
    die("Error: Unrecognized administrative role.");
}

$requests = [];
$error_message = "";

// Function to determine CSS class for status pill
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

// Prepare the SQL query
// We select requests that are 'Pending' for the current role's column.
$sql = "
    SELECT 
        r.request_id, 
        r.title, 
        r.type, 
        r.date_submitted,
        r.adviser_status,
        r.dean_status,
        r.osafa_status,
        r.afo_status,
        o.org_name,
        u.full_name AS submitted_by
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    JOIN 
        organizations o ON u.org_id = o.org_id
    WHERE 
        r.$role_column = 'Pending'
    ORDER BY r.date_submitted ASC
";

if ($result = mysqli_query($link, $sql)) {
    if (mysqli_num_rows($result) > 0) {
        $requests = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_free_result($result);
    }
} else {
    $error_message = "ERROR: Could not execute $sql. " . mysqli_error($link);
}

// Close connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($current_role); ?> Review Queue | AUF System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .status-pill { 
            padding: 4px 8px; 
            border-radius: 9999px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            border: 1px solid; 
            display: inline-block;
            margin-top: 4px; /* Added spacing for multi-line pills */
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Bar -->
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Admin Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="admin_dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b> (<?php echo htmlspecialchars($current_role); ?>)
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h2 class="text-3xl font-extrabold text-gray-800">
                Review Queue: Requests Awaiting <?php echo htmlspecialchars($current_role); ?> Approval
            </h2>
            <a href="admin_dashboard.php" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-medium transition duration-150">
                &larr; Back to Dashboard
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-8 rounded-lg" role="alert">
                <p class="font-bold">Database Error</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php elseif (empty($requests)): ?>
            <div class="bg-teal-100 border-l-4 border-teal-500 text-teal-700 p-8 mb-8 rounded-xl">
                <p class="text-lg font-semibold">All Clear!</p>
                <p class="mt-2">There are currently no requests awaiting approval for the **<?php echo htmlspecialchars($current_role); ?>** stage.</p>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-2xl overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID / Title</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Submitted</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Approval Stages</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($requests as $request): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">#<?php echo htmlspecialchars($request['request_id']); ?> - <?php echo htmlspecialchars($request['title']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($request['type']); ?> by <?php echo htmlspecialchars($request['submitted_by']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($request['org_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M d, Y', strtotime($request['date_submitted'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <span class="status-pill <?php echo get_status_class($request['adviser_status']); ?>">ADVISER: <?php echo htmlspecialchars($request['adviser_status']); ?></span>
                                <span class="status-pill <?php echo get_status_class($request['dean_status']); ?>">DEAN: <?php echo htmlspecialchars($request['dean_status']); ?></span>
                                <span class="status-pill <?php echo get_status_class($request['osafa_status']); ?>">OSAFA: <?php echo htmlspecialchars($request['osafa_status']); ?></span>
                                <span class="status-pill <?php echo get_status_class($request['afo_status']); ?>">AFO: <?php echo htmlspecialchars($request['afo_status']); ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="admin_review.php?id=<?php echo htmlspecialchars($request['request_id']); ?>" class="text-indigo-600 hover:text-indigo-900 font-semibold transition duration-150">
                                    Review & Decide &rarr;
                                </a>
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
