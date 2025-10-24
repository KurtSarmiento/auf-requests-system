<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Check if the user is logged in and is a Signatory (not an Officer)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] === 'Officer') {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];

// 1. Determine which status column and notification status to monitor based on the role
$status_column = '';
$current_review_status = '';
$role_description = '';

switch ($role) {
    case 'Adviser':
        $status_column = 'adviser_status';
        $current_review_status = 'Awaiting Adviser Review';
        $role_description = 'Organization Adviser';
        break;
    case 'Dean':
        $status_column = 'dean_status';
        $current_review_status = 'Awaiting Dean Review';
        $role_description = 'College Dean';
        break;
    case 'OSAFA': // Assuming OSAFA Head role
        $status_column = 'osafa_status';
        $current_review_status = 'Awaiting OSAFA Review';
        $role_description = 'OSAFA Head';
        break;
    case 'AFO': // Assuming AFO Head role
        $status_column = 'afo_status';
        $current_review_status = 'Awaiting AFO Review';
        $role_description = 'AFO Head';
        break;
    default:
        // Handle unexpected roles (shouldn't happen if user table is clean)
        $status_column = '';
        $current_review_status = 'Unknown';
        $role_description = 'Administrator';
}

// Determine previous signatory column (used to ensure a request is only pending for you after prior approval)
$previous_status_column = '';
if ($role === 'Dean') {
    $previous_status_column = 'adviser_status';
} elseif ($role === 'OSAFA') {
    $previous_status_column = 'dean_status';
} elseif ($role === 'AFO') {
    $previous_status_column = 'osafa_status';
}

// Initialize counts
$total_reviewable = 0;
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;

if (!empty($status_column)) {
    // --- Determine if the Admin is an Adviser/Dean (needs organization filtering) ---
    $org_filter_clause = '';
    $org_id = null;
    $params = [];
    $types = '';

    if ($role === 'Adviser' || $role === 'Dean') {
        // Advisers and Deans only see requests from organizations they are associated with.
        $org_id_sql = "SELECT org_id FROM users WHERE user_id = ?";
        if ($stmt = mysqli_prepare($link, $org_id_sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $org_id);
            mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);
        }

        if ($org_id) {
            // Find all users belonging to this organization
            $users_in_org_sql = "SELECT user_id FROM users WHERE org_id = ?";
            if ($stmt_org = mysqli_prepare($link, $users_in_org_sql)) {
                mysqli_stmt_bind_param($stmt_org, "i", $org_id);
                mysqli_stmt_execute($stmt_org);
                $result_org = mysqli_stmt_get_result($stmt_org);
                
                $officer_ids = [];
                while ($row = mysqli_fetch_assoc($result_org)) {
                    $officer_ids[] = $row['user_id'];
                }
                mysqli_stmt_close($stmt_org);

                if (!empty($officer_ids)) {
                    // Create WHERE clause for multiple user IDs
                    $placeholders = implode(',', array_fill(0, count($officer_ids), '?'));
                    $org_filter_clause = "AND r.user_id IN ($placeholders)";
                    
                    // Add all officer IDs to the parameters array
                    $params = array_merge($params, $officer_ids);
                    // Add type strings ('i') for each parameter
                    $types = str_repeat('i', count($officer_ids));
                }
            }
        }
    }
    
    // --- Fetch Request Counts for Reviewer ---
    // Build pending condition: role's status must be 'Pending' AND (if applicable) previous signatory must have 'Approved'
    $pending_condition = "r.{$status_column} = 'Pending'";
    if (!empty($previous_status_column)) {
        $pending_condition .= " AND r.{$previous_status_column} = 'Approved'";
    }

    $count_sql = "SELECT 
        COUNT(r.request_id) AS total_reviewable,
        SUM(CASE WHEN {$pending_condition} THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN r.{$status_column} = 'Approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN r.{$status_column} = 'Rejected' THEN 1 ELSE 0 END) AS rejected
    FROM requests r
    WHERE 1=1 $org_filter_clause";

    if ($stmt = mysqli_prepare($link, $count_sql)) {

        // Bind organization user_id parameters only if present (Adviser/Dean filter)
        if (!empty($types) && !empty($params)) {
            // Build array of references for call_user_func_array
            $bind_names = [];
            $bind_names[] = $types; // e.g. "ii..." depending on number of params
            foreach ($params as $key => $val) {
                $bind_names[] = &$params[$key];
            }
            call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_names));
        }

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $total_reviewable, $pending_count, $approved_count, $rejected_count);
            mysqli_stmt_fetch($stmt);
        } else {
            // Debugging: If query fails, print error.
            error_log("Admin Dashboard Query Failed: " . mysqli_error($link));
        }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($link);

// Start the page using the template function
start_page("Admin Dashboard", $role, $full_name);

?>

<!-- Dashboard Content -->
<h2 class="text-5xl font-extrabold text-gray-900 mb-2">Welcome Back, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>!</h2>
<p class="text-xl text-gray-600 mb-10">
    Review Dashboard for the <span class="font-bold text-blue-700"><?php echo htmlspecialchars($role_description); ?></span>.
</p>

<!-- Quick Action Buttons -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12 max-w-2xl">
    <!-- View Review Queue (Primary Action) -->
    <a href="admin_request_list.php" class="bg-blue-600 hover:bg-blue-700 text-white p-8 rounded-xl shadow-2xl transition duration-300 transform hover:scale-[1.03] flex flex-col justify-center">
        <h3 class="text-3xl font-bold mb-1">View Review Queue</h3>
        <p class="text-sm opacity-90 font-light">See all requests currently pending your action.</p>
    </a>
    <!-- Total Requests Managed (Info Card) -->
    <div class="bg-sky-600 text-white p-8 rounded-xl shadow-xl flex flex-col justify-center">
        <h3 class="text-xl font-semibold mb-1 opacity-80">Your Role:</h3>
        <p class="text-3xl font-extrabold"><?php echo htmlspecialchars($role_description); ?></p>
        <p class="text-sm opacity-90 mt-1">Reviewing: <?php echo htmlspecialchars($current_review_status); ?></p>
    </div>
</div>

<!-- Status Overview Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
    
    <!-- Pending Review (CRITICAL - Red/Orange Accent) -->
    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-red-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-red-600 uppercase tracking-wider">Pending Your Review</p>
        <p class="text-5xl font-extrabold text-red-700 mt-2"><?php echo $pending_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Requires immediate action</p>
    </div>

    <!-- Approved (Positive Blue/Green Accent) -->
    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-teal-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-teal-600 uppercase tracking-wider">Approved by You</p>
        <p class="text-5xl font-extrabold text-teal-700 mt-2"><?php echo $approved_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Total requests approved in your stage</p>
    </div>
    
    <!-- Rejected (Neutral Gray) -->
    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-gray-100 flex flex-col items-start">
        <p class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Rejected by You</p>
        <p class="text-5xl font-extrabold text-gray-500 mt-2"><?php echo $rejected_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Requests you have denied</p>
    </div>

</div>


<?php
// End the page using the template function
end_page();
?>
