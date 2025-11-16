<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// --- DEFINE SESSION VARIABLES FOR TEMPLATE ---
$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];

// Check if the user is an Officer (this list is specifically for Officers)
if ($role !== 'Officer') {
    // If not an officer, redirect them to their appropriate dashboard
    header("location: admin_dashboard.php");
    exit;
}

// Start the page using the template function
start_page("My Submitted Requests", $role, $full_name);

// Initialize array to hold requests
$requests = [];

// --- 1. Fetch FUNDING Requests ---
$sql_funding = "
    SELECT 
        r.request_id, 
        r.title, 
        r.amount, 
        r.date_submitted,
        r.adviser_status, 
        r.dean_status, 
        r.osafa_status, 
        r.afo_status, 
        r.final_status, 
        r.notification_status,
        o.org_name,
        r.type -- âœ… *** FIX 1: Changed 'funding' AS type to r.type ***
    FROM requests r
    INNER JOIN users u ON r.user_id = u.user_id
    INNER JOIN organizations o ON u.org_id = o.org_id
    WHERE r.user_id = ?
    GROUP BY r.request_id
    ORDER BY r.date_submitted DESC
";

if ($stmt = mysqli_prepare($link, $sql_funding)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $requests['funding_' . $row['request_id']] = $row;
        }
    } else {
        echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-md'>
                    ERROR: Could not execute Funding query. " . mysqli_error($link) . "
                  </div>";
    }
    mysqli_stmt_close($stmt);
}

// --- 2. Fetch VENUE Requests ---
$sql_venue = "
    SELECT 
        vr.venue_request_id AS request_id, 
        vr.title, 
        vr.date_submitted, 
        vr.notification_status,
        NULL AS amount, 
        'N/A' AS adviser_status, 
        'N/A' AS dean_status, 
        'N/A' AS osafa_status, 
        'N/A' AS afo_status, 
        vr.final_status,
        vr.admin_services_status, 
        vr.cfdo_status, 
        vr.vp_acad_status, 
        vr.vp_admin_status,
        o.org_name,
        'venue' AS type
    FROM venue_requests vr
    INNER JOIN users u ON vr.user_id = u.user_id
    INNER JOIN organizations o ON u.org_id = o.org_id
    WHERE vr.user_id = ?    
    GROUP BY vr.venue_request_id
    ORDER BY vr.date_submitted DESC
";

if ($stmt = mysqli_prepare($link, $sql_venue)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $requests['venue_' . $row['request_id']] = $row;
        }
    } else {
        echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-md'>
                    ERROR: Could not execute Venue query. " . mysqli_error($link) . "
                  </div>";
    }
    mysqli_stmt_close($stmt);
}

// --- 3. Convert associative array to regular array and sort by date ---
$requests = array_values($requests);
usort($requests, fn($a, $b) => strtotime($b['date_submitted']) - strtotime($a['date_submitted']));

// --- 4. Function to determine final status ---
function get_final_status($request) {
    // âœ… Trust DB's final_status first
    if (!empty($request['final_status']) && $request['final_status'] !== 'Pending') {

        // âœ… *** FIX 2: LIQUIDATION STATUS FIX ***
        // If the type is 'Liquidation Report' and status is 'Budget Available',
        // just show 'Approved'.
        if (isset($request['type']) && $request['type'] === 'Liquidation Report' && $request['final_status'] === 'Budget Available') {
            return 'Approved';
        }
        
        return $request['final_status'];
    }

    // ðŸŸ£ Handle Venue Requests
    if (isset($request['type']) && $request['type'] === 'venue') {
        $statuses = [
            $request['vp_admin_status'] ?? 'N/A',
            $request['vp_acad_status'] ?? 'N/A',
            $request['cfdo_status'] ?? 'N/A',
            $request['admin_services_status'] ?? 'N/A'
        ];

        // Any rejection = Rejected
        if (in_array('Rejected', $statuses)) {
            return 'Rejected';
        }

        // Remove N/A and check all Approved
        $relevant = array_filter($statuses, fn($s) => $s !== 'N/A');
        if (!empty($relevant) && count(array_filter($relevant, fn($s) => $s === 'Approved')) === count($relevant)) {
            return 'Approved';
        }

        return 'Pending';
    }

    // ðŸ”µ Handle Funding Requests
    else {
        $statuses = [
            $request['adviser_status'] ?? 'Pending',
            $request['dean_status'] ?? 'Pending',
            $request['osafa_status'] ?? 'Pending',
            $request['afo_status'] ?? 'Pending'
        ];

        // Any rejection = Rejected
        if (in_array('Rejected', $statuses)) {
            return 'Rejected';
        }

        // All approved = Approved
        if (count(array_filter($statuses, fn($s) => $s === 'Approved')) === count($statuses)) {
            return 'Approved';
        }

        return 'Pending';
    }
}

$summary = [
    'total' => count($requests),
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'venue' => 0,
];

foreach ($requests as $req) {
    if (($req['type'] ?? 'funding') === 'venue') {
        $summary['venue']++;
    }

    $final = get_final_status($req);
    if ($final === 'Rejected') {
        $summary['rejected']++;
    } elseif ($final === 'Approved' || $final === 'Budget Available') {
        $summary['approved']++;
    } else {
        $summary['pending']++;
    }
}
?>

<div class="space-y-8 py-4">
    <section class="page-hero">
        <span class="hero-pill">Submission Tracker</span>
        <h2 class="hero-title">Glass-clear history of your AUFthorize filings.</h2>
        <p class="hero-subtext">
            Every funding, liquidation, reimbursement, and venue request stays in sync with routing updates
            so you can focus on execution.
        </p>
        <div class="hero-actions">
            <a href="request_select.php" class="btn-primary">Create request</a>
            <a href="request_select.php#templates" class="detail-link">View available forms</a>
        </div>
    </section>

    <?php if ($summary['total'] > 0): ?>
    <div class="stat-grid">
        <div class="stat-card stat-card--accent">
            <p class="stat-card__label">Total</p>
            <p class="stat-card__value"><?php echo $summary['total']; ?></p>
            <p class="stat-card__meta"><?php echo $summary['venue']; ?> venue booking(s) included</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">In review</p>
            <p class="stat-card__value"><?php echo $summary['pending']; ?></p>
            <p class="stat-card__meta">With advisers and offices</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Approved</p>
            <p class="stat-card__value"><?php echo $summary['approved']; ?></p>
            <p class="stat-card__meta">Ready for execution</p>
        </div>
        <div class="stat-card">
            <p class="stat-card__label">Returned</p>
            <p class="stat-card__value"><?php echo $summary['rejected']; ?></p>
            <p class="stat-card__meta">Requires revisions</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($requests)): ?>
        <div class="subtle-card">
            <p class="hero-subtext">No requests submitted yet.</p>
            <p class="text-sm text-slate-500 mt-1">Once you send a budget, liquidation, reimbursement, or venue request it will appear here for easy tracking.</p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($requests as $request): 
                $final_status = get_final_status($request);
                $is_venue = ($request['type'] ?? 'funding') === 'venue';
                $detail_page = $is_venue ? 'venue_request_details.php' : 'request_details.php';
                $submitted_date = date('M d, Y', strtotime($request['date_submitted']));

                $type_class = 'type-chip';
                if ($is_venue) {
                    $type_class .= ' type-chip--venue';
                } elseif (($request['type'] ?? '') === 'Liquidation Report') {
                    $type_class .= ' type-chip--liquidation';
                } elseif (($request['type'] ?? '') === 'Reimbursement') {
                    $type_class .= ' type-chip--reimbursement';
                }

                $status_class = 'status-chip pending';
                if ($final_status === 'Rejected') {
                    $status_class = 'status-chip rejected';
                } elseif ($final_status === 'Approved' || $final_status === 'Budget Available') {
                    $status_class = 'status-chip approved';
                }

                $pipeline_columns = $is_venue ? [
                    'admin_services_status' => 'Admin Services',
                    'cfdo_status' => 'CFDO',
                    'afo_status' => 'AFO',
                    'vp_acad_status' => 'VP Acad',
                    'vp_admin_status' => 'VP Admin'
                ] : [
                    'adviser_status' => 'Adviser',
                    'dean_status' => 'Dean',
                    'osafa_status' => 'OSAFA',
                    'afo_status' => 'AFO'
                ];
            ?>
            <article class="list-card space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-3 flex-wrap text-xs text-slate-500">
                            <span class="<?php echo $type_class; ?>">
                                <?php echo $is_venue ? 'Venue' : htmlspecialchars($request['type'] ?? 'Funding'); ?>
                            </span>
                            <span class="stage-chip">#<?php echo htmlspecialchars($request['request_id']); ?></span>
                        </div>
                        <h3 class="text-xl font-semibold text-slate-900 mt-2"><?php echo htmlspecialchars($request['title']); ?></h3>
                        <p class="text-sm text-slate-500 mt-1"><?php echo htmlspecialchars($request['org_name']); ?></p>
                    </div>
                    <div class="text-sm text-slate-600 text-left lg:text-right">
                        <p class="font-semibold text-slate-900">Submitted <?php echo $submitted_date; ?></p>
                        <?php if (!$is_venue): ?>
                            <p class="text-emerald-700 font-semibold mt-1">&#8369;<?php echo number_format($request['amount'], 2); ?></p>
                        <?php else: ?>
                            <p class="text-slate-500 italic mt-1">No budget requested</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="<?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($final_status); ?>
                        </span>
                        <span class="flow-pill">
                            Stage: <?php echo htmlspecialchars($request['notification_status']); ?>
                        </span>
                    </div>
                    <a href="<?php echo $detail_page; ?>?id=<?php echo $request['request_id']; ?>" class="detail-link">
                        View details
                    </a>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($pipeline_columns as $column => $label):
                        $value = $request[$column] ?? 'Pending';
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
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php end_page(); ?>
