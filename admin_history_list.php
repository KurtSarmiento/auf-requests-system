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
$date_column = ''; // Use date_updated as a proxy for decision date

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
        $status_column = 'afo_status';
        $role_description = 'AFO Head';
        $date_column = 'afo_decision_date';
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
$filter_clause = " (t.my_decision = 'Approved' OR t.my_decision = 'Rejected') ";

// Filter by Approved or Rejected if specified
if ($filter_status !== 'All') {
    $filter_clause = " t.my_decision = ? ";
}

// Add search clause: searches on request title (now available as t.title in the unified table)
if (!empty($search_term)) {
    $search_clause = " AND t.title LIKE ? ";
}

// Adviser/Dean Organization Filtering
$params = [];
$types = '';

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
                // Filter is applied against the 'user_id' column in the unified table 't'
                $org_filter_clause = " AND t.user_id IN ($placeholders) "; 
                $params = array_merge($params, $officer_ids);
                $types .= str_repeat('i', count($officer_ids));
            }
        }
    }
}


// --- 4. The Complex SQL Query (Funding Requests + Venue Requests) - **FIXED** ---
$sql_parts = [];

// 4.1. Funding/Standard Requests (Table 'requests')
$venue_only_roles = ['Admin Services', 'CFDO', 'VP for Administration'];

if (!empty($status_column) && !in_array($role, $venue_only_roles)) {
    // Note: 'activity_title' is aliased as 'title' and 'user_id' is included for outer filtering
    $sql_funding = "
        SELECT 
            r.request_id, 
            r.title AS title, 
            r.user_id, 
            u.full_name AS officer_name, 
            o.org_name, 
            r.type, 
            r.amount, 
            r.$status_column AS my_decision, 
            r.$date_column AS decision_date,
            'Funding' AS request_type_label 
        FROM requests r
        JOIN users u ON r.user_id = u.user_id
        JOIN organizations o ON u.org_id = o.org_id
        WHERE r.$status_column IN ('Approved', 'Rejected')
    ";
    $sql_parts[] = "({$sql_funding})";
}


// 4.2. Venue Requests (Table 'venue_requests') - CONDITIONAL INCLUSION APPLIED HERE
// Use the same status column name, but only include the query if the role is NOT Adviser.
if ($role !== 'Adviser' && !empty($status_column)) { 
    
    // Note: 'activity_title' is aliased as 'title' and 'user_id' is included for outer filtering
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
            'Venue' AS request_type_label
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
    $sql_union = "SELECT 1 AS request_id, 'No History' AS title, 1 AS user_id, 'N/A' AS officer_name, 'N/A' AS org_name, 'N/A' AS type, NULL AS amount, 'N/A' AS my_decision, NOW() AS decision_date, 'N/A' AS request_type_label LIMIT 0";
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

// 1. Filter Status Parameter (if not 'All')
if ($filter_status !== 'All') {
    $all_params[] = &$filter_status;
    $all_types .= 's';
}

// 2. Search Term Parameter
if (!empty($search_term)) {
    $all_params[] = &$safe_search;
    $all_types .= 's';
}

// 3. Organization IDs (Adviser/Dean only)
// Note: $params and $types were already built in the organization filtering block.
foreach ($params as $key => $value) {
    $all_params[] = &$params[$key];
}
$all_types .= $types;


// --- Execute Count Query ---
$total_records = 0;
if ($stmt_count = mysqli_prepare($link, $sql_count)) {
    // Bind all non-limit parameters for the count query
    if (!empty($all_types)) {
        $bind_count_params = array_merge([$stmt_count, $all_types], $all_params);
        // We use call_user_func_array here as the original code did, for dynamic binding.
        call_user_func_array('mysqli_stmt_bind_param', $bind_count_params); 
    }

    if (mysqli_stmt_execute($stmt_count)) {
        $result_count = mysqli_stmt_get_result($stmt_count);
        $row_count = mysqli_fetch_assoc($result_count);
        $total_records = (int) ($row_count['total_count'] ?? 0);
    }
    mysqli_stmt_close($stmt_count);
}
$total_pages = ceil($total_records / $records_per_page);


// --- Execute Final Data Query ---
$requests = [];
// Append LIMIT and OFFSET parameters
$all_params[] = &$records_per_page;
$all_params[] = &$offset;
$all_types .= 'ii';

if ($stmt = mysqli_prepare($link, $sql_final)) {
    // Prepare the final array of parameters for the main query
    $bind_final_params = array_merge([$stmt, $all_types], $all_params);
    
    if (!empty($all_types)) {
        call_user_func_array('mysqli_stmt_bind_param', $bind_final_params);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($link);

// Start the page using the template function
start_page("Completed Reviews History", $role, $full_name);
?>

<h2 class="text-4xl font-extrabold text-gray-900 mb-2">
    Completed Reviews
</h2>
<p class="text-xl text-gray-600 mb-6">
    History of requests you, the <span class="font-bold text-blue-700"><?php echo htmlspecialchars($role_description); ?></span>, have actioned.
</p>

<!-- Search and Filter Form -->
<div class="bg-gray-50 p-6 rounded-xl shadow-inner mb-6 border border-blue-100">
    <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
        
        <!-- Search Field -->
        <div class="flex-grow w-full md:w-auto">
            <label for="search" class="block text-sm font-medium text-gray-700">Search by Title/Officer</label>
            <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search_term); ?>" 
                    placeholder="Enter keyword" 
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border">
        </div>

        <!-- Status Filter -->
        <div class="w-full md:w-48">
            <label for="status" class="block text-sm font-medium text-gray-700">Filter by Decision</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 p-2 border bg-white">
                <option value="All" <?php if ($filter_status === 'All') echo 'selected'; ?>>All Decisions</option>
                <option value="Approved" <?php if ($filter_status === 'Approved') echo 'selected'; ?>>Approved</option>
                <option value="Rejected" <?php if ($filter_status === 'Rejected') echo 'selected'; ?>>Rejected</option>
            </select>
        </div>
        
        <!-- Submit Button -->
        <button type="submit" class="w-full md:w-auto px-4 py-2 bg-blue-600 text-white font-semibold rounded-md shadow-md hover:bg-blue-700 transition duration-150">
            Apply Filters
        </button>
        
        <!-- Clear Button -->
        <a href="admin_history_list.php" class="w-full md:w-auto px-4 py-2 bg-gray-300 text-gray-700 font-semibold rounded-md shadow-md hover:bg-gray-400 text-center transition duration-150">
            Clear
        </a>

    </form>
</div>

<!-- Results Table -->
<div class="bg-white p-6 rounded-xl shadow-xl overflow-x-auto">
    <div class="mb-4 text-sm text-gray-600">
        Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> records.
    </div>

    <?php if (empty($requests)): ?>
        <div class="p-8 text-center text-gray-500 bg-gray-50 rounded-lg border-2 border-dashed border-gray-200">
            <p class="font-semibold text-lg">No Completed Reviews Found</p>
            <p class="text-sm mt-1">Adjust your search or filter criteria.</p>
        </div>
    <?php else: ?>
        <table class="min-w-full divide-y divide-blue-200">
            <thead class="bg-blue-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">ID / Type</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Officer / Organization</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Title</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-blue-600 uppercase tracking-wider">Amount (PHP)</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-blue-600 uppercase tracking-wider">My Decision</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-blue-600 uppercase tracking-wider">Decision Date</th>
                    <th scope="col" class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                <?php foreach ($requests as $request): 
                    // Determine color for decision status
                    $decision_color = $request['my_decision'] === 'Approved' ? 'bg-teal-100 text-teal-800 border-teal-500' : 'bg-red-100 text-red-800 border-red-500';
                    $amount_display = is_numeric($request['amount']) ? '₱' . number_format($request['amount'], 2) : 'N/A';
                    $request_link = $request['request_type_label'] === 'Venue' ? 'venue_request_details.php?id=' : 'request_details.php?id=';
                ?>
                <tr class="hover:bg-blue-50 transition duration-100">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">#<?php echo htmlspecialchars($request['request_id']); ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($request['type']); ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['officer_name']); ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($request['org_name']); ?></div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" title="<?php echo htmlspecialchars($request['title']); ?>">
                        <?php echo htmlspecialchars($request['title']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-semibold text-gray-700">
                        <?php echo $amount_display; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <span class="inline-flex items-center px-3 py-0.5 rounded-full text-xs font-medium border-2 <?php echo $decision_color; ?>">
                            <?php echo htmlspecialchars($request['my_decision']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo date('M d, Y', strtotime($request['decision_date'])); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <a href="<?php echo $request_link . $request['request_id']; ?>" 
                            class="text-blue-600 hover:text-blue-900 font-semibold transition duration-150">View Details</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Pagination Controls -->
<div class="mt-6 flex justify-between items-center">
    <?php 
        $query_params = http_build_query(['search' => $search_term, 'status' => $filter_status]);
    ?>
    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
        <!-- Previous Page Button -->
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&<?php echo $query_params; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                <span class="sr-only">Previous</span>
                <!-- Heroicon name: solid/chevron-left -->
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
            </a>
        <?php else: ?>
             <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                 <span class="sr-only">Previous</span>
                 <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                     <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                 </svg>
             </span>
        <?php endif; ?>

        <!-- Page Numbers (Simplified) -->
        <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-blue-600 text-sm font-semibold text-white">
            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
        </span>

        <!-- Next Page Button -->
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?>&<?php echo $query_params; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                <span class="sr-only">Next</span>
                <!-- Heroicon name: solid/chevron-right -->
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                </svg>
            </a>
        <?php else: ?>
             <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-100 text-sm font-medium text-gray-400 cursor-not-allowed">
                 <span class="sr-only">Next</span>
                 <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                     <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                 </svg>
             </span>
        <?php endif; ?>
    </nav>
    <div class="text-sm text-gray-600">
        Total Results: <span class="font-semibold text-gray-900"><?php echo $total_records; ?></span>
    </div>
</div>


<?php
end_page();
?>
