<?php
// Initialize the session
session_start();
 
// Check if the user is logged in AND has an administrative role
// Administrative roles are: Adviser, Dean, OSAFA, AFO
$admin_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], $admin_roles)) {
    // If not logged in or not an admin, redirect to login
    header("location: login.php");
    exit;
}

require_once "db_config.php";

$role = $_SESSION["role"];
$full_name = htmlspecialchars($_SESSION["full_name"]);
$role_description = "";
$status_column = "";
$dashboard_title = "Admin Dashboard";

// Determine the specific role and status column for filtering requests
switch ($role) {
    case 'Adviser':
        $role_description = "You are the **Faculty Adviser**. You review requests from your specific organization.";
        $status_column = 'adviser_status';
        $dashboard_title = "Adviser Panel";
        break;
    case 'Dean':
        $role_description = "You are the **College Dean**. You review requests based on academic and college policies.";
        $status_column = 'dean_status';
        $dashboard_title = "Dean's Panel";
        break;
    case 'OSAFA':
        $role_description = "You are the **Head of OSAFA**. You review requests based on student activities guidelines.";
        $status_column = 'osafa_status';
        $dashboard_title = "OSAFA Head Panel";
        break;
    case 'AFO':
        $role_description = "You are the **Head of AFO**. You handle the final budget approval and release.";
        $status_column = 'afo_status';
        $dashboard_title = "AFO Head Panel";
        break;
}

// Prepare the query to count requests awaiting *this* signatory's approval
$pending_count = 0;
$sql_count = "
    SELECT 
        COUNT(r.request_id) AS pending_count
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    WHERE 
        r.{$status_column} = 'Pending'
";

// Logic for filtering by role:
// 1. Advisers only see requests from their own organization (using org_id)
// 2. Dean, OSAFA, and AFO see requests that have passed the *previous* stage.

if ($role === 'Adviser') {
    // Adviser: Must filter by their specific organization and pending status
    $org_id = $_SESSION["org_id"];
    $sql_count .= " AND u.org_id = ?";
    
    if ($stmt_count = mysqli_prepare($link, $sql_count)) {
        mysqli_stmt_bind_param($stmt_count, "i", $org_id);
    }
} else {
    // Dean, OSAFA, AFO: Must filter by pending status AND the previous stage must be approved.
    $previous_status_col = '';
    if ($role === 'Dean') {
        $previous_status_col = 'adviser_status';
    } elseif ($role === 'OSAFA') {
        $previous_status_col = 'dean_status';
    } elseif ($role === 'AFO') {
        $previous_status_col = 'osafa_status';
    }
    
    if (!empty($previous_status_col)) {
        $sql_count .= " AND r.{$previous_status_col} = 'Approved'";
    }
    
    // Dean, OSAFA, AFO don't need org_id binding here
    if ($stmt_count = mysqli_prepare($link, $sql_count)) {
        // No parameters needed if not an Adviser
    }
}


if (isset($stmt_count)) {
    if (mysqli_stmt_execute($stmt_count)) {
        $result_count = mysqli_stmt_get_result($stmt_count);
        $row = mysqli_fetch_assoc($result_count);
        $pending_count = $row['pending_count'];
    } else {
        // Handle error, though this should rarely happen
        $pending_count = "ERROR";
    }
    mysqli_stmt_close($stmt_count);
} else {
     $pending_count = "SQL ERROR";
}


// Close connection (we don't need it open for the rest of the page)
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $dashboard_title; ?> | AUF Request System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
    </style>
</head>
<body class="min-h-screen">
    
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold"><?php echo $dashboard_title; ?></h1>
        <div class="flex items-center space-x-4">
            <span class="text-sm font-light">
                Logged in as: <b><?php echo $full_name; ?></b> (<?php echo $role; ?>)
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="mb-8 p-6 bg-white rounded-xl shadow-2xl">
            <h2 class="text-3xl font-extrabold text-indigo-700 mb-2">Welcome, <?php echo $full_name; ?>!</h2>
            <p class="text-gray-600 font-medium"><?php echo $role_description; ?></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-xl shadow-lg border-l-4 border-yellow-500">
                <p class="text-sm font-medium text-gray-500 uppercase">Requests Awaiting My Review</p>
                <div class="text-4xl font-extrabold text-yellow-700 mt-2">
                    <?php echo $pending_count; ?>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-lg border-l-4 border-gray-300">
                <p class="text-sm font-medium text-gray-500 uppercase">My Role</p>
                <div class="text-4xl font-extrabold text-gray-700 mt-2">
                    <?php echo $role; ?>
                </div>
            </div>

            <div class="p-6">
                <a href="admin_request_list.php" class="block w-full text-center bg-indigo-600 text-white py-4 rounded-xl text-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md transform hover:scale-[1.01] active:scale-95 h-full flex items-center justify-center">
                    Go to Requests Needing My Review (<?php echo $pending_count; ?>)
                </a>
            </div>
        </div>
        
        <div class="mt-8">
            <h3 class="text-2xl font-bold text-gray-800 mb-4 border-b pb-2">Admin Tools & Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                
                <div class="bg-white p-6 rounded-xl shadow-lg border-t-2 border-green-500">
                    <h4 class="font-semibold text-lg text-gray-800 mb-2">My Review Inbox</h4>
                    <p class="text-gray-600 text-sm">Review the list of requests that require your specific approval step.</p>
                    <a href="admin_request_list.php" class="text-indigo-600 hover:text-indigo-800 mt-2 inline-block text-sm font-medium">View Inbox &rarr;</a>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border-t-2 border-blue-500">
                    <h4 class="font-semibold text-lg text-gray-800 mb-2">View All Requests</h4>
                    <p class="text-gray-600 text-sm">See the full history and status of all requests in the system.</p>
                    <a href="admin_request_list.php?filter=all" class="text-indigo-600 hover:text-indigo-800 mt-2 inline-block text-sm font-medium">View All &rarr;</a>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border-t-2 border-red-500">
                    <h4 class="font-semibold text-lg text-gray-800 mb-2">System Guidelines</h4>
                    <p class="text-gray-600 text-sm">Access the official policies and approval matrix for the AUF System.</p>
                    <a href="#" class="text-indigo-600 hover:text-indigo-800 mt-2 inline-block text-sm font-medium">Read Documentation &rarr;</a>
                </div>

            </div>
        </div>
    </div>
</body>
</html>
