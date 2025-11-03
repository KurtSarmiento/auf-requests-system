<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// --- HELPER FUNCTION: Safely binds parameters for prepared statements ---
/**
 * Binds parameters to a mysqli prepared statement dynamically.
 * Note: Parameters must be passed by reference for mysqli_stmt_bind_param.
 *
 * @param mysqli_stmt $stmt The prepared statement object.
 * @param string $types String containing one or more characters which specify the types.
 * @param array $params Array of parameters to bind.
 * @return void
 */
function safe_bind_params($stmt, $types, &$params) {
    if (empty($types) || empty($params)) {
        return;
    }

    $bind_names = [$types];
    // Create references for all parameters
    foreach ($params as $key => $val) {
        $bind_names[] = &$params[$key];
    }
    // Call the bind_param method
    call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_names));
}
// --- END HELPER FUNCTION ---


// Check if the user is logged in and is a Signatory (not an Officer)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] === 'Officer') {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];
// --- ADDED: This is required for the new logic ---
$user_org_id = isset($_SESSION["org_id"]) ? (int)$_SESSION["org_id"] : 0;
$role_description = '';
$error_message = ""; // To store errors

// 1. Determine which status column and notification status to monitor based on the role
$status_column = '';
$current_review_status = ''; // This variable seems unused in the original, keeping for consistency

// Define roles that review the 'requests' table (Funding/Standard requests)
$funding_review_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
// Define roles that review the 'venue_requests' table ONLY (or other tables)
$venue_only_roles = [
    'Admin Services',
    'CFDO',
    'VP for Academic Affairs',
    'VP for Administration'
];
// Define roles that review both types of requests
$dual_review_roles = ['Dean', 'OSAFA', 'AFO'];

// Variables to store previous role columns for checking prerequisites
$prev_status_column_funding = null;
$prev_status_column_venue = null;

switch ($role) {
    case 'Adviser':
        $status_column = 'adviser_status';
        $current_review_status = 'Awaiting Adviser Review'; // Unused?
        $role_description = 'Organization Adviser';
        break;
    case 'Dean':
        $status_column = 'dean_status';
        $current_review_status = 'Awaiting Dean Review'; // Unused?
        $role_description = 'College Dean';
        $prev_status_column_funding = 'adviser_status'; // Prerequisite for funding
        // No prerequisite for venue assumed
        break;
    case 'OSAFA': // Assuming OSAFA Head role
        $status_column = 'osafa_status';
        $current_review_status = 'Awaiting OSAFA Review'; // Unused?
        $role_description = 'OSAFA Head';
        $prev_status_column_funding = 'dean_status';
        $prev_status_column_venue = 'admin_services_status';
        break;
    case 'AFO': // Assuming AFO Head role
        $status_column = 'afo_status';
        $current_review_status = 'Awaiting AFO Review'; // Unused?
        $role_description = 'AFO Head';
        $prev_status_column_funding = 'osafa_status';
        $prev_status_column_venue = 'cfdo_status';
        break;
    case 'Admin Services': // Assuming Admin Services role
        $status_column = 'admin_services_status'; // Only exists on venue_requests table
        $current_review_status = 'Awaiting Admin Services Approval'; // Used for Venue
        $role_description = 'Admin Services Head'; // Corrected description
        $prev_status_column_venue = 'dean_status';
        break;
    case 'CFDO': // Assuming CFDO role
        $status_column = 'cfdo_status'; // Only exists on venue_requests table
        $current_review_status = 'Awaiting CFDO Approval'; // Used for Venue
        $role_description = 'CFDO Head'; // Corrected description
        $prev_status_column_venue = 'osafa_status';
        break;
    case 'VP for Academic Affairs': // Assuming VP for Academic Affairs role
        $status_column = 'vp_acad_status'; // Fixed from VPAcad_status to match DB
        $current_review_status = 'Awaiting VP for Academic Affairs Approval'; // Used for Venue
        $role_description = 'VP for Academic Affairs';
        $prev_status_column_venue = 'afo_status';
        break;
    case 'VP for Administration': // Assuming VP for Administration role
        $status_column = 'vp_admin_status'; // Fixed from VPAdmin_status to match DB
        $current_review_status = 'Awaiting VP for Administration Approval'; // Used for Venue
        $role_description = 'VP for Administration';
        $prev_status_column_venue = 'vp_acad_status';
        break;
    default:
        // Handle unexpected roles
        $status_column = '';
        $current_review_status = 'Unknown'; // Unused?
        $role_description = 'Administrator';
        $error_message = "Your role ('" . htmlspecialchars($role) . "') is not configured correctly.";
}

// ===================================================================
// --- START: ORIGINAL DATA FETCHING LOGIC WITH AFO MODIFICATIONS ---
// ===================================================================

// Initialize counts
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;

// --- Build SQL conditions ---
$sql_approved = "({$status_column} = 'Approved')";
$sql_rejected = "({$status_column} = 'Rejected')";

// --- Build PENDING condition for Funding Requests (table: requests) ---
// This logic ensures we only count if the previous role approved
$funding_pending_condition = "";
if (in_array($role, $funding_review_roles)) {
    // ✅ AFO LOGIC: Modify the base pending condition for AFO
    if ($role === 'AFO') {
         $funding_pending_condition = "({$status_column} = 'Pending' OR final_status = 'Budget Processing')";
    } else {
         $funding_pending_condition = "({$status_column} = 'Pending')";
    }
    
    // Add prerequisite check based on the switch statement above
    if ($prev_status_column_funding) {
        $funding_pending_condition .= " AND ({$prev_status_column_funding} = 'Approved')";
    }
}

// --- Build PENDING condition for Venue Requests (table: venue_requests) ---
// This logic ensures we only count if the previous role approved
$venue_pending_condition = "";
// Check if the role is involved in venue reviews (dual or venue-only)
if (in_array($role, $dual_review_roles) || in_array($role, $venue_only_roles)) {
    
    $venue_pending_condition = "({$status_column} = 'Pending')";

    // Add prerequisite check based on the switch statement above
    if ($prev_status_column_venue) {
        $venue_pending_condition .= " AND ({$prev_status_column_venue} = 'Approved')";
    }
}

// --- Build Organization Filter (for Adviser and Dean) ---
// This logic is more efficient and handles the org_id=0 case for Deans
$org_filter_clause_funding = ""; // Use JOIN alias 'u' for user table
$org_filter_clause_venue = "";   // Use JOIN alias 'u' for user table

if ($role === 'Adviser') {
    // Advisers MUST be tied to an org
    if ($user_org_id > 0) {
        // We filter by joining the users table (aliased as 'u')
        $org_filter_clause_funding = " AND u.org_id = " . (int)$user_org_id;
    } else {
        $org_filter_clause_funding = " AND 1=0"; // 1=0 is 'false', returns 0 rows
        if(empty($error_message)) $error_message = "Your Adviser account is not linked to an organization.";
    }
} elseif ($role === 'Dean') {
    // Deans are OPTIONALLY tied to an org. If org_id=0, they see all.
    if ($user_org_id > 0) {
        $org_filter_clause_funding = " AND u.org_id = " . (int)$user_org_id;
        $org_filter_clause_venue = " AND u.org_id = " . (int)$user_org_id;
    }
}
// Other roles (OSAFA, AFO, etc.) are not filtered by org.

// --- 3. EXECUTE QUERIES ---

// Only run queries if the role is valid and no critical errors occurred
if (!empty($status_column) && empty($error_message)) {

    // Determine which tables to query based on role category
    $query_funding = in_array($role, $funding_review_roles);
    $query_venue = in_array($role, $venue_only_roles) || in_array($role, $dual_review_roles);

    // --- Execute Funding Query (if applicable) ---
    if ($query_funding && !empty($funding_pending_condition)) {
        // ✅ AFO LOGIC: Modify approved condition for AFO
        $funding_approved_condition = $sql_approved;
        if($role === 'AFO') {
            $funding_approved_condition = "(final_status = 'Budget Available')"; // Only count fully available
        }
        
        $sql_funding_counts = "SELECT 
                COALESCE(SUM(CASE WHEN {$funding_pending_condition} THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN {$funding_approved_condition} THEN 1 ELSE 0 END), 0) AS approved,
                COALESCE(SUM(CASE WHEN {$sql_rejected} THEN 1 ELSE 0 END), 0) AS rejected
            FROM requests r
            JOIN users u ON r.user_id = u.user_id
            WHERE 1=1 {$org_filter_clause_funding}";

        if ($result = mysqli_query($link, $sql_funding_counts)) {
            $row = mysqli_fetch_assoc($result);
            $pending_count += (int)$row['pending'];
            $approved_count += (int)$row['approved'];
            $rejected_count += (int)$row['rejected'];
            mysqli_free_result($result);
        } else {
             error_log("Dashboard Funding Count Error: " . mysqli_error($link));
        }
    }

    // --- Execute Venue Query (if applicable) ---
    if ($query_venue && !empty($venue_pending_condition)) {
        // Venue uses standard approved/rejected conditions
        $sql_venue_counts = "SELECT 
                COALESCE(SUM(CASE WHEN {$venue_pending_condition} THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN {$sql_approved} THEN 1 ELSE 0 END), 0) AS approved,
                COALESCE(SUM(CASE WHEN {$sql_rejected} THEN 1 ELSE 0 END), 0) AS rejected
            FROM venue_requests v
            JOIN users u ON v.user_id = u.user_id
            WHERE 1=1 {$org_filter_clause_venue}";

        if ($result = mysqli_query($link, $sql_venue_counts)) {
            $row = mysqli_fetch_assoc($result);
            $pending_count += (int)$row['pending'];
            $approved_count += (int)$row['approved'];
            $rejected_count += (int)$row['rejected'];
            mysqli_free_result($result);
        } else {
             error_log("Dashboard Venue Count Error: " . mysqli_error($link));
        }
    }
}
// ===================================================================
// --- END: ORIGINAL DATA FETCHING LOGIC WITH AFO MODIFICATIONS ---
// ===================================================================

// Close DB connection
if (isset($link) && $link instanceof mysqli) { // Check if link is valid before closing
    mysqli_close($link);
}

// Start the page using the template function
start_page("Admin Dashboard", $role, $full_name);

?>

<h2 class="text-5xl font-extrabold text-gray-900 mb-2">Welcome Back, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>!</h2>
<p class="text-xl text-gray-600 mb-10">
    Review Dashboard for the <span class="font-bold text-blue-700"><?php echo htmlspecialchars($role_description); ?></span>.
</p>

<?php if (!empty($error_message)): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p class="font-bold">Configuration Error</p>
        <p><?php echo htmlspecialchars($error_message); ?></p>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12 max-w-4xl">
    <a href="admin_request_list.php" class="bg-blue-600 hover:bg-blue-700 text-white p-8 rounded-xl shadow-2xl transition duration-300 transform hover:scale-[1.03] flex flex-col justify-center">
        <h3 class="text-3xl font-bold mb-1">View Review Queue</h3>
        <p class="text-sm opacity-90 font-light">See all requests currently pending your action.</p>
    </a>
    
    <a href="admin_history_list.php" class="bg-sky-700 hover:bg-sky-800 text-white p-8 rounded-xl shadow-2xl transition duration-300 transform hover:scale-[1.03] flex flex-col justify-center">
        <h3 class="text-3xl font-bold mb-1">View Request History</h3>
        <p class="text-sm opacity-90 font-light">See all requests you have previously approved or rejected.</p>
    </a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-red-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-red-600 uppercase tracking-wider">Pending Your Review</p>
        <p class="text-5xl font-extrabold text-red-700 mt-2"><?php echo $pending_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Requires immediate action</p>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-teal-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-teal-600 uppercase tracking-wider">Approved by You</p>
        <p class="text-5xl font-extrabold text-teal-700 mt-2"><?php echo $approved_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">
            <?php 
            // ✅ AFO LOGIC: Update description for the 'Approved' count box
            if ($role === 'AFO') {
                echo 'Total requests with budget available and approved by you'; 
            } else {
                echo 'Total requests approved in your stage';
            }
            ?>
        </p>
    </div>

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

