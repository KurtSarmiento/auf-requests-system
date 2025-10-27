<?php
// admin_venue_review.php
session_start();

// Ensure only admin roles can access
$admin_roles = ['Adviser', 'Dean', 'Admin Services', 'OSAFA', 'CFDO', 'AFO', 'VP for Academic Affairs', 'VP for Administration'];
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], $admin_roles)) {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

// Helper to normalize role strings
if (!function_exists('normalize_role')) {
    function normalize_role($r) {
        if (!is_string($r)) return '';
        $s = trim(strtolower($r));
        return preg_replace('/\s+/', ' ', $s);
    }
}

$current_role = $_SESSION["role"];
$is_officer = (stripos($current_role, 'officer') !== false);
$user_id = $_SESSION["user_id"];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = $success_message = "";
$remark = "";
$request = null;

// --- Map approval chain (PHP 7 version of match) ---
switch ($current_role) {
    case 'Dean':
        $role_data = ['dean_status', 'admin_services_status', 'Awaiting Admin Services Approval'];
        break;
    case 'Admin Services':
        $role_data = ['admin_services_status', 'osafa_status', 'Awaiting OSAFA Approval'];
        break;
    case 'OSAFA':
        $role_data = ['osafa_status', 'cfdo_status', 'Awaiting CFDO Approval'];
        break;
    case 'CFDO':
        $role_data = ['cfdo_status', 'afo_status', 'Awaiting AFO Approval'];
        break;
    case 'AFO':
        $role_data = ['afo_status', 'vp_acad_status', 'Awaiting VP for Academic Affairs Approval'];
        break;
    case 'VP for Academic Affairs':
        $role_data = ['vp_acad_status', 'vp_admin_status', 'Awaiting VP for Administration Approval'];
        break;
    case 'VP for Administration':
        $role_data = ['vp_admin_status', null, 'Venue Approved'];
        break;
    default:
        $role_data = [null, null, null];
        break;
}

$current_status_column = $role_data[0];
$next_status_column = $role_data[1];
$next_notification_status = $role_data[2];
$current_remark_column = strtolower(str_replace(' ', '_', $current_role)) . '_remark';
if ($current_role === 'Admin Services') $current_remark_column = 'admin_services_remark';
if ($current_role === 'VP for Academic Affairs') $current_remark_column = 'vp_acad_remark';
if ($current_role === 'VP for Administration') $current_remark_column = 'vp_admin_remark';

// Function for CSS class
if (!function_exists('get_status_class')) {
    function get_status_class($status) {
        switch ($status) {
            case 'Approved':
            case 'Venue Approved':
                return 'bg-green-100 text-green-800 border-green-500';
            case 'Rejected':
                return 'bg-red-100 text-red-800 border-red-500';
            case 'Pending':
                return 'bg-yellow-100 text-yellow-800 border-yellow-500';
            default:
                return 'bg-gray-100 text-gray-800 border-gray-500';
        }
    }
}

// --- Handle POST submission ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && $request_id > 0) {
    $decision = mysqli_real_escape_string($link, trim($_POST["decision"]));
    $remark = mysqli_real_escape_string($link, trim($_POST["remark"]));

    if (empty($decision)) {
        $error_message = "Please select a decision (Approve or Reject).";
    } elseif ($decision === 'Rejected' && empty($remark)) {
        $error_message = "Remarks are required for rejection.";
    }

    if (empty($error_message)) {
        mysqli_begin_transaction($link);
        $updates = [];

        // Always update current status + remark
        $updates[] = "{$current_status_column} = '{$decision}'";
        $updates[] = "{$current_remark_column} = '{$remark}'";

        if ($decision === 'Approved') {
            if ($next_status_column !== null) {
                $updates[] = "{$next_status_column} = 'Pending'";
                $updates[] = "notification_status = '{$next_notification_status}'";
            } else {
                $updates[] = "final_status = 'Approved'";
                $updates[] = "notification_status = 'Venue Approved'";
            }
        } elseif ($decision === 'Rejected') {
            $updates[] = "final_status = 'Rejected'";
            $updates[] = "notification_status = 'Rejected by {$current_role}'";

            $sub_cols = [];
            switch ($current_role) {
                case 'Dean': $sub_cols = ['admin_services_status','osafa_status','cfdo_status','afo_status','vp_acad_status','vp_admin_status']; break;
                case 'Admin Services': $sub_cols = ['osafa_status','cfdo_status','afo_status','vp_acad_status','vp_admin_status']; break;
                case 'OSAFA': $sub_cols = ['cfdo_status','afo_status','vp_acad_status','vp_admin_status']; break;
                case 'CFDO': $sub_cols = ['afo_status','vp_acad_status','vp_admin_status']; break;
                case 'AFO': $sub_cols = ['vp_acad_status','vp_admin_status']; break;
                case 'VP for Academic Affairs': $sub_cols = ['vp_admin_status']; break;
            }
            foreach ($sub_cols as $col) {
                $updates[] = "{$col} = 'Rejected'";
            }
        }

        $sql = "UPDATE venue_requests SET " . implode(', ', $updates) . " WHERE venue_request_id = {$request_id}";

        if (mysqli_query($link, $sql)) {
            mysqli_commit($link);
            header("location: admin_request_list.php?success=1");
            exit;
        } else {
            $error_message = "Database Error: " . mysqli_error($link);
            mysqli_rollback($link);
        }
    }
}

// --- Fetch request details ---
if ($request_id > 0) {
    $sql = "SELECT r.*, u.full_name AS officer_name, o.org_name
            FROM venue_requests r
            INNER JOIN users u ON r.user_id = u.user_id
            INNER JOIN organizations o ON r.org_id = o.org_id
            WHERE r.venue_request_id = {$request_id}";
    $result = mysqli_query($link, $sql);
    if ($result) {
        $request = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        if ($request && ($_SERVER["REQUEST_METHOD"] !== "POST" || !empty($error_message))) {
            if (in_array($request[$current_status_column], ['Approved', 'Rejected'])) {
                $remark = $request[$current_remark_column] ?? '';
            }
        }
    }
}

if (!$request) {
    header("location: admin_request_list.php");
    exit;
}

require_once "layout_template.php";
start_page("Review Venue Request", $current_role, $_SESSION['full_name']);
?>

<div class="max-w-4xl mx-auto bg-white p-8 rounded-xl shadow-2xl">
    <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">
        Review Venue Request #<?php echo $request['venue_request_id']; ?>
    </h2>
    <p class="text-gray-600 mb-8">
        Role: <span class="font-bold text-indigo-600"><?php echo htmlspecialchars($current_role); ?></span>
    </p>

    <?php if (!empty($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
            <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="space-y-4 mb-8 p-6 bg-gray-50 rounded-xl border">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Request Information</h3>
        <p><strong>Organization:</strong> <?php echo htmlspecialchars($request['org_name']); ?></p>
        <p><strong>Activity Title:</strong> <?php echo htmlspecialchars($request['title']); ?></p>
        <p><strong>Venue Requested:</strong> <span class="font-bold text-blue-700"><?php echo htmlspecialchars($request['venue_name']); ?></span></p>
        <p><strong>Date & Time:</strong> 
            <?php echo date('F j, Y', strtotime($request['activity_date'])) . 
                 ' (' . date('h:i A', strtotime($request['start_time'])) . 
                 ' - ' . date('h:i A', strtotime($request['end_time'])) . ')'; ?>
        </p>
        <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($request['description'])); ?></p>
        <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($request['officer_name']); ?></p>
    </div>

    <div class="space-y-4 mb-8 p-6 bg-indigo-50/50 rounded-xl border border-indigo-200">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Approval Chain Status</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <?php
            $cols = [
                'dean_status' => 'Dean',
                'admin_services_status' => 'Admin Services',
                'osafa_status' => 'OSAFA',
                'cfdo_status' => 'CFDO',
                'afo_status' => 'AFO',
                'vp_acad_status' => 'VP Acad',
                'vp_admin_status' => 'VP Admin (Final)',
            ];
            foreach ($cols as $col => $label) {
                echo "<p><strong>{$label}:</strong> <span class='status-pill " .
                     get_status_class($request[$col]) . "'>" .
                     htmlspecialchars($request[$col]) . "</span></p>";
            }
            ?>
            <p><strong>OVERALL:</strong> 
                <span class="status-pill <?php echo get_status_class($request['final_status']); ?> font-bold">
                    <?php echo htmlspecialchars($request['final_status']); ?>
                </span>
            </p>
        </div>
    </div>

    <?php
    $is_already_decided = in_array($request[$current_status_column], ['Approved', 'Rejected']);
    $is_closed = in_array($request['final_status'], ['Approved', 'Rejected']);

    if ($is_already_decided): ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 rounded-lg mb-6">
            <p class="font-bold">Decision Already Made</p>
            <p>Your decision for this request has already been recorded.</p>
        </div>
    <?php elseif ($is_closed): ?>
        <div class="bg-gray-100 border-l-4 border-gray-500 text-gray-800 p-4 rounded-lg mb-6">
            <p class="font-bold">Request Closed</p>
            <p>This request has reached its final status (<?php echo htmlspecialchars($request['final_status']); ?>).</p>
        </div>
    <?php else: ?>
        <h3 class="text-2xl font-semibold text-gray-900 mb-4 border-t pt-4">Your Decision</h3>
        <form action="admin_venue_review.php?id=<?php echo $request_id; ?>" method="post">
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Action:</label>
                <div class="flex space-x-4">
                    <label class="flex items-center cursor-pointer bg-green-50 hover:bg-green-100 p-3 rounded-lg border-2 border-green-300">
                        <input type="radio" name="decision" value="Approved" class="h-5 w-5 text-green-600" required>
                        <span class="ml-2 font-semibold text-green-700">Approve / Forward</span>
                    </label>
                    <label class="flex items-center cursor-pointer bg-red-50 hover:bg-red-100 p-3 rounded-lg border-2 border-red-300">
                        <input type="radio" name="decision" value="Rejected" class="h-5 w-5 text-red-600" required>
                        <span class="ml-2 font-semibold text-red-700">Reject</span>
                    </label>
                </div>
            </div>
            <div class="mb-4">
                <label for="remark" class="block text-sm font-medium text-gray-700 mb-2">Remarks / Reason</label>
                <textarea id="remark" name="remark" rows="4"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="Enter your justification..."><?php echo htmlspecialchars($remark); ?></textarea>
            </div>
            <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700">
                Submit Decision
            </button>
        </form>
    <?php endif; ?>
</div>
<?php end_page(); ?>
