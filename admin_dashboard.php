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

// 1. Determine which status column and notification status to monitor based on the role
$status_column = '';
$current_review_status = '';
$role_description = '';

// Define roles that review the 'requests' table (Funding/Standard requests)
$funding_review_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
// Define roles that review the 'venue_requests' table ONLY (or other tables)
$venue_only_roles = [
    'Admin Services',
    'CFDO',
    'VP for Academic Affairs',
    'VP for Administration'
];


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
    // --- START: Roles that DO NOT have status columns on the 'requests' table ---
    case 'Admin Services': // Assuming Admin Services role
        $status_column = 'admin_services_status'; // Only exists on venue_requests table
        $current_review_status = 'Awaiting Admin Services Approval'; // Used for Venue
        $role_description = 'Admin Services';
        break;
    case 'CFDO': // Assuming CFDO role
        $status_column = 'cfdo_status'; // Only exists on venue_requests table
        $current_review_status = 'Awaiting CFDO Approval'; // Used for Venue
        $role_description = 'CFDO';
        break;
    case 'VP for Academic Affairs': // Assuming VP for Academic Affairs role
        $status_column = 'VPAcad_status'; // Only exists on venue_requests table
        $current_review_status = 'Awaiting VP for Academic Affairs Approval'; // Used for Venue
        $role_description = 'VP for Academic Affairs';
        break;
    case 'VP for Administration': // Assuming VP for Administration role
        $status_column = 'VPAdmin_status'; // Only exists on venue_requests table
        $current_review_status = 'Awaiting VP for Administration Approval'; // Used for Venue
        $role_description = 'VP for Administration';
        break;
    // --- END: Roles that DO NOT have status columns on the 'requests' table ---
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

    // Check if the current role is one that needs organization filtering
    if ($role === 'Adviser' || $role === 'Dean') {
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
                    $placeholders = implode(',', array_fill(0, count($officer_ids), '?'));
                    $org_filter_clause = "AND r.user_id IN ($placeholders)";
                    $params = $officer_ids;
                    $types = str_repeat('i', count($officer_ids));
                }
            }
        }
    }

    // --- 2a. Funding/Standard Requests Count ---
    if (in_array($role, $funding_review_roles)) {
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
            // Use the new helper function for binding
            safe_bind_params($stmt, $types, $params);

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_bind_result($stmt, $r_total_reviewable, $r_pending_count, $r_approved_count, $r_rejected_count);
                mysqli_stmt_fetch($stmt);
                
                // Add requests counts to overall totals
                $total_reviewable += (int)$r_total_reviewable;
                $pending_count += (int)$r_pending_count;
                $approved_count += (int)$r_approved_count;
                $rejected_count += (int)$r_rejected_count;

            }
            mysqli_stmt_close($stmt);
        }
    }

    // --- 2b. VENUE REQUESTS COUNT (Counts Pending, Approved, and Rejected reliably) ---

    // Build organization clause for venue table correctly (uses vr.user_id)
    $org_filter_clause_venue = '';
    $venue_bind_params = [];
    $venue_bind_types = '';

    if ($role === 'Adviser' || $role === 'Dean') {
        if (!empty($params)) {
            // Re-use the organization parameters determined earlier
            $placeholders_v = implode(',', array_fill(0, count($params), '?'));
            $org_filter_clause_venue = "AND vr.user_id IN ($placeholders_v)";
            $venue_bind_params = $params;
            $venue_bind_types = $types;
        }
    }

    // Note: The original venue query logic is maintained here as it represents your application's business logic
    $venue_sql = "
        SELECT
            COALESCE(COUNT(vr.venue_request_id), 0) AS total_reviewable,
            COALESCE(SUM(
                CASE
                    WHEN (
                        vr.notification_status LIKE 'Awaiting%' OR
                        vr.admin_services_status = 'Pending' OR
                        vr.cfdo_status = 'Pending' OR
                        vr.vp_acad_status = 'Pending' OR
                        vr.vp_admin_status = 'Pending'
                    ) THEN 1 ELSE 0 END
            ), 0) AS pending,
            COALESCE(SUM(
                CASE
                    WHEN (
                        vr.notification_status LIKE '%Approved%' OR
                        vr.admin_services_status = 'Approved' OR
                        vr.cfdo_status = 'Approved' OR
                        vr.vp_acad_status = 'Approved' OR
                        vr.vp_admin_status = 'Approved' OR
                        vr.final_status = 'Approved'
                    ) THEN 1 ELSE 0 END
            ), 0) AS approved,
            COALESCE(SUM(
                CASE
                    WHEN (
                        vr.notification_status LIKE '%Rejected%' OR
                        vr.admin_services_status = 'Rejected' OR
                        vr.cfdo_status = 'Rejected' OR
                        vr.vp_acad_status = 'Rejected' OR
                        vr.vp_admin_status = 'Rejected' OR
                        vr.final_status = 'Rejected'
                    ) THEN 1 ELSE 0 END
            ), 0) AS rejected
        FROM venue_requests vr
        WHERE 1=1 $org_filter_clause_venue
    ";

    // Initialize counts to zero
    $venue_total = 0;
    $venue_pending_count = 0;
    $venue_approved_count = 0;
    $venue_rejected_count = 0;

    // Prepare + bind + execute
    if ($stmt_venue = mysqli_prepare($link, $venue_sql)) {
        // Use the new helper function for binding
        safe_bind_params($stmt_venue, $venue_bind_types, $venue_bind_params);

        if (mysqli_stmt_execute($stmt_venue)) {
            mysqli_stmt_bind_result($stmt_venue, $v_total, $v_pending, $v_approved, $v_rejected);

            // Fetch safely
            if (mysqli_stmt_fetch($stmt_venue)) {
                $venue_total = (int)$v_total;
                $venue_pending_count = (int)$v_pending;
                $venue_approved_count = (int)$v_approved;
                $venue_rejected_count = (int)$v_rejected;
            } else {
                // If query returns no rows, ensure counts are 0 (though COALESCE should handle this)
                $venue_total = 0;
                $venue_pending_count = 0;
                $venue_approved_count = 0;
                $venue_rejected_count = 0;
            }
            
            // Add venue counts to overall totals
            $total_reviewable += (int)$venue_total;
            $pending_count += (int)$venue_pending_count;
            $approved_count += (int)$venue_approved_count;
            $rejected_count += (int)$venue_rejected_count;

        }
        mysqli_stmt_close($stmt_venue);
    }
}

// Close DB connection
mysqli_close($link);

// Start the page using the template function
start_page("Admin Dashboard", $role, $full_name);

?>

<h2 class="text-5xl font-extrabold text-gray-900 mb-2">Welcome Back, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>!</h2>
<p class="text-xl text-gray-600 mb-10">
    Review Dashboard for the <span class="font-bold text-blue-700"><?php echo htmlspecialchars($role_description); ?></span>.
</p>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-12 max-w-4xl">
    <!-- Card 1: View Review Queue -->
    <a href="admin_request_list.php" class="bg-blue-600 hover:bg-blue-700 text-white p-8 rounded-xl shadow-2xl transition duration-300 transform hover:scale-[1.03] flex flex-col justify-center">
        <h3 class="text-3xl font-bold mb-1">View Review Queue</h3>
        <p class="text-sm opacity-90 font-light">See all requests currently pending your action.</p>
    </a>
    
    <!-- Card 2: View Request History (The missing button/link) -->
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
        <p class="text-xs text-gray-500 mt-2">Total requests approved in your stage</p>
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