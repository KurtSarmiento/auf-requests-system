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
$current_org_name = "";

// Function to determine CSS class for status pill
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-100 text-green-800';
        case 'Rejected':
            return 'bg-red-100 text-red-800';
        case 'Under Review':
            return 'bg-yellow-100 text-yellow-800';
        case 'Pending':
        default:
            return 'bg-blue-100 text-blue-800';
    }
}

// Fetch Organization Name for display
$sql_org = "SELECT org_name FROM organizations WHERE org_id = ?";
if ($stmt_org = mysqli_prepare($link, $sql_org)) {
    mysqli_stmt_bind_param($stmt_org, "i", $param_org_id);
    $param_org_id = $current_org_id;
    if (mysqli_stmt_execute($stmt_org)) {
        mysqli_stmt_bind_result($stmt_org, $org_name_result);
        if (mysqli_stmt_fetch($stmt_org)) {
            $current_org_name = $org_name_result;
        }
    }
    mysqli_stmt_close($stmt_org);
}


// Prepare the SQL statement to fetch all requests for the organization
$sql = "
    SELECT 
        r.request_id, 
        r.title, 
        r.type, 
        r.amount, 
        r.status, 
        r.date_submitted,
        u.full_name AS submitted_by 
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    WHERE 
        u.org_id = ? 
    ORDER BY 
        r.date_submitted DESC
";

$requests = [];

if ($stmt = mysqli_prepare($link, $sql)) {
    // Bind organization ID parameter
    mysqli_stmt_bind_param($stmt, "i", $param_org_id);
    $param_org_id = $current_org_id;

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        // Fetch all results into an array
        $requests = mysqli_fetch_all($result, MYSQLI_ASSOC);
    } else {
        echo "ERROR: Could not execute query: " . mysqli_error($link);
    }
    mysqli_stmt_close($stmt);
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
        /* Responsive table styling for smaller screens */
        @media screen and (max-width: 768px) {
            .table-responsive thead { display: none; }
            .table-responsive tr { 
                display: block; 
                margin-bottom: 0.75rem; 
                border-bottom: 2px solid #e5e7eb; /* light gray */
            }
            .table-responsive td {
                display: block;
                text-align: right;
                padding: 0.5rem 1rem;
                position: relative;
                font-size: 0.9rem;
            }
            .table-responsive td::before {
                content: attr(data-label);
                position: absolute;
                left: 1rem;
                font-weight: 600;
                width: 50%;
                text-align: left;
                color: #4b5563; /* gray-700 */
            }
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Bar (Simple) -->
    <div class="bg-indigo-800 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Request System</h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <span class="text-sm">Logged in as: <b><?php echo htmlspecialchars($_SESSION["username"]); ?></b></span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <h2 class="text-3xl font-extrabold text-gray-800 mb-2">Requests Submitted by <?php echo htmlspecialchars($current_org_name); ?></h2>
        <p class="text-gray-500 mb-6">Overview of all requests and their current status.</p>
        
        <div class="flex justify-end mb-4">
            <a href="request_create.php" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-4 py-2 rounded-lg transition duration-150 shadow-md">
                <!-- Plus icon using SVG -->
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                </svg>
                New Request
            </a>
        </div>
        
        <!-- Requests Table -->
        <div class="bg-white rounded-xl shadow-2xl overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 table-responsive">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title / Subject</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted By</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($requests)): ?>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900" data-label="ID"><?php echo $request['request_id']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800" data-label="Title"><?php echo htmlspecialchars($request['title']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-label="Type"><?php echo htmlspecialchars($request['type']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-label="Amount">
                                    <?php echo $request['amount'] !== NULL ? '₱' . number_format($request['amount'], 2) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" data-label="Submitted By"><?php echo htmlspecialchars($request['submitted_by']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" data-label="Date">
                                    <?php echo date('M d, Y', strtotime($request['date_submitted'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm" data-label="Status">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo get_status_class($request['status']); ?>">
                                        <?php echo htmlspecialchars($request['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium" data-label="Actions">
                                    <!-- Placeholder link for viewing/editing details -->
                                    <a href="request_details.php?id=<?php echo $request['request_id']; ?>" 
                                       class="text-indigo-600 hover:text-indigo-900">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="px-6 py-10 text-center text-gray-500">
                                You have not submitted any requests yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
