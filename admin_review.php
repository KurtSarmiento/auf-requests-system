<?php
// ================================
// admin_review.php (Fixed for current DB schema)
// ================================

session_start();
require_once "db_config.php";

// ✅ Allowed roles based on your table columns
$admin_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];

// ✅ Redirect if not logged in or not a valid officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], $admin_roles)) {
    header("location: login.php");
    exit;
}

// --- Helper for CSS colors
function get_status_class($status) {
    switch ($status) {
        case 'Approved': return 'bg-green-100 text-green-800 border-green-500';
        case 'Rejected': return 'bg-red-100 text-red-800 border-red-500';
        case 'Pending':  return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        default:         return 'bg-gray-100 text-gray-800 border-gray-500';
    }
}

// --- Variables
$current_role = $_SESSION["role"];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = $success_message = $remark = "";
$request = null;

// ========================================
// 1️⃣ DEFINE STATUS CHAIN BASED ON ROLE
// ========================================
$current_status_column = "";
$next_status_column = "";
$next_notification_status = "";

if ($current_role === 'Adviser') {
    $current_status_column = 'adviser_status';
    $next_status_column = 'dean_status';
    $next_notification_status = 'Awaiting Dean Approval';
} elseif ($current_role === 'Dean') {
    $current_status_column = 'dean_status';
    $next_status_column = 'osafa_status';
    $next_notification_status = 'Awaiting OSAFA Approval';
} elseif ($current_role === 'OSAFA') {
    $current_status_column = 'osafa_status';
    $next_status_column = 'afo_status';
    $next_notification_status = 'Awaiting AFO Approval';
} elseif ($current_role === 'AFO') {
    $current_status_column = 'afo_status';
    $next_status_column = 'final_status';
    $next_notification_status = 'Finalized';
}

$current_remark_column = strtolower(str_replace(' ', '_', $current_role)) . '_remark';

// ========================================
// 2️⃣ HANDLE FORM SUBMISSION
// ========================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && $request_id > 0) {
    $decision = mysqli_real_escape_string($link, $_POST["decision"]);
    $remark = mysqli_real_escape_string($link, $_POST["remark"]);

    if (empty($decision)) {
        $error_message = "Please select a decision (Approve or Reject).";
    } elseif ($decision === 'Rejected' && empty($remark)) {
        $error_message = "Remarks are required for rejection.";
    }

    if (empty($error_message)) {
        mysqli_begin_transaction($link);
        $updates = [];

        // Update current role decision
        $updates[] = "{$current_status_column} = '{$decision}'";
        $updates[] = "{$current_remark_column} = '{$remark}'";

        // Handle flow based on decision
        if ($decision === 'Approved') {
            if ($next_status_column !== 'final_status') {
                $updates[] = "{$next_status_column} = 'Pending'";
                $updates[] = "notification_status = '{$next_notification_status}'";
            } else {
                $updates[] = "final_status = 'Approved'";
                $updates[] = "notification_status = 'Approved by {$current_role}'";
            }
        } elseif ($decision === 'Rejected') {
            $updates[] = "final_status = 'Rejected'";
            $updates[] = "notification_status = 'Rejected by {$current_role}'";

            // Reject all later stages automatically
            $later_columns = [];
            if ($current_role === 'Adviser') {
                $later_columns = ['dean_status','osafa_status','afo_status'];
            } elseif ($current_role === 'Dean') {
                $later_columns = ['osafa_status','afo_status'];
            } elseif ($current_role === 'OSAFA') {
                $later_columns = ['afo_status'];
            }

            foreach ($later_columns as $col) {
                $updates[] = "{$col} = 'Rejected'";
            }
        }

        $sql = "UPDATE requests SET " . implode(', ', $updates) . " WHERE request_id = {$request_id}";

        if (mysqli_query($link, $sql)) {
            mysqli_commit($link);
            header("location: admin_request_list.php?success=1");
            exit;
        } else {
            mysqli_rollback($link);
            $error_message = "ERROR: Could not update record. " . mysqli_error($link);
        }
    }
}

// ========================================
// 3️⃣ FETCH REQUEST DETAILS
// ========================================
if ($request_id > 0) {
    $sql = "
        SELECT 
            r.*, 
            u.full_name AS officer_name, 
            o.org_name 
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON u.org_id = o.org_id
        WHERE r.request_id = {$request_id}
    ";

    $result = mysqli_query($link, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $request = mysqli_fetch_assoc($result);
        if (in_array($request[$current_status_column], ['Approved', 'Rejected'])) {
            $remark = $request[$current_remark_column];
        }
    } else {
        header("location: admin_request_list.php");
        exit;
    }
}

require_once "layout_template.php";
start_page("Review Request", $current_role, $_SESSION['full_name']);
?>

<!-- ======================== HTML VIEW ======================== -->
<div class="max-w-4xl mx-auto bg-white p-8 rounded-xl shadow-2xl">
    <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">
        Review Request #<?= htmlspecialchars($request['request_id']) ?>
    </h2>
    <p class="text-gray-600 mb-8">
        Role: <span class="font-bold text-indigo-600"><?= htmlspecialchars($current_role) ?></span>
    </p>

    <?php if (!empty($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= $error_message ?>
        </div>
    <?php endif; ?>

    <div class="space-y-4 mb-8 p-6 bg-gray-50 rounded-xl border">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Request Information</h3>
        <p><strong>Organization:</strong> <?= htmlspecialchars($request['org_name']) ?></p>
        <p><strong>Title:</strong> <?= htmlspecialchars($request['title']) ?></p>
        <p><strong>Type:</strong> <?= htmlspecialchars($request['type']) ?></p>
        <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($request['description'])) ?></p>
        <p><strong>Submitted By:</strong> <?= htmlspecialchars($request['officer_name']) ?></p>
    </div>

    <div class="space-y-4 mb-8 p-6 bg-indigo-50 rounded-xl border border-indigo-200">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Approval Status</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <p><strong>Adviser:</strong> <span class="<?= get_status_class($request['adviser_status']) ?>"><?= htmlspecialchars($request['adviser_status']) ?></span></p>
            <p><strong>Dean:</strong> <span class="<?= get_status_class($request['dean_status']) ?>"><?= htmlspecialchars($request['dean_status']) ?></span></p>
            <p><strong>OSAFA:</strong> <span class="<?= get_status_class($request['osafa_status']) ?>"><?= htmlspecialchars($request['osafa_status']) ?></span></p>
            <p><strong>AFO:</strong> <span class="<?= get_status_class($request['afo_status']) ?>"><?= htmlspecialchars($request['afo_status']) ?></span></p>
            <p><strong>Overall:</strong> <span class="<?= get_status_class($request['final_status']) ?>"><?= htmlspecialchars($request['final_status']) ?></span></p>
        </div>
    </div>

    <?php 
    $is_already_decided = in_array($request[$current_status_column], ['Approved', 'Rejected']);
    $is_closed = in_array($request['final_status'], ['Approved', 'Rejected']);
    ?>

    <?php if ($is_already_decided): ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 rounded-lg text-sm mb-6">
            <p class="font-bold">Decision Already Made</p>
            <p>Your decision (<?= htmlspecialchars($request[$current_status_column]) ?>) has already been recorded.</p>
        </div>

    <?php elseif ($is_closed): ?>
        <div class="bg-gray-100 border-l-4 border-gray-500 text-gray-800 p-4 rounded-lg text-sm mb-6">
            <p class="font-bold">Request Closed</p>
            <p>This request has reached its final status (<?= htmlspecialchars($request['final_status']) ?>).</p>
        </div>

    <?php else: ?>
        <h3 class="text-2xl font-semibold text-gray-900 mb-4 border-t pt-4">Your Decision</h3>
        <form action="admin_review.php?id=<?= $request_id ?>" method="post">
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Action:</label>
                <div class="flex space-x-4">
                    <label class="flex items-center cursor-pointer bg-green-50 hover:bg-green-100 p-3 rounded-lg border-2 border-green-300">
                        <input type="radio" name="decision" value="Approved" class="form-radio h-5 w-5 text-green-600" required>
                        <span class="ml-2 font-semibold text-green-700">Approve / Forward</span>
                    </label>
                    <label class="flex items-center cursor-pointer bg-red-50 hover:bg-red-100 p-3 rounded-lg border-2 border-red-300">
                        <input type="radio" name="decision" value="Rejected" class="form-radio h-5 w-5 text-red-600" required>
                        <span class="ml-2 font-semibold text-red-700">Reject</span>
                    </label>
                </div>
            </div>

            <div class="mb-4">
                <label for="remark" class="block text-sm font-medium text-gray-700 mb-2">
                    Remarks / Reason for Decision
                </label>
                <textarea id="remark" name="remark" rows="4" 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150"
                    placeholder="Enter justification..."><?= htmlspecialchars($remark) ?></textarea>
            </div>

            <button type="submit"
                class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700 transition duration-150">
                Submit Decision
            </button>
        </form>
    <?php endif; ?>
</div>

<?php end_page(); ?>