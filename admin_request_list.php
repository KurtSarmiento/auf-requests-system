<?php
// Initialize the session tite puke
session_start(); // âœ… Required to track logged-in user

// Check if the user is logged in and NOT an Officer (i.e., a Signatory)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}


require_once "db_config.php";
require_once "layout_template.php";

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
// âœ… MODIFIED TO INCLUDE NEW STATUSES
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
            case 'Pending': // Added Pending here explicitly
                return 'bg-yellow-100 text-yellow-800 border-yellow-500';
            case 'Available': // âœ… NEW
                return 'bg-emerald-100 text-emerald-800 border-emerald-500 font-bold';
            case 'Budget Processing': // âœ… NEW
                return 'bg-blue-100 text-blue-800 border-blue-500';
            default:
                return 'bg-gray-100 text-gray-800 border-gray-500'; // Default fallback
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
    
    // âœ… MODIFIED AFO WHERE CLAUSE
    $funding_where_clause = "";
    if ($current_role === 'AFO') {
        // AFO sees requests where afo_status is Pending OR final_status is Budget Processing
        $funding_where_clause = "(r.{$role_column} = 'Pending' OR r.final_status = 'Budget Processing')";
        // Must still ensure previous step (OSAFA) is approved
        if ($funding_previous_role_column) {
            $funding_where_clause .= " AND r.{$funding_previous_role_column} = 'Approved'";
        }
    } else {
        // Standard logic for other roles
        $funding_where_clause = "r.{$role_column} = 'Pending'";
        if ($funding_previous_role_column) {
            $funding_where_clause .= " AND r.{$funding_previous_role_column} = 'Approved'";
        }
    }
    
    // Add org filter if applicable
    $funding_where_clause .= $org_filter_sql;

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
            r.final_status, -- âœ… ADDED final_status
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
    
    // Standard logic for venue requests (no budget processing)
    $venue_where_clause = "vr.{$role_column} = 'Pending'";
    if ($venue_previous_role_column !== null) {
        $venue_where_clause .= " AND vr.{$venue_previous_role_column} = 'Approved'";
    }
    
    // Add org filter if applicable
    $venue_where_clause .= $org_filter_sql;
    
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
        vr.final_status, -- âœ… ADDED final_status
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
        // âœ… Converted from prepared statement to mysqli_query()
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

$queue_total = is_array($requests) ? count($requests) : 0;
$_SESSION['review_queue_count'] = max(0, (int)$queue_total);
$funding_total = 0;
$venue_total = 0;
$oldest_submission = null;

if (!empty($requests)) {
    foreach ($requests as $req) {
        if (($req['request_type'] ?? 'Funding') === 'Funding') {
            $funding_total++;
        } else {
            $venue_total++;
        }
        $submitted = strtotime($req['date_submitted']);
        if ($submitted && ($oldest_submission === null || $submitted < $oldest_submission)) {
            $oldest_submission = $submitted;
        }
    }
}
?>

<?php start_page("Admin Review Queue", $current_role, $_SESSION["full_name"], $queue_total); ?>
<div class="space-y-8 py-4">
    <section class="page-hero">
        <div class="grid gap-6 lg:grid-cols-2 lg:items-center">
            <div>
                <span class="hero-pill">Review Queue</span>
                <h2 class="hero-title">Requests awaiting <?php echo htmlspecialchars($current_role); ?>.</h2>
                <p class="hero-subtext">Funding packages and venue bookings sit in one translucent queue once prerequisites sign off.</p>
                <div class="hero-actions">
                    <?php if ($oldest_submission): ?>
                        <span class="filter-pill">Oldest submission <?php echo date('M d, Y', $oldest_submission); ?></span>
                    <?php endif; ?>
                </div>
            </div>
    </section>

    <div class="stat-grid">
        <div class="stat-card stat-card--accent">
            <p class="stat-card__label">Total queue</p>
            <p class="stat-card__value"><?php echo $queue_total; ?></p>
            <p class="stat-card__meta">Awaiting your decision</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Funding</p>
            <p class="stat-card__value"><?php echo $funding_total; ?></p>
            <p class="stat-card__meta">Budget / liquidation / reimbursement</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Venue</p>
            <p class="stat-card__value"><?php echo $venue_total; ?></p>
            <p class="stat-card__meta">Facility bookings</p>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="subtle-card" style="background: rgba(255, 228, 230, 0.6); border-color: rgba(244, 63, 94, 0.3);">
            <p class="text-sm font-semibold text-rose-700">Error</p>
            <p class="text-sm text-rose-600 mt-2"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php elseif (empty($requests)): ?>
        <div class="subtle-card">
            <p class="hero-subtext">Nothing to review.</p>
            <p class="text-sm text-slate-500 mt-1">There are no pending requests requiring your approval.</p>
        </div>
    <?php else: ?>
        <div class="table-shell overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Request</th>
                        <th>Organization</th>
                        <th>Amount</th>
                        <th>Submitted</th>
                        <th>Routing Status</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request):
                        $is_funding = ($request['request_type'] ?? 'Funding') === 'Funding';
                        $review_page = $is_funding ? 'admin_review.php' : 'admin_venue_review.php';
                        $display_type = $is_funding ? ($request['funding_type'] ?? 'Funding') : 'Venue';
                    ?>
                    <tr>
                        <td>
                            <div class="flex flex-col gap-1">
                                <span class="type-chip"><?php echo htmlspecialchars($display_type); ?></span>
                                <span class="stage-chip">#<?php echo htmlspecialchars($request['request_id']); ?></span>
                            </div>
                        </td>
                        <td>
                            <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($request['title']); ?></p>
                        </td>
                        <td>
                            <p class="font-semibold text-slate-900"><?php echo htmlspecialchars($request['org_name']); ?></p>
                            <p class="text-xs text-slate-500 mt-0.5"><?php echo htmlspecialchars($request['submitted_by']); ?></p>
                        </td>
                        <td>
                            <?php if ($is_funding): ?>
                                <p class="font-semibold text-emerald-700">&#8369;<?php echo number_format((float)$request['amount'], 2); ?></p>
                            <?php else: ?>
                                <p class="text-sm text-slate-500">N/A</p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <p class="text-sm text-slate-700"><?php echo date('M d, Y', strtotime($request['date_submitted'])); ?></p>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <?php 
                                $status_columns = [
                                    'adviser_status' => 'Adviser',
                                    'dean_status' => 'Dean',
                                    'admin_services_status' => 'Admin',
                                    'osafa_status' => 'OSAFA',
                                    'cfdo_status' => 'CFDO',
                                    'afo_status' => 'AFO',
                                    'vp_acad_status' => 'VP Acad',
                                    'vp_admin_status' => 'VP Admin'
                                ];
                                foreach ($status_columns as $column => $label):
                                    if (!isset($request[$column]) || $request[$column] === null) continue;
                                    $value = $request[$column];
                                    $pill_class = 'flow-pill';
                                    if ($value === 'Approved') {
                                        $pill_class .= ' approved';
                                    } elseif ($value === 'Rejected') {
                                        $pill_class .= ' rejected';
                                    }
                                ?>
                                    <span class="<?php echo $pill_class; ?>">
                                        <span class="font-semibold tracking-[0.2em] text-[10px]"><?php echo $label; ?></span>
                                        <?php echo htmlspecialchars($value); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="text-right">
                            <a href="<?php echo $review_page; ?>?id=<?php echo $request['review_link_id']; ?>" class="detail-link">
                                Review
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php end_page(); ?><?php end_page(); ?>
