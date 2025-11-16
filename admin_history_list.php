<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php";

// Check if the user is logged in and is a Signatory (Admin)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] === 'Officer') {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];

// 1. Determine Status Column and Role Description
$status_column = '';
$role_description = '';
$date_column = ''; // This will now hold the specific decision date column name

switch ($role) {
    case 'Adviser':
        $status_column = 'adviser_status';
        $role_description = 'Organization Adviser';
        $date_column = 'adviser_decision_date';
        break;
    case 'Dean':
        $status_column = 'dean_status';
        $role_description = 'College Dean';
        $date_column = 'dean_decision_date';
        break;
    case 'OSAFA':
        // OSAFA handles both funding requests ('requests') and venue requests ('venue_requests')
        $status_column = 'osafa_status';
        $role_description = 'OSAFA Head';
        $date_column = 'osafa_decision_date';
        break;
    case 'AFO':
        $status_column = 'afo_status'; // Still needed for the WHERE clause
        $role_description = 'AFO Head';
        // Gï¿½ï¿½ AFO LOGIC: Date column logic moved to SQL SELECT
        $date_column = 'afo_decision_date'; // Placeholder
        break;
    case 'Admin Services':
        $status_column = 'admin_services_status';
        $role_description = 'Admin Services Head';
        $date_column = 'admin_services_decision_date';
        break;
    case 'CFDO':
        $status_column = 'cfdo_status';
        $role_description = 'CFDO Head';
        $date_column = 'cfdo_decision_date';
        break;
    case 'VP for Academic Affairs':
        $status_column = 'vp_acad_status';
        $role_description = 'VP for Academic Affairs';
        $date_column = 'vp_acad_decision_date';
        break;
    case 'VP for Administration':
        $status_column = 'vp_admin_status';
        $role_description = 'VP for Administration';
        $date_column = 'vp_admin_decision_date';
        break;
    default:
        $status_column = '';
        $role_description = 'Administrator';
        $date_column = 'date_updated'; // Fallback only if role is somehow misconfigured
}

// Gï¿½ï¿½ ADDED: Helper function for status colors


// 2. Search, Filter, and Pagination Setup
$search_term = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? 'All'; // Approved, Rejected, or All
$page = (int) ($_GET['page'] ?? 1);
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

// 3. Build SQL Clauses
$search_clause = '';
$filter_clause = '';
$org_filter_clause = '';

// Prepare search term for LIKE query
$safe_search = "%" . $search_term . "%";

// Base WHERE clause for Approved/Rejected status in the current admin's column
// Gï¿½ï¿½ Use a dedicated param array for the filter
$params_filter = [];
// Gï¿½ï¿½ NEW: Need to track types for the filter
$types_filter = ''; 

// ===================================
// === START OF BUG FIX 1 (AFO FILTER) ===
// ===================================
if ($role === 'AFO') {
    // History = 'Budget Available' (funding), 'Rejected' (either), or 'Approved' (venue)
    if ($filter_status === 'All') {
        $filter_clause = " (t.my_decision = 'Budget Available' OR t.my_decision = 'Rejected' OR t.my_decision = 'Approved') ";
    } 
    // 'Approved' for AFO means BOTH funding and venue approvals
    elseif ($filter_status === 'Approved') {
        $filter_clause = " (t.my_decision = 'Budget Available' OR t.my_decision = 'Approved') ";
        // No parameters needed for this specific case
    } 
    // Filter is 'Rejected'
    else {
        $filter_clause = " t.my_decision = ? ";
        $params_filter[] = &$filter_status; // Use reference as user did
        $types_filter .= 's';
    }
} else {
    // Standard logic for other roles
    if ($filter_status === 'All') {
        $filter_clause = " (t.my_decision = 'Approved' OR t.my_decision = 'Rejected') ";
    } else {
        $filter_clause = " t.my_decision = ? ";
        $params_filter[] = &$filter_status; // Use reference as user did
        $types_filter .= 's';
    }
}
// ===================================
// === END OF BUG FIX 1 ===
// ===================================


// Add search clause: searches on request title
if (!empty($search_term)) {
    $search_clause = " AND t.title LIKE ? ";
}

// Adviser/Dean Organization Filtering
$params_org = [];
$types_org = '';

if ($role === 'Adviser' || $role === 'Dean') {
    // Retrieve the admin's associated organization ID
    $org_id = null;
    $org_id_sql = "SELECT org_id FROM users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($link, $org_id_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $org_id);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }

    if ($org_id) {
        // Find all officers belonging to this organization
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
                $org_filter_clause = " AND t.user_id IN ($placeholders) "; 
                $params_org = $officer_ids; // Store directly
                $types_org .= str_repeat('i', count($officer_ids));
            } else {
                // No officers in org, ensure no results are returned
                $org_filter_clause = " AND t.user_id = -1 ";
            }
        }
    }
}


// --- 4. The Complex SQL Query (Funding Requests + Venue Requests) ---
$sql_parts = [];

// 4.1. Funding/Standard Requests (Table 'requests')
// Roles allowed to see funding history
$funding_history_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
if (in_array($role, $funding_history_roles)) {
    
    // Gï¿½ï¿½ AFO LOGIC: Special WHERE clause and SELECT logic for AFO history
    $funding_where_clause = "";
    $status_column_to_select = "r.$status_column"; // Default
    $date_column_to_select = "r.$date_column";     // Default

    if ($role === 'AFO') {
        // History = Rejected (afo_status) OR Budget Available (final_status)
        // Note: Budget Processing items are NOT history yet.
        $funding_where_clause = "WHERE (r.afo_status = 'Rejected' OR r.final_status = 'Budget Available' OR (r.final_status = 'Approved' AND r.type = 'Liquidation Report'))";
        // Use 'final_status' if available, otherwise 'afo_status'
        $status_column_to_select = "IF(r.final_status IN ('Budget Available', 'Approved'), r.final_status, r.afo_status)"; 
        // Use 'date_budget_available' for approved, 'afo_decision_date' for rejected
        // NOTE: Make sure `date_budget_available` column exists and is DATETIME, NULLable
        $date_column_to_select = "IF(r.final_status = 'Budget Available', r.date_budget_available, r.afo_decision_date)";
    } else {
        // Standard WHERE for other roles
        $funding_where_clause = "WHERE r.$status_column IN ('Approved', 'Rejected')";
    }
    
    // Gï¿½ï¿½ This SQL is correct, it selects r.type which we need for the fix
    $sql_funding = "
        SELECT 
            r.request_id, 
            r.title AS title, 
            r.user_id, 
            u.full_name AS officer_name, 
            o.org_name, 
            r.type, 
            r.amount, 
            {$status_column_to_select} AS my_decision, 
            {$date_column_to_select} AS decision_date,
            'Funding' AS request_type_label,
            r.date_submitted
        FROM requests r
        JOIN users u ON r.user_id = u.user_id
        JOIN organizations o ON u.org_id = o.org_id
        {$funding_where_clause}
    ";
    $sql_parts[] = "({$sql_funding})";
}


// 4.2. Venue Requests (Table 'venue_requests')
// Roles allowed to see venue history
$venue_history_roles = ['Dean', 'Admin Services', 'OSAFA', 'CFDO', 'AFO', 'VP for Academic Affairs', 'VP for Administration'];
if (in_array($role, $venue_history_roles)) { 
    
    // Note: AFO/Dean/OSAFA venue requests use the standard Approved/Rejected logic
    
    $sql_venue = "
        SELECT 
            vr.venue_request_id AS request_id, 
            vr.title AS title, 
            vr.user_id, 
            u.full_name AS officer_name, 
            o.org_name, 
            'Venue Request' AS type, 
            NULL AS amount, 
            vr.$status_column AS my_decision, 
            vr.$date_column AS decision_date,
            'Venue' AS request_type_label,
            vr.date_submitted
        FROM venue_requests vr
        JOIN users u ON vr.user_id = u.user_id
        JOIN organizations o ON u.org_id = o.org_id
        WHERE vr.$status_column IN ('Approved', 'Rejected')
    ";
    $sql_parts[] = "({$sql_venue})";
}


// 4.3. Combine the parts with UNION ALL
$sql_union = implode("\n UNION ALL \n", $sql_parts);

if (empty($sql_union)) {
    // Fallback if no relevant history exists for the role
    $sql_union = "SELECT 1 AS request_id, 'No History' AS title, 1 AS user_id, 'N/A' AS officer_name, 'N/A' AS org_name, 'N/A' AS type, NULL AS amount, 'N/A' AS my_decision, NOW() AS decision_date, 'N/A' AS request_type_label, NOW() as date_submitted LIMIT 0";
}


// The final SELECT query applies the filter, search, and org clauses to the unified table 't'
$sql_final = "
    SELECT t.* FROM ({$sql_union}) AS t
    WHERE 1=1
    AND {$filter_clause}
    {$search_clause}
    {$org_filter_clause}
    ORDER BY t.decision_date DESC
    LIMIT ? OFFSET ?
";

// --- Count Query for Pagination ---
$sql_count = "
    SELECT COUNT(*) AS total_count FROM ({$sql_union}) AS t
    WHERE 1=1
    AND {$filter_clause}
    {$search_clause}
    {$org_filter_clause}
";

// --- Prepare Parameters and Types for Execution ---
$all_params = [];
$all_types = '';

// ===================================
// === START OF BUG FIX 2 (PARAM BINDING) ===
// ===================================
// 1. Filter Status Parameter (if it exists)
if (!empty($params_filter)) {
    foreach ($params_filter as $key => $value) {
        $all_params[] = &$params_filter[$key];
    }
    $all_types .= $types_filter; // Use the types string we saved
}
// ===================================
// === END OF BUG FIX 2 ===
// ===================================

// 2. Search Term Parameter
if (!empty($search_term)) {
    $all_params[] = &$safe_search;
    $all_types .= 's';
}

// 3. Organization IDs (Adviser/Dean only)
foreach ($params_org as $key => $value) {
    $all_params[] = &$params_org[$key]; // Use the org params directly
}
$all_types .= $types_org;


// --- Execute Count Query ---
$total_records = 0;
if ($stmt_count = mysqli_prepare($link, $sql_count)) {
    // Bind all non-limit parameters for the count query
    if (!empty($all_types)) {
        $bind_count_params = array_merge([$stmt_count, $all_types], $all_params);
        call_user_func_array('mysqli_stmt_bind_param', $bind_count_params); 
    }

    if (mysqli_stmt_execute($stmt_count)) {
        $result_count = mysqli_stmt_get_result($stmt_count);
        $row_count = mysqli_fetch_assoc($result_count);
        $total_records = (int) ($row_count['total_count'] ?? 0);
    } else {
        error_log("History Count Query Error: " . mysqli_stmt_error($stmt_count));
    }
    mysqli_stmt_close($stmt_count);
} else {
     error_log("History Count Prepare Error: " . mysqli_error($link));
}
$total_pages = ceil($total_records / $records_per_page);


// --- Execute Final Data Query ---
$requests = [];
// Append LIMIT and OFFSET parameters
$limit_param = $records_per_page; // Create non-reference variable
$offset_param = $offset;         // Create non-reference variable
$all_params[] = &$limit_param;
$all_params[] = &$offset_param;
$all_types .= 'ii';

if ($stmt = mysqli_prepare($link, $sql_final)) {
    // Prepare the final array of parameters for the main query
    if (!empty($all_types)) {
        $bind_final_params = array_merge([$stmt, $all_types], $all_params);
        call_user_func_array('mysqli_stmt_bind_param', $bind_final_params);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
    } else {
         error_log("History Data Query Error: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
} else {
    error_log("History Data Prepare Error: " . mysqli_error($link));
}

mysqli_close($link);

// Start the page using the template function
start_page("Completed Reviews History", $role, $full_name);
?>

<div class="space-y-8 py-4">
    <section class="page-hero">
        <span class="hero-pill">History</span>
        <h2 class="hero-title">Completed reviews for <?php echo htmlspecialchars($role_description); ?>.</h2>
        <p class="hero-subtext">Search by title or filter by decision to reference previous approvals and returns.</p>
    </section>

    <div class="subtle-card">
        <form method="GET" action="admin_history_list.php" class="grid gap-4 md:grid-cols-3">
            <div>
                <label for="search" class="text-xs uppercase tracking-[0.4em] text-slate-500">Search</label>
                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>"
                       placeholder="Enter keyword"
                       class="input-modern w-full px-4 py-3 mt-2">
            </div>
            <div>
                <label for="status" class="text-xs uppercase tracking-[0.4em] text-slate-500">Decision</label>
                <select id="status" name="status" class="input-modern w-full px-4 py-3 mt-2 bg-white/70">
                    <option value="All" <?php if ($filter_status === 'All') echo 'selected'; ?>>All</option>
                    <option value="Approved" <?php if ($filter_status === 'Approved') echo 'selected'; ?>>
                        <?php echo ($role === 'AFO') ? 'Budget Available / Approved' : 'Approved'; ?>
                    </option>
                    <option value="Rejected" <?php if ($filter_status === 'Rejected') echo 'selected'; ?>>Rejected</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="btn-primary w-full">Apply filters</button>
            </div>
        </form>
    </div>

    <?php if (empty($requests)): ?>
        <div class="subtle-card">
            <p class="hero-subtext">No records yet.</p>
            <p class="text-sm text-slate-500 mt-1">Completed reviews will appear here after you approve or return a request.</p>
        </div>
    <?php else: ?>
        <div class="table-shell overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Officer</th>
                        <th>Request</th>
                        <th>Amount</th>
                        <th>Decision</th>
                        <th>Date</th>
                        <th class="text-right">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request):
                        $display_decision = $request['my_decision'];
                        if ($role === 'AFO' && $display_decision === 'Budget Available' && $request['request_type_label'] === 'Liquidation Report') {
                            $display_decision = 'Approved';
                        }
                        $decision_class = 'status-chip pending';
                        if ($display_decision === 'Rejected') {
                            $decision_class = 'status-chip rejected';
                        } elseif (in_array($display_decision, ['Approved', 'Budget Available'])) {
                            $decision_class = 'status-chip approved';
                        }
                        $amount_display = is_numeric($request['amount']) ? '&#8369;' . number_format($request['amount'], 2) : 'N/A';
                        $request_link = ($request['request_type_label'] === 'Venue' ? 'venue_request_details.php?id=' : 'request_details.php?id=') . $request['request_id'];
                        $decision_date_display = 'N/A';
                        if (!empty($request['decision_date'])) {
                            $timestamp = strtotime($request['decision_date']);
                            if ($timestamp !== false && $timestamp > 0) {
                                $decision_date_display = date('M d, Y', $timestamp);
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="text-sm font-semibold text-slate-900">#<?php echo htmlspecialchars($request['request_id']); ?></div>
                            <div class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($request['type']); ?></div>
                        </td>
                        <td>
                            <div class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($request['officer_name']); ?></div>
                            <div class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($request['org_name']); ?></div>
                        </td>
                        <td>
                            <p class="text-sm text-slate-700 max-w-xs" title="<?php echo htmlspecialchars($request['title']); ?>"><?php echo htmlspecialchars($request['title']); ?></p>
                        </td>
                        <td>
                            <p class="text-sm font-semibold text-slate-900 text-right"><?php echo $amount_display; ?></p>
                        </td>
                        <td class="text-center">
                            <span class="<?php echo $decision_class; ?>"><?php echo htmlspecialchars($display_decision); ?></span>
                        </td>
                        <td>
                            <p class="text-sm text-slate-600"><?php echo $decision_date_display; ?></p>
                        </td>
                        <td class="text-right">
                            <a href="<?php echo $request_link; ?>" class="detail-link">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <?php 
            $query_params_array = [];
            if (!empty($search_term)) $query_params_array['search'] = $search_term;
            if ($filter_status !== 'All') $query_params_array['status'] = $filter_status;
            $query_params = http_build_query($query_params_array);
            if (!empty($query_params)) $query_params = '&' . $query_params;
        ?>
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?><?php echo $query_params; ?>" class="filter-pill">Prev</a>
            <?php else: ?>
                <span class="filter-pill" style="opacity:0.5;">Prev</span>
            <?php endif; ?>
            <span class="hero-pill">Page <?php echo $page; ?> of <?php echo max(1, $total_pages); ?></span>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $query_params; ?>" class="filter-pill">Next</a>
            <?php else: ?>
                <span class="filter-pill" style="opacity:0.5;">Next</span>
            <?php endif; ?>
        </div>
        <p class="text-sm text-slate-600">Total Results: <span class="font-semibold text-slate-900"><?php echo $total_records; ?></span></p>
    </div>
</div>

<?php
end_page();
?><?php
end_page();
?>




