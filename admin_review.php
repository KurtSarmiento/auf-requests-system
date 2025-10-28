<?php
// ================================
// admin_review.php
// ================================

session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// ✅ Allowed roles based on your table columns
$admin_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
$current_role = isset($_SESSION["role"]) ? $_SESSION["role"] : '';

// ✅ Redirect if not logged in or not a valid admin for this page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($current_role, $admin_roles)) {
    header("location: login.php");
    exit;
}

// --- Helper for CSS colors (moved to layout_template.php, but good to have a fallback)
if (!function_exists('get_status_class')) {
    function get_status_class($status) {
        switch ($status) {
            case 'Approved': return 'bg-green-100 text-green-800 border-green-500';
            case 'Rejected': return 'bg-red-100 text-red-800 border-red-500';
            case 'Pending':  return 'bg-yellow-100 text-yellow-800 border-yellow-500';
            default:         return 'bg-gray-100 text-gray-800 border-gray-500';
        }
    }
}

// --- Variables
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = $success_message = $remark = "";
$request = null;
$files = [];

// ===================================
// --- 1. Define Role Logic (FIXED) ---
// ===================================
$role_column = '';
$remark_column = '';
$date_column = ''; // <-- The fixed variable
$previous_role_column = '';

switch ($current_role) {
    case 'Adviser':
        $role_column = 'adviser_status';
        $remark_column = 'adviser_remark';
        $date_column = 'adviser_decision_date'; 
        break;
    case 'Dean':
        $role_column = 'dean_status';
        $remark_column = 'dean_remark';
        $date_column = 'dean_decision_date'; 
        $previous_role_column = 'adviser_status';
        break;
    case 'OSAFA':
        $role_column = 'osafa_status';
        $remark_column = 'osafa_remark';
        $date_column = 'osafa_decision_date'; 
        $previous_role_column = 'dean_status';
        break;
    case 'AFO':
        $role_column = 'afo_status';
        $remark_column = 'afo_remark';
        $date_column = 'afo_decision_date'; 
        $previous_role_column = 'osafa_status';
        break;
}

// ===================================
// --- 2. HANDLE FORM SUBMISSION (POST) ---
// ===================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && $request_id > 0 && !empty($date_column)) {
    $decision = $_POST['decision'];
    $remark_text = trim($_POST['remark']);

    // Determine the next status for the user
    $next_notification_status = "";
    if ($decision === 'Approved') {
        switch ($current_role) {
            case 'Adviser': $next_notification_status = 'Awaiting Dean Review'; break;
            case 'Dean':    $next_notification_status = 'Awaiting OSAFA Review'; break;
            case 'OSAFA': $next_notification_status = 'Awaiting AFO Review'; break;
            case 'AFO':   $next_notification_status = 'Budget Available'; break;
        }
    } else {
        // 'Rejected'
        $next_notification_status = "Rejected by " . $current_role;
    }
    
    // Set final_status if this is the end of the line (AFO) or a rejection
    $final_status_sql = "";
    if (($current_role === 'AFO' && $decision === 'Approved') || $decision === 'Rejected') {
        $final_status_sql = ", final_status = '{$decision}'";
    }

    // --- UPDATED SQL: Using {$date_column} (e.g., adviser_decision_date) ---
    $sql = "UPDATE requests 
            SET 
                {$role_column} = ?,
                {$date_column} = NOW(),
                {$remark_column} = ?,
                notification_status = ?
                {$final_status_sql}
            WHERE 
                request_id = ?";
                
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssi", $decision, $remark_text, $next_notification_status, $request_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Your decision ($decision) has been recorded successfully.";
        } else {
            $error_message = "Error: Could not execute the update. " . mysqli_error($link);
        }
        mysqli_stmt_close($stmt);
    } else {
        $error_message = "Error: Could not prepare the update statement. " . mysqli_error($link);
    }
}

// ===================================
// --- 3. FETCH REQUEST DATA (GET) ---
// ===================================
if ($request_id > 0) {
    // --- UPDATED SQL: Select all _decision_date columns ---
    $sql = "SELECT 
                r.*, 
                u.full_name, 
                o.org_name,
                r.adviser_decision_date,
                r.dean_decision_date,
                r.osafa_decision_date,
                r.afo_decision_date
            FROM requests r
            JOIN users u ON r.user_id = u.user_id
            JOIN organizations o ON u.org_id = o.org_id
            WHERE r.request_id = ?";
            
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 1) {
            $request = mysqli_fetch_assoc($result);
            if (!empty($remark_column)) {
                $remark = $request[$remark_column]; // Load existing remark
            }
            
            // Fetch associated files
            $file_sql = "SELECT * FROM files WHERE request_id = ?";
            if ($stmt_files = mysqli_prepare($link, $file_sql)) {
                mysqli_stmt_bind_param($stmt_files, "i", $request_id);
                mysqli_stmt_execute($stmt_files);
                $result_files = mysqli_stmt_get_result($stmt_files);
                while ($row = mysqli_fetch_assoc($result_files)) {
                    $files[] = $row;
                }
                mysqli_stmt_close($stmt_files);
            }
        } else {
            $error_message = "Request not found.";
        }
        mysqli_stmt_close($stmt);
    }
    
    // Check if the request is actually ready for review by this role
    if ($request && $previous_role_column && $request[$previous_role_column] !== 'Approved') {
        $error_message = "This request is not yet ready for your review. It is awaiting approval from a previous signatory.";
        // Don't nullify the request, just show the error.
    }
    
} else {
    $error_message = "Invalid request ID.";
}

// Define the approval chain for display
$approval_chain = [
    'Adviser' => 'adviser_status',
    'Dean' => 'dean_status',
    'OSAFA' => 'osafa_status',
    'AFO' => 'afo_status'
];

// Start the page
start_page("Review Funding Request", $current_role, $_SESSION["full_name"]);

?>
<div class="container mx-auto px-4 py-8">

    <?php if ($error_message): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
        <p class="font-bold">Error</p>
        <p><?php echo htmlspecialchars($error_message); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
        <p class="font-bold">Success</p>
        <p><?php echo htmlspecialchars($success_message); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($request): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-2 space-y-6">
            
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <span class="text-sm font-semibold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full"><?php echo htmlspecialchars($request['type']); ?> Request</span>
                <h1 class="text-4xl font-extrabold text-gray-900 mt-2 mb-1"><?php echo htmlspecialchars($request['title']); ?></h1>
                <p class="text-lg text-gray-600">
                    Submitted by <span class="font-semibold"><?php echo htmlspecialchars($request['full_name']); ?></span> 
                    (<?php echo htmlspecialchars($request['org_name']); ?>)
                </p>
                <p class="text-sm text-gray-500">
                    On: <?php echo date('M d, Y h:i A', strtotime($request['date_submitted'])); ?>
                </p>
                <div class="text-4xl font-bold text-gray-800 mt-4">
                    ₱<?php echo number_format($request['amount'], 2); ?>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Request Progress</h3>
                <div class="flex items-start space-x-4 overflow-x-auto py-2">
                    <?php 
                    // ===================================
                    // --- NEW REJECTION LOGIC (START) ---
                    // ===================================
                    $rejection_has_occurred = false; 
                    foreach ($approval_chain as $role_name => $status_col):
                        
                        $status_value = 'Pending'; // Default
                        $status_date = null;
                        $status_class = get_status_class('Pending');

                        if ($rejection_has_occurred) { 
                            $status_value = '-';
                            $status_class = 'bg-gray-100 text-gray-800 border-gray-500'; // Neutral
                        } else { 
                            $status_value = $request[$status_col];
                            $status_date_col = str_replace('_status', '_decision_date', $status_col);
                            $status_date = isset($request[$status_date_col]) ? $request[$status_date_col] : null;
                            $status_class = get_status_class($status_value);

                            if ($status_value === 'Rejected') { 
                                $rejection_has_occurred = true;
                            }
                        }
                        
                        $is_current = ($role_name === $current_role);
                        ?>
                        <div class="flex-shrink-0 w-48 text-center">
                            <div class="p-4 rounded-lg flex flex-col items-center justify-center <?php echo $is_current ? 'bg-blue-50 border-2 border-blue-500' : 'bg-gray-50 border border-gray-200'; ?>">
                                <p class="text-sm font-bold text-gray-800"><?php echo htmlspecialchars($role_name); ?></p>
                                <span class="status-pill text-xs mt-2 <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($status_value); ?>
                                </span>
                                <?php if ($status_date): ?>
                                <span class="text-xs text-gray-500 mt-1">
                                    <?php echo date('M d, Y g:i A', strtotime($status_date)); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; 
                    // --- NEW REJECTION LOGIC (END) ---
                    ?>
                </div>
                
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Final Budget Status</h4>
                    <?php
                        $final_status_display = $request['notification_status'];
                        $final_status_class = get_status_class($request['final_status']);
                    ?>
                    <span class="status-pill text-sm <?php echo $final_status_class; ?>">
                        <?php echo htmlspecialchars($final_status_display); ?>
                    </span>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Supporting Attachments (<?php echo count($files); ?>)</h3>
                <?php if (!empty($files)): ?>
                    <ul class="space-y-2">
                        <?php foreach ($files as $file): ?>
                        <li class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <span class="text-sm text-gray-700 font-medium"><?php echo htmlspecialchars($file['original_file_name']); ?></span>
                            <a href="download_file.php?fid=<?php echo (int)$file['file_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold" target="_blank" rel="noopener noreferrer">Download</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-sm text-gray-500">No supporting documents were attached.</p>
                <?php endif; ?>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Remarks History</h3>
                <div class="space-y-4">
                    <?php
                    $remarks_map = [
                        'Adviser' => 'adviser_remark',
                        'Dean' => 'dean_remark',
                        'OSAFA' => 'osafa_remark',
                        'AFO' => 'afo_remark'
                    ];
                    $has_remarks = false;
                    foreach ($remarks_map as $role_name => $remark_col):
                        $remark_text = $request[$remark_col];
                        if (!empty($remark_text)):
                            $has_remarks = true;
                    ?>
                    <div class="border-l-4 border-gray-300 pl-4">
                        <p class="text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($role_name); ?> wrote:</p>
                        <p class="text-gray-600 italic">"<?php echo nl2br(htmlspecialchars($remark_text)); ?>"</p>
                    </div>
                    <?php 
                        endif; 
                    endforeach; 
                    
                    if (!$has_remarks):
                    ?>
                    <p class="text-sm text-gray-500">No remarks have been left on this request yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>

        <div class="lg:col-span-1">
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 sticky top-8">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Your Decision</h2>

                <?php
                $is_decided = ($request[$role_column] === 'Approved' || $request[$role_column] === 'Rejected');
                $is_ready_for_review = (!$previous_role_column || $request[$previous_role_column] === 'Approved');
                
                if ($is_decided):
                ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
                    <p class="font-bold">Decision Recorded</p>
                    <p>You have already submitted your decision (<?php echo htmlspecialchars($request[$role_column]); ?>) for this request.</p>
                </div>
                
                <?php elseif (!$is_ready_for_review): ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                    <p class="font-bold">Awaiting Prior Approval</p>
                    <p>This request must be approved by the <?php echo htmlspecialchars(array_search($previous_role_column, $approval_chain)); ?> before you can review it.</p>
                </div>
                
                <?php elseif ($rejection_has_occurred): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                    <p class="font-bold">Request Rejected</p>
                    <p>This request has already been rejected by a previous signatory. No further action is needed.</p>
                </div>

                <?php else: // Not decided and is ready for review ?>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $request_id; ?>" method="post">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select your decision:</label>
                        <div class="space-y-3">
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
                            placeholder="Enter justification..."><?php echo htmlspecialchars($remark); ?></textarea>
                    </div>

                    <button type="submit"
                        class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150">
                        Submit Decision
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php endif; ?>
</div>
<?php
// End the page
end_page();
?>