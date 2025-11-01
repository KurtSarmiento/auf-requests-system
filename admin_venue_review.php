<?php
// admin_venue_review.php
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Ensure only admin roles can access
$admin_roles = ['Dean', 'Admin Services', 'OSAFA', 'CFDO', 'AFO', 'VP for Academic Affairs', 'VP for Administration'];
$current_role = isset($_SESSION["role"]) ? $_SESSION["role"] : '';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($current_role, $admin_roles)) {
    header("location: login.php");
    exit;
}

// Helper for CSS colors (moved to layout_template.php, but good to have a fallback)
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
$error_message = $success_message = "";
$remark = "";
$request = null;
$schedule = []; // To hold event schedule

// ===================================
// --- 1. DEFINE ROLE LOGIC & HIERARCHY (FIXED) ---
// ===================================
// Map [My_Status_Col, My_Remark_Col, My_Date_Col, Next_Status_Col, Next_Notif_Msg, Prev_Status_Col]
$role_map = [
    'Dean' => [
        'dean_status', 'dean_remark', 'dean_decision_date', 'admin_services_status', 'Awaiting Admin Services Approval', null
    ],
    'Admin Services' => [
        'admin_services_status', 'admin_services_remark', 'admin_services_decision_date', 'osafa_status', 'Awaiting OSAFA Approval', 'dean_status'
    ],
    'OSAFA' => [
        'osafa_status', 'osafa_remark', 'osafa_decision_date', 'cfdo_status', 'Awaiting CFDO Approval', 'admin_services_status'
    ],
    'CFDO' => [
        'cfdo_status', 'cfdo_remark', 'cfdo_decision_date', 'afo_status', 'Awaiting AFO Approval', 'osafa_status'
    ],
    'AFO' => [
        'afo_status', 'afo_remark', 'afo_decision_date', 'vp_acad_status', 'Awaiting VP for Academic Affairs Approval', 'cfdo_status'
    ],
    'VP for Academic Affairs' => [
        'vp_acad_status', 'vp_acad_remark', 'vp_acad_decision_date', 'vp_admin_status', 'Awaiting VP for Administration Approval', 'afo_status'
    ],
    'VP for Administration' => [
        'vp_admin_status', 'vp_admin_remark', 'vp_admin_decision_date', null, 'Venue Approved', 'vp_acad_status' // Final step
    ],
];

$role_data = isset($role_map[$current_role]) ? $role_map[$current_role] : null;

// ===================================
// --- 2. HANDLE FORM SUBMISSION (POST) ---
// ===================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && $request_id > 0 && $role_data) {
    
    // === START: NEW EMAIL LOGIC (Include) ===
    // Include the email functions at the start of the POST handling
    require_once 'email.php';
    // === END: NEW EMAIL LOGIC (Include) ===

    $decision = $_POST['decision'];
    $remark_text = trim($_POST['remark']);

    // Get column names from role_data
    $role_column = $role_data[0];
    $remark_column = $role_data[1];
    $date_column = $role_data[2]; // <-- The fixed variable
    $next_role_column = $role_data[3];
    $next_notification_status = $role_data[4];
    
    // Handle Rejection
    if ($decision === 'Rejected') {
        $next_notification_status = "Rejected by " . $current_role;
        $final_status_sql = ", final_status = 'Rejected'";
    } else {
        // Handle Approval
        $final_status_sql = "";
        // If this is the final step, set final_status to Approved
        if ($current_role === 'VP for Administration') {
            $next_notification_status = 'Venue Approved'; // Set final notif message
            $final_status_sql = ", final_status = 'Approved'";
        }
    }
    
    // --- UPDATED SQL: Using {$date_column} (e.g., dean_decision_date) ---
    $sql = "UPDATE venue_requests 
            SET 
                {$role_column} = ?,
                {$date_column} = NOW(),
                {$remark_column} = ?,
                notification_status = ?
                {$final_status_sql}
            WHERE 
                venue_request_id = ?";
                
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssi", $decision, $remark_text, $next_notification_status, $request_id);
        
        if (mysqli_stmt_execute($stmt)) {
            
            // === START: NEW EMAIL LOGIC (Rejection) ===
            // We send an email ONLY if the decision is 'Rejected'
            if ($decision === 'Rejected') {
                // $request_id is the venue_request_id
                $officerDetails = getOfficerDetails($link, $request_id, 'venue'); 
                
                if ($officerDetails) {
                    $recipientEmail = $officerDetails['email'];
                    $recipientName = $officerDetails['full_name'];
                    $activityName = $officerDetails['activity_name']; // This is the alias for 'title'

                    $subject = "Update on your Venue Request: Rejected";
                    $body = "Dear $recipientName,<br><br>
                            Your venue request for <strong>'$activityName'</strong> (ID: $request_id) has been <strong>rejected</strong> by the $current_role.<br><br>
                            <strong>Reason:</strong> " . ($remark_text ?: 'No remarks provided.') . "<br><br>
                            Please check the system for more details.";
                    
                    sendNotificationEmail($recipientEmail, $subject, $body);
                }
            }
            // === END: NEW EMAIL LOGIC (Rejection) ===

            $success_message = "Your decision ($decision) has been recorded successfully.";

        } else {
            $error_message = "Error: Could not execute the update. " . mysqli_error($link);
        }
        mysqli_stmt_close($stmt);
    } else {
        $error_message = "Error: Could not prepare the update statement. " . mysqli_error($link);
    }
} elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    $error_message = "Invalid request or role configuration.";
}

// ===================================
// --- 3. FETCH REQUEST DATA (GET) ---
// ===================================
if ($request_id > 0 && $role_data) {
    $role_column = $role_data[0];
    $remark_column = $role_data[1];
    $previous_role_column = $role_data[5]; // Index is 5 now

    // --- UPDATED SQL: Select all _decision_date columns + description ---
    $sql = "SELECT 
                vr.*, 
                u.full_name, 
                o.org_name,
                vr.description,
                vr.dean_decision_date,
                vr.admin_services_decision_date,
                vr.osafa_decision_date,
                vr.cfdo_decision_date,
                vr.afo_decision_date,
                vr.vp_acad_decision_date,
                vr.vp_admin_decision_date
            FROM venue_requests vr
            JOIN users u ON vr.user_id = u.user_id
            JOIN organizations o ON u.org_id = o.org_id
            WHERE vr.venue_request_id = ?";
            
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 1) {
            $request = mysqli_fetch_assoc($result);
            $remark = $request[$remark_column]; // Load existing remark
            
            // Fetch schedule
            $schedule_sql = "SELECT * FROM venue_schedule WHERE venue_request_id = ? ORDER BY activity_date, start_time";
            if ($stmt_sched = mysqli_prepare($link, $schedule_sql)) {
                mysqli_stmt_bind_param($stmt_sched, "i", $request_id);
                mysqli_stmt_execute($stmt_sched);
                $result_sched = mysqli_stmt_get_result($stmt_sched);
                while ($row = mysqli_fetch_assoc($result_sched)) {
                    $schedule[] = $row;
                }
                mysqli_stmt_close($stmt_sched);
            }
        } else {
            $error_message = "Venue request not found.";
        }
        mysqli_stmt_close($stmt);
    }
    
    // Check if the request is actually ready for review by this role
    if ($request && $previous_role_column && $request[$previous_role_column] !== 'Approved') {
        $error_message = "This request is not yet ready for your review. It is awaiting approval from a previous signatory.";
    }
    
} elseif (!$role_data) {
    $error_message = "Your role ($current_role) is not configured to approve venue requests.";
} else {
    $error_message = "Invalid request ID.";
}

// Define the full approval chain for display
$approval_chain = [
    'Dean' => 'dean_status',
    'Admin Services' => 'admin_services_status',
    'OSAFA' => 'osafa_status',
    'CFDO' => 'cfdo_status',
    'AFO' => 'afo_status',
    'VP for Academic Affairs' => 'vp_acad_status',
    'VP for Administration' => 'vp_admin_status'
];

// Start the page
start_page("Review Venue Request", $current_role, $_SESSION["full_name"]);
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
                <span class="text-sm font-semibold text-purple-600 bg-purple-50 px-3 py-1 rounded-full">Venue Request</span>
                <h1 class="text-4xl font-extrabold text-gray-900 mt-2 mb-1"><?php echo htmlspecialchars($request['title']); ?></h1>
                <p class="text-lg text-gray-600">
                    Activity Title
                </p>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Request Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <span class="text-sm font-semibold text-gray-500">Organization</span>
                        <p class="text-lg text-gray-800"><?php echo htmlspecialchars($request['org_name']); ?></p>
                    </div>
                    <div>
                        <span class="text-sm font-semibold text-gray-500">Submitted By</span>
                        <p class="text-lg text-gray-800"><?php echo htmlspecialchars($request['full_name']); ?></p>
                    </div>
                    <div class="md:col-span-2">
                        <span class="text-sm font-semibold text-gray-500">Date Submitted</span>
                        <p class="text-lg text-gray-800"><?php echo date('M d, Y h:i A', strtotime($request['date_submitted'])); ?></p>
                    </div>
                    <div class="md:col-span-2">
                        <span class="text-sm font-semibold text-gray-500">Description / Purpose</span>
                        <p class="text-gray-700 mt-1 prose max-w-none">
                            <?php echo isset($request['description']) ? nl2br(htmlspecialchars($request['description'])) : 'N/A'; ?>
                        </p>
                    </div>
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
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Final Venue Status</h4>
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
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Venue & Schedule</h3>
                <ul class="divide-y divide-gray-200">
                    <?php if (empty($schedule)): ?>
                        <p class="text-sm text-gray-500">No schedule details found for this request.</p>
                    <?php else: ?>
                        <?php foreach ($schedule as $item): ?>
                        <li class="py-4">
                            <strong class="text-gray-900"><?php echo htmlspecialchars($item['venue_name']); ?></strong>
                            <?php if (!empty($item['venue_other_name'])): ?>
                            <span class="text-gray-600">(<?php echo htmlspecialchars($item['venue_other_name']); ?>)</span>
                            <?php endif; ?>
                            <div class="text-sm text-gray-600 mt-1">
                                <span class="font-medium"><?php echo date('D, M d, Y', strtotime($item['activity_date'])); ?></span>
                                <span class="mx-2">|</span>
                                <span><?php echo date('g:i A', strtotime($item['start_time'])); ?> to <?php echo date('g:i A', strtotime($item['end_time'])); ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Equipment & Other Needs</h3>
            
            <?php
            // 1. Get the JSON data from the correct column
            $equipment_data = $request['equipment_details'] ?? null;
            $decoded_equipment = json_decode($equipment_data, true);
            
            // 2. Extract the 'other' text from the JSON
            $other_text_from_json = $decoded_equipment['other'] ?? null;

            // 3. Check if we have valid, non-empty JSON
            if (is_array($decoded_equipment) && !empty($decoded_equipment)) {
                
                echo '<p class="text-sm font-semibold text-gray-700">Equipment/Logistics:</p>';
                // Use the same grid styling as before
                echo '<div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-4">';
                
                $has_equipment = false;
                foreach ($decoded_equipment as $item => $quantity) {
                    
                    // 4. Skip the 'other' key in this grid
                    if ($item === 'other') {
                        continue; 
                    }

                    // 5. Only show items with a quantity > 0
                    if (is_numeric($quantity) && $quantity > 0) {
                        $has_equipment = true;
                        // Use the existing styling for the items
                        echo '<div class="bg-gray-50 p-3 rounded-lg border border-gray-200">';
                        echo '  <span class="text-xs text-gray-500">' . htmlspecialchars(ucfirst($item)) . '</span>';
                        echo '  <p class="text-lg font-bold text-gray-800">' . htmlspecialchars($quantity) . '</p>';
                        echo '</div>';
                    }
                }
                
                if (!$has_equipment) {
                    echo "<i class='text-gray-500 col-span-full p-3'>No specific equipment quantities were listed.</i>";
                }
                
                echo '</div>'; // end grid
            
            } else {
                // Fallback if JSON is empty or invalid
                echo "<div class='mt-2 text-gray-800 bg-gray-50 p-4 rounded-md border text-sm'>";
                echo "<i class='text-gray-500'>No equipment listed.</i>";
                echo "</div>";
            }
            ?>

            <div class="mt-4 pt-4 border-t border-gray-200">
                <p class="text-sm font-semibold text-gray-700">Other Materials/Notes:</p>
                <div class="text-gray-600 p-3 bg-gray-50 rounded-lg border border-gray-200 mt-1">
                <?php 
                echo !empty($other_text_from_json) 
                    ? nl2br(htmlspecialchars($other_text_from_json)) 
                    : "<i class='text-gray-500'>No other materials listed.</i>"; 
                ?>
                </div>
            </div>
        </div>
            
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Remarks History</h3>
                <div class="space-y-4">
                    <?php
                    $remarks_map = [
                        'Dean' => 'dean_remark',
                        'Admin Services' => 'admin_services_remark',
                        'OSAFA' => 'osafa_remark',
                        'CFDO' => 'cfdo_remark',
                        'AFO' => 'afo_remark',
                        'VP for Academic Affairs' => 'vp_acad_remark',
                        'VP for Administration' => 'vp_admin_remark'
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
                $role_column = $role_data[0];
                $previous_role_column = $role_data[5]; // Index 5
                
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
                    <p>This request must be approved by the <?php echo htmlspecialchars(array_search($previous_role_column, array_column($role_map, 0))); ?> before you can review it.</p>
                </div>
                
                <?php elseif ($rejection_has_occurred): // <-- NEW CHECK ?>
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
                    <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
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
