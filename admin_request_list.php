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
$user_org_id = isset($_SESSION["org_id"]) ? (int)$_SESSION["org_id"] : 0;

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
$bind_params = [];
$types = "";

// Determine required previous status filter column
$previous_role_column = match($current_role) {
    'Dean' => 'adviser_status',
    'OSAFA' => 'dean_status',
    'AFO' => 'osafa_status',
    default => null, // Adviser has no previous signatory
};

// Function to determine CSS class for status pill
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-100 text-green-800 border-green-500';
        case 'Rejected':
            return 'bg-red-100 text-red-800 border-red-500';
        case 'Under Review':
        case 'Awaiting Dean Approval':
        case 'Awaiting OSAFA Approval':
        case 'Awaiting AFO Approval':
            return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        case 'Budget Available':
            return 'bg-purple-100 text-purple-800 border-purple-500 font-bold';
        case 'Pending':
        default:
            return 'bg-blue-100 text-blue-800 border-blue-500';
    }
}


// --- Build the SQL Query ---
$sql = "
    SELECT 
        r.request_id, 
        r.title, 
        r.amount, 
        r.date_submitted, 
        r.adviser_status, 
        r.dean_status, 
        r.osafa_status, 
        r.afo_status,
        r.notification_status,
        o.org_name,
        u.full_name AS submitted_by
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    JOIN
        organizations o ON u.org_id = o.org_id
    WHERE 
        r.{$role_column} = 'Pending' 
";


// 1. FILTER: Adviser-specific filter (only see requests from their own org)
if ($current_role === 'Adviser') {
    if ($user_org_id > 0) {
        $sql .= " AND u.org_id = ?";
        $types .= "i";
        $bind_params[] = $user_org_id;
    } else {
        $error_message = "Your Adviser account is not linked to any organization. Cannot display requests.";
    }
}

// 2. FILTER: Sequential Approval Logic
if ($previous_role_column !== null) {
    // CRITICAL: Only show requests that the previous signatory has 'Approved'.
    // This blocks requests that are 'Rejected' OR still 'Pending' from the previous stage.
    $sql .= " AND r.{$previous_role_column} = 'Approved'";
}

// Final ordering
$sql .= " ORDER BY r.date_submitted ASC";


// --- Execute the Query ---
if (empty($error_message) && $stmt = mysqli_prepare($link, $sql)) {
    
    // Dynamic binding for parameters
    if (!empty($bind_params)) {
        // Prepend the type string to the bind parameters array
        array_unshift($bind_params, $types);
        
        // Use references for dynamic binding
        $refs = [];
        foreach ($bind_params as $key => &$value) {
            $refs[$key] = &$bind_params[$key];
        }

        // Call bind_param dynamically
        call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs));
    }

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $requests = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_free_result($result);
    } else {
        $error_message = "Error executing request fetch query: " . mysqli_error($link);
    }
    mysqli_stmt_close($stmt);
} else if (empty($error_message)) {
     $error_message = "Error preparing request fetch statement: " . mysqli_error($link);
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Queue | AUF Admin System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .status-pill { 
            padding: 4px 8px; 
            border-radius: 9999px; 
            font-size: 0.65rem; /* Smaller font for multiple pills */
            font-weight: 600; 
            border: 1px solid; 
            display: inline-block;
            margin: 2px 0;
            line-height: 1;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Admin Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="admin_dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <a href="admin_request_list.php" class="text-yellow-300 font-bold transition duration-150">Review Queue</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b> (<?php echo htmlspecialchars($current_role); ?>)
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <h2 class="text-3xl font-extrabold text-gray-900 mb-6">
            Requests Awaiting <?php echo htmlspecialchars($current_role); ?> Review
        </h2>
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if (empty($requests)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-6 rounded-xl shadow-md">
                <p class="font-bold text-xl">Review Queue Clear!</p>
                <p class="mt-2">There are no pending requests requiring your <?php echo htmlspecialchars($current_role); ?> approval at this time.</p>
            </div>
        <?php else: ?>
            <div class="bg-white shadow-xl overflow-hidden sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Request ID / Title
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Organization / Submitted By
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Amount / Date
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Approval Status
                            </th>
                            <th scope="col" class="relative px-6 py-3">
                                <span class="sr-only">Action</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($requests as $request): ?>
                        <tr class="hover:bg-indigo-50 transition duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-indigo-600">#<?php echo htmlspecialchars($request['request_id']); ?></div>
                                <div class="text-sm font-medium text-gray-900 truncate max-w-xs"><?php echo htmlspecialchars($request['title']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($request['org_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($request['submitted_by']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">₱<?php echo number_format($request['amount'], 2); ?></div>
                                <div class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($request['date_submitted'])); ?></div>
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