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

// --- Helper for CSS colors
if (!function_exists('get_status_class')) {
    function get_status_class($status) {
        switch ($status) {
            case 'Approved': return 'bg-green-100 text-green-800 border-green-500';
            case 'Rejected': return 'bg-red-100 text-red-800 border-red-500';
            case 'Pending':  return 'bg-yellow-100 text-yellow-800 border-yellow-500';
            case 'Budget Processing': return 'bg-blue-100 text-blue-800 border-blue-500';
            case 'Budget Available': return 'bg-emerald-100 text-emerald-800 border-emerald-500';
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

// Define the approval chain for display (used in "Awaiting" message)
$approval_chain = [
    'Adviser' => 'adviser_status',
    'Dean' => 'dean_status',
    'OSAFA' => 'osafa_status',
    'AFO' => 'afo_status'
];


// ===================================
// --- 2. HANDLE FORM SUBMISSION (POST) ---
// ===================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && $request_id > 0) {
    
    // === START: NEW EMAIL LOGIC (Include) ===
    // Using 'email.php' as seen in your provided code
    require_once 'email.php';
    // === END: NEW EMAIL LOGIC (Include) ===

    $decision = $_POST['decision'];
    $remark_text = trim($_POST['remark'] ?? ''); // remark might not exist on 'Available'

    // ✅ START AFO "Mark as Budget Available" LOGIC
    if ($decision === 'Available' && $current_role === 'AFO') {
        
        $sql = "UPDATE requests 
                SET 
                    final_status = 'Budget Available',
                    notification_status = 'Budget Available',
                    date_budget_available = NOW()
                WHERE 
                    request_id = ?";
                    
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $request_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Success! The budget has been marked as available and the officer has been notified.";

                // === START: UPGRADED EMAIL LOGIC (Budget Available) ===
                $officerDetails = getOfficerDetails($link, $request_id, 'funding'); // This now gets more data
                if ($officerDetails) {
                    $recipientEmail = $officerDetails['email'];
                    $recipientName = $officerDetails['full_name'];
                    
                    $subject = "Budget Available for Your Request (ID: $request_id)";
                    $greeting = "Dear $recipientName,";
                    $message = "Good news! The budget for your request is now <strong>available for claiming</strong>. Please see the details below and feel free to reach out if you have any questions.";
                    
                    // NEW: Create the details array
                    $details = [
                        "Request Title" => $officerDetails['activity_name'],
                        "Request Type" => $officerDetails['type'],
                        "Amount" => "PHP " . number_format($officerDetails['amount'], 2),
                        "Date Submitted" => date('F j, Y, g:i A', strtotime($officerDetails['date_submitted']))
                    ];

                    // NEW: Use the template builder
                    $body = buildEmailTemplate($greeting, $message, $details);
                    sendNotificationEmail($recipientEmail, $subject, $body);
                }
                // === END: UPGRADED EMAIL LOGIC (Budget Available) ===

            } else {
                $error_message = "Error: Could not mark budget as available. " . mysqli_error($link);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Error: Could not prepare the update statement. " . mysqli_error($link);
        }

    // ✅ END AFO "Mark as Budget Available" LOGIC

    // This is the original Approve/Reject logic
    } elseif (in_array($decision, ['Approved', 'Rejected']) && !empty($date_column)) {

        // Determine the next status for the user
        $next_notification_status = "";
        if ($decision === 'Approved') {
            switch ($current_role) {
                case 'Adviser': $next_notification_status = 'Awaiting Dean Review'; break;
                case 'Dean':    $next_notification_status = 'Awaiting OSAFA Review'; break;
                case 'OSAFA': $next_notification_status = 'Awaiting AFO Review'; break;
                case 'AFO':   $next_notification_status = 'Budget Processing'; break;
            }
        } else {
            // 'Rejected'
            $next_notification_status = "Rejected by " . $current_role;
        }
        
        $final_status_sql = "";
        if ($decision === 'Rejected') {
            $final_status_sql = ", final_status = 'Rejected'";
        } elseif ($current_role === 'AFO' && $decision === 'Approved') {
            $final_status_sql = ", final_status = 'Budget Processing'"; // Not final yet
        }

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

                // === START: UPGRADED EMAIL LOGIC (Rejection) ===
                if ($decision === 'Rejected') {
                    $officerDetails = getOfficerDetails($link, $request_id, 'funding'); // Gets more data
                    if ($officerDetails) {
                        $recipientEmail = $officerDetails['email'];
                        $recipientName = $officerDetails['full_name'];
                        
                        $subject = "Update on Your Funding Request: Rejected (ID: $request_id)";
                        $greeting = "Dear $recipientName,";
                        $message = "Your funding request has been <strong>rejected</strong> by the $current_role. Please see the details and reason below.";
                        
                        // NEW: Create the details array
                        $details = [
                            "Request Title" => $officerDetails['activity_name'],
                            "Request Type" => $officerDetails['type'],
                            "Amount" => "PHP " . number_format($officerDetails['amount'], 2),
                            "Date Submitted" => date('F j, Y, g:i A', strtotime($officerDetails['date_submitted']))
                        ];

                        // NEW: Use template builder with the reason
                        $body = buildEmailTemplate($greeting, $message, $details, "Reason for Rejection", $remark_text);
                        sendNotificationEmail($recipientEmail, $subject, $body);
                    }
                }
                // === END: UPGRADED EMAIL LOGIC (Rejection) ===

            } else {
                $error_message = "Error: Could not execute the update. " . mysqli_error($link);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Error: Could not prepare the update statement. " . mysqli_error($link);
        }
    }
}

// ===================================
// --- 3. FETCH REQUEST DATA (GET) ---
// ===================================
// (This entire section is unchanged from your original file)
if ($request_id > 0) {
    // Check for success from POST redirect (if we add one)
    if (isset($_GET['success']) && $_GET['success'] == '1' && empty($success_message)) {
        $success_message = "Your decision has been recorded successfully.";
    }

    $sql = "SELECT 
                r.*, 
                u.full_name, 
                o.org_name,
                r.adviser_decision_date,
                r.dean_decision_date,
                r.osafa_decision_date,
                r.afo_decision_date,
                r.date_budget_available 
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
        if (!($current_role === 'AFO' && $request['final_status'] === 'Budget Processing')) {
            $error_message = "This request is not yet ready for your review. It is awaiting approval from a previous signatory.";
        }
    }
    
} else {
    $error_message = "Invalid request ID.";
}

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
    <!-- The entire HTML display section is unchanged -->
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
                    ?>
                </div>
                
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Final Budget Status</h4>
                    <?php
                        $final_status_display = $request['notification_status'];
                        $final_status_class = get_status_class($request['final_status']);

                        if ($request['final_status'] === 'Budget Available') {
                            $final_status_display = 'Budget Available';
                            $final_status_class = get_status_class('Budget Available');
                        } elseif ($request['final_status'] === 'Budget Processing') {
                            $final_status_display = 'Budget Processing';
                            $final_status_class = get_status_class('Budget Processing');
                        }
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
                
                <!-- This entire dynamic decision box is unchanged -->
                <?php
                $my_status = $request[$role_column];
                $final_status = $request['final_status'];
                $is_ready_for_review = (!$previous_role_column || $request[$previous_role_column] === 'Approved');
                
                if ($current_role === 'AFO' && $my_status === 'Approved' && $final_status === 'Budget Processing') {
                ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Budget Status</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-4" role="alert">
                        <p class="font-bold">Budget Processing</p>
                        <p>You have approved this request. It is now awaiting budget availability. Click below once the budget is ready for release.</p>
                    </div>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $request_id; ?>" method="post">
                        <button type="submit" name="decision" value="Available"
                            class="w-full bg-green-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150">
                            Mark Budget as Available
                        </button>
                    </form>

                <?php
                } elseif ($final_status === 'Budget Available' || $final_status === 'Rejected') {
                    $is_decided = ($my_status === 'Approved' || $my_status === 'Rejected');
                ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Request Completed</h2>
                    
                    <?php if ($final_status === 'Budget Available'): ?>
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4" role="alert">
                            <p class="font-bold">Budget Available</p>
                            <p>This request has been fully approved and the budget is available.</p>
                        </div>
                    <?php else: // Rejected ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                            <p class="font-bold">Request Rejected</p>
                            <p>This request was rejected. No further action is needed.</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($is_decided && $current_role !== 'AFO'): ?>
                    <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mt-4" role="alert">
                         <p class="font-bold">Your Decision</p>
                         <p>You recorded a decision of (<?php echo htmlspecialchars($my_status); ?>) for this request.</p>
                    </div>
                    <?php endif; ?>

                <?php
                } elseif (!$is_ready_for_review) {
                    $prev_role_name = array_search($previous_role_column, $approval_chain);
                    if ($prev_role_name === false) $prev_role_name = 'a previous signatory';
                ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Your Decision</h2>
                    <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
                        <p class="font-bold">Awaiting Prior Approval</p>
                        <p>This request must be approved by the <?php echo htmlspecialchars($prev_role_name); ?> before you can review it.
                        </p>
                    </div>

                <?php
                } elseif ($my_status === 'Pending' && $final_status !== 'Rejected') {
                ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Your Decision</h2>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?id=<?php echo $request_id; ?>" method="post">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select your decision:</label>
                            <div class="space-y-3">
                                <label class="flex items-center cursor-pointer bg-green-50 hover:bg-green-100 p-3 rounded-lg border-2 border-green-300">
                                    <input type="radio" name="decision" value="Approved" class="form-radio h-5 w-5 text-green-600" required>
                                    <span class="ml-2 font-semibold text-green-700">Approve</span>
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
                
                <?php 
                } else {
                    $is_decided = ($my_status === 'Approved' || $my_status === 'Rejected');
                    if ($is_decided) {
                ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Decision Recorded</h2>
                    <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
                         <p class="font-bold">Decision Recorded</p>
                         <p>You have already submitted your decision (<?php echo htmlspecialchars($my_status); ?>) for this request. It is awaiting action from the next signatory.</p>
                    </div>
                <?php } else { ?>
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Error</h2>
                     <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                         <p class="font-bold">Unknown State</p>
                         <p>The request is in an unknown state. Please contact the administrator.</p>
                    </div>
                <?php
                    }
                }
                ?>
            </div>
        </div>

    </div>
    <?php endif; ?>
</div>
<?php
// End the page
end_page();
?>