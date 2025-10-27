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
    header("location: dashboard.php");
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
        'funding' AS type
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
    // ✅ Trust DB's final_status first
    if (!empty($request['final_status']) && $request['final_status'] !== 'Pending') {
        return $request['final_status'];
    }

    // 🟣 Handle Venue Requests
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

    // 🔵 Handle Funding Requests
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
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">My Submissions</h1>
    
    <?php if (empty($requests)): ?>
        <div class="bg-indigo-50 border-l-4 border-indigo-500 text-indigo-700 p-6 rounded-lg shadow-lg" role="alert">
            <p class="font-bold">No Requests Submitted Yet!</p>
            <p>You haven't submitted any Funding or Venue requests. Click the <span class="font-semibold">Create New Request</span> button to start.</p>
        </div>
    <?php else: ?>

        <div class="bg-white shadow-xl rounded-xl overflow-hidden mt-8">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID / Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title / Organization</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount / Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Final Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($requests as $request): 
                        $final_status = get_final_status($request);
                        $status_class = match ($final_status) {
                            'Approved' => 'bg-green-100 text-green-800 font-semibold',
                            'Rejected' => 'bg-red-100 text-red-800 font-semibold',
                            default => 'bg-yellow-100 text-yellow-800'
                        };

                        $is_venue = ($request['type'] ?? 'funding') === 'venue';
                        $detail_page = $is_venue ? 'officer_request_status.php' : 'request_details.php'; 
                        $request_type_label = $is_venue 
                            ? '<span class="text-purple-600 font-bold">VENUE</span>' 
                            : '<span class="text-blue-600 font-bold">FUNDING</span>';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">
                            #<?php echo htmlspecialchars($request['request_id']); ?><br>
                            <?php echo $request_type_label; ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <div class="text-base font-semibold text-gray-900 truncate max-w-xs"><?php echo htmlspecialchars($request['title']); ?></div>
                            <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($request['org_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?php if (!$is_venue): ?>
                                <span class="font-semibold text-green-700">₱<?php echo number_format($request['amount'], 2); ?></span>
                            <?php else: ?>
                                <span class="text-sm text-gray-500 italic">N/A</span>
                            <?php endif; ?>
                            <br>
                            <span class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($request['date_submitted'])); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                <?php echo $final_status; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <?php echo htmlspecialchars($request['notification_status']); ?>
                        </td>
                        <td class="px-6 py-4 text-center text-sm font-medium">
                            <a href="<?php echo $detail_page; ?>?id=<?php echo $request['request_id']; ?>" 
                               class="text-blue-600 hover:text-blue-900 transition duration-150 font-semibold">
                                View Details
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php end_page(); ?>
