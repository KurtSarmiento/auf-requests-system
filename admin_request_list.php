<?php
// Initialize the session tite puke
session_start(); // ✅ Required to track logged-in user

// Check if the user is logged in and NOT an Officer (i.e., a Signatory)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}


require_once "db_config.php";

// === ADMIN ROLE NORMALIZATION AND CHECK ===
$admin_roles = [
    'Adviser', 'Dean', 'Admin Services', 'OSAFA', 'CFDO', 'AFO',
    'VP for Academic Affairs', 'VP for Administration'
];

if (!function_exists('normalize_role')) {
    function normalize_role($r) {
        if (!is_string($r)) return '';
        $s = trim(strtolower($r));
        return preg_replace('/\s+/', ' ', $s);
    }
}

$currentUserRole = '';
if (!empty($_SESSION['role'])) {
    $currentUserRole = normalize_role($_SESSION['role']);
}

$normalizedAdminRoles = array_map('normalize_role', $admin_roles);
$is_admin = in_array($currentUserRole, $normalizedAdminRoles, true);

$current_role = $_SESSION["role"];
$user_org_id = isset($_SESSION["org_id"]) ? (int)$_SESSION["org_id"] : 0;

// =========================================================================================
// !!! ROLE MAPPING USING SWITCH (PHP 7.x Compatible) !!!
// =========================================================================================
$role_map = [null, null, null, null];

switch($current_role) {
    case 'Adviser':
        $role_map = ['adviser_status', null, null, 'requests'];
        break;
    case 'Dean':
        $role_map = ['dean_status', 'adviser_status', null, 'both'];
        break;
    case 'Admin Services':
        $role_map = ['admin_services_status', null, 'dean_status', 'venue_requests'];
        break;
    case 'OSAFA':
        $role_map = ['osafa_status', 'dean_status', 'admin_services_status', 'both'];
        break;
    case 'CFDO':
        $role_map = ['cfdo_status', null, 'osafa_status', 'venue_requests'];
        break;
    case 'AFO':
        // =======================================================================
        // !!! BUG FIX 1 (Corrected) !!!
        // AFO waits for OSAFA (funding) AND CFDO (venue)
        // =======================================================================
        $role_map = ['afo_status', 'osafa_status', 'cfdo_status', 'both'];
        break;
    case 'VP for Academic Affairs':
        // =======================================================================
        // !!! BUG FIX 2 (Corrected) !!!
        // VP for Academic Affairs waits for AFO (venue)
        // =======================================================================
        $role_map = ['vp_acad_status', null, 'afo_status', 'venue_requests'];
        break;
    case 'VP for Administration':
        $role_map = ['vp_admin_status', null, 'vp_acad_status', 'venue_requests'];
        break;
    default:
        break;
}

$role_column = $role_map[0];
$funding_previous_role_column = $role_map[1];
$venue_previous_role_column = $role_map[2];
$target_tables = $role_map[3];

if (!$role_column) {
    error_log("Error: Unrecognized administrative role '{$current_role}' in admin_request_list.php");
    $error_message = "Error: Your role is not configured correctly. Please contact the administrator.";
    $requests = [];
} else {
    $requests = [];
    $error_message = "";
    $funding_sql = "";
    $venue_sql = "";
}

// Function for CSS class
if (!function_exists('get_status_class')) {
    function get_status_class($status) {
        switch ($status) {
            case 'Approved':
            case 'Venue Approved':
                return 'bg-green-100 text-green-800 border-green-500';
            case 'Rejected':
                return 'bg-red-100 text-red-800 border-red-500';
            case 'Under Review':
            case 'Awaiting Dean Approval':
            case 'Awaiting Admin Services Approval':
            case 'Awaiting OSAFA Approval':
            case 'Awaiting CFDO Approval':
            case 'Awaiting AFO Approval':
            case 'Awaiting VP for Academic Affairs Approval':
            case 'Awaiting VP for Administration Approval':
                return 'bg-yellow-100 text-yellow-800 border-yellow-500';
            case 'Budget Available':
                return 'bg-purple-100 text-purple-800 border-purple-500 font-bold';
            default:
                return 'bg-blue-100 text-blue-800 border-blue-500';
        }
    }
}

// =================================================================
// !!! BUG FIX AREA: Correct Organization Filter Logic !!!
// This replaces your old filter logic.
// =================================================================
$org_filter_sql = "";
if ($current_role === 'Adviser') {
    // Advisers MUST be tied to an org
    if ($user_org_id > 0) {
        $org_filter_sql = " AND o.org_id = " . (int)$user_org_id;
    } else {
        $error_message = "Your Adviser account is not linked to an organization. Please contact support.";
    }
} elseif ($current_role === 'Dean') {
    // Deans are OPTIONALLY tied to an org. If they are, filter. If not (org_id=0), they see all.
    if ($user_org_id > 0) {
        $org_filter_sql = " AND o.org_id = " . (int)$user_org_id;
    }
    // If org_id is 0, $org_filter_sql remains "" and no error is thrown. This is correct.
}
// Other roles (OSAFA, AFO, etc.) are not filtered by org, so $org_filter_sql remains "".


// --- 2. Funding Requests SQL ---
if (empty($error_message) && in_array($target_tables, ['requests', 'both'])) {
    $funding_previous_filter = ($funding_previous_role_column !== null)
        ? " AND r.{$funding_previous_role_column} = 'Approved'" : "";

    $funding_where_clause = "r.{$role_column} = 'Pending' {$funding_previous_filter} {$org_filter_sql}";

    $funding_sql = "
        (SELECT
            r.request_id,
            r.title,
            r.amount,
            r.date_submitted,
            r.adviser_status,
            r.dean_status,
            r.osafa_status,
            r.afo_status,
            NULL AS admin_services_status,
            NULL AS cfdo_status,
            NULL AS vp_acad_status,
            NULL AS vp_admin_status,
            r.notification_status,
            o.org_name,
            u.full_name AS submitted_by,
            'Funding' AS request_type,
            r.request_id AS review_link_id,
            r.type AS funding_type
        FROM requests r
        JOIN users u ON r.user_id = u.user_id
        JOIN organizations o ON u.org_id = o.org_id
        WHERE {$funding_where_clause})
    ";
}

// --- 3. Venue Requests SQL ---
if (empty($error_message) && in_array($target_tables, ['venue_requests', 'both'])) {
    $venue_previous_filter = ($venue_previous_role_column !== null)
        ? " AND vr.{$venue_previous_role_column} = 'Approved'" : "";

    $venue_where_clause = "vr.{$role_column} = 'Pending' {$venue_previous_filter} {$org_filter_sql}";
    $union_prefix = !empty($funding_sql) ? " UNION ALL " : "";

    $venue_sql = "
    {$union_prefix}
    (SELECT
        vr.venue_request_id AS request_id,
        vr.title,
        0.00 AS amount,
        vr.date_submitted,
        NULL AS adviser_status,
        vr.dean_status,
        vr.osafa_status,
        vr.afo_status,
        vr.admin_services_status,
        vr.cfdo_status,
        vr.vp_acad_status,
        vr.vp_admin_status,
        vr.notification_status,
        o.org_name,
        u.full_name AS submitted_by,
        'Venue' AS request_type,
        vr.venue_request_id AS review_link_id,
        'Venue Request' AS funding_type
    FROM venue_requests vr
    JOIN users u ON vr.user_id = u.user_id
    JOIN organizations o ON u.org_id = o.org_id
    WHERE {$venue_where_clause})
";
}

// --- 4. Final Query Assembly & Execution ---
if (empty($error_message)) {
    $sql = trim("{$funding_sql} {$venue_sql} ORDER BY date_submitted ASC");

    if (empty($sql)) {
        // This is not an error, it just means the queue is empty.
        $requests = [];
    } else {
        // ✅ Converted from prepared statement to mysqli_query()
        $result = mysqli_query($link, $sql);    
        if ($result) {
            $requests = mysqli_fetch_all($result, MYSQLI_ASSOC);
            mysqli_free_result($result);
        } else {
            $error_message = "Query Error: " . mysqli_error($link);
            error_log("SQL Error in admin_request_list.php: " . mysqli_error($link) . " | SQL: " . $sql);
        }
    }
}

if (isset($link) && $link instanceof mysqli) {
    mysqli_close($link);
}
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
            font-size: 0.65rem;
            font-weight: 600;
            border: 1px solid;
            display: inline-block;
            margin: 2px 0;
            line-height: 1;
        }
        .type-tag {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            line-height: 1;
            margin-bottom: 4px;
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Admin Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="admin_dashboard.php" class="hover:text-indigo-200">Dashboard</a>
            <a href="admin_request_list.php" class="text-yellow-300 font-bold">Review Queue</a>
            <span class="text-sm font-light">Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b></span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <h2 class="text-3xl font-extrabold text-gray-900 mb-6">
            Requests Awaiting <?php echo htmlspecialchars($current_role); ?> Review
        </h2>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
                <p class="font-bold">Error</p>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (empty($requests) && empty($error_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-6 rounded-xl shadow-md">
                <p class="font-bold text-xl">Review Queue Clear! 🎉</p>
                <p class="mt-2">There are no pending requests requiring your <?php echo htmlspecialchars($current_role); ?> approval.</p>
            </div>
        <?php elseif (!empty($requests)): ?>
            <div class="bg-white shadow-xl overflow-hidden sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type / Title</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization / Submitted By</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount / Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Approval Status</th>
                            <th class="relative px-6 py-3"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($requests as $request):
                            $type_tag_class = ($request['request_type'] === 'Funding') ? 'bg-indigo-500 text-white' : 'bg-pink-500 text-white';
                            $review_page = ($request['request_type'] === 'Funding') ? 'admin_review.php' : 'admin_venue_review.php';
                            
                            // Determine display type
                            $display_type = ($request['request_type'] === 'Venue') ? 'Venue' : $request['funding_type']; // Budget, Liquidation, etc.

                        ?>
                        <tr class="hover:bg-indigo-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="type-tag <?php echo $type_tag_class; ?>"><?php echo htmlspecialchars($display_type); ?></span>
                                <div class="text-sm font-bold text-indigo-600">#<?php echo htmlspecialchars($request['request_id']); ?></div>
                                <div class="text-sm font-medium text-gray-900 truncate max-w-xs"><?php echo htmlspecialchars($request['title']); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($request['org_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($request['submitted_by']); ?></div>

                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($request['request_type'] === 'Funding'): ?>
                                    <div class="text-sm font-semibold text-gray-900">₱<?php echo number_format((float)$request['amount'], 2); ?></div>
                                <?php else: ?>
                                    <div class="text-sm font-semibold text-gray-400">Venue Request</div>
                                <?php endif; ?>
                                <div class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($request['date_submitted'])); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php
                                $status_columns = [
                                    'adviser_status' => 'ADVISER',
                                    'dean_status' => 'DEAN',
                                    'admin_services_status' => 'ADMIN SVC',
                                    'osafa_status' => 'OSAFA',
                                    'cfdo_status' => 'CFDO',
                                    'afo_status' => 'AFO',
                                    'vp_acad_status' => 'VP ACAD',
                                    'vp_admin_status' => 'VP ADMIN',
                                ];
                                foreach ($status_columns as $col_name => $label) {
                                    $status_value = isset($request[$col_name]) ? $request[$col_name] : NULL;
                                    if ($status_value !== NULL && $col_name !== $role_column) {
                                        echo '<span class="status-pill ' . get_status_class($status_value) . '">' .
                                             htmlspecialchars($label) . ': ' . htmlspecialchars($status_value) . '</span>';
                                    }
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="<?php echo htmlspecialchars($review_page); ?>?id=<?php echo htmlspecialchars($request['review_link_id']); ?>" class="text-indigo-600 hover:text-indigo-900 font-semibold">Review & Decide →</a>
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