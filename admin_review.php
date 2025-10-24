<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is NOT an Officer
$admin_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], $admin_roles)) {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

// Define current role FIRST
$current_role = $_SESSION["role"];
$user_org_id = isset($_SESSION["org_id"]) ? (int)$_SESSION["org_id"] : 0;
$request = null;
$error_message = $success_message = "";
// $remark is initialized here to ensure the textarea doesn't error out if no POST data is present
$remark = "";
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Determine the SQL column names corresponding to the current user's role.
// Format: [0] = Current Status Column, [1] = Next Status Column, [2] = Next Notification Status
$role_data = match($current_role) {
    'Adviser' => ['adviser_status', 'dean_status', 'Awaiting Dean Approval'],
    'Dean' => ['dean_status', 'osafa_status', 'Awaiting OSAFA Approval'],
    'OSAFA' => ['osafa_status', 'afo_status', 'Awaiting AFO Approval'],
    'AFO' => ['afo_status', null, 'Budget Available'], // Final stage, no next status column
    default => [null, null, null],
};

$status_column = $role_data[0]; // e.g., 'adviser_status'
$next_status_column = $role_data[1]; // e.g., 'dean_status' (or null for AFO)
$next_notification = $role_data[2]; // e.g., 'Awaiting Dean Approval'

if (!$status_column) {
    die("Error: Unrecognized administrative role.");
}

// --- 1. Handle Form Submission (Decision) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $request_id > 0) {
    $decision = trim($_POST['decision'] ?? ''); // 'Approved' or 'Rejected'
    $remark = trim($_POST['remark'] ?? ''); // Capture the remark content
    
    $valid_decisions = ['Approved', 'Rejected'];

    // Determine the correct database column to store this remark
    $remark_column = match($current_role) {
        'Adviser' => 'adviser_remark',
        'Dean' => 'dean_remark',
        'OSAFA' => 'osafa_remark',
        'AFO' => 'afo_remark',
        default => null,
    };
    
    if (!$remark_column || !in_array($decision, $valid_decisions)) {
        $error_message = "Invalid decision submitted or system error finding remark column.";
    } else {
        
        // Start transaction
        if (!mysqli_begin_transaction($link)) {
            $error_message = "Unable to start database transaction: " . mysqli_error($link);
        } else {
            try {
                // --- A. Update current stage status AND ADD REMARK ---
                $update_sql = "
                    UPDATE requests 
                    SET {$status_column} = ?, 
                        {$remark_column} = ?,
                        date_updated = NOW() 
                    WHERE request_id = ?
                ";
                
                if ($stmt = mysqli_prepare($link, $update_sql)) {
                    // Binding: (s) for decision, (s) for remark, (i) for request_id
                    if (!mysqli_stmt_bind_param($stmt, "ssi", $decision, $remark, $request_id)) {
                        throw new Exception("Error binding parameters for update: " . mysqli_stmt_error($stmt));
                    }

                    if (!mysqli_stmt_execute($stmt)) {
                        $error = mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                        throw new Exception("Error executing update query: " . $error);
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    throw new Exception("Error preparing update statement: " . mysqli_error($link));
                }
                
                // --- B. Update final/notification status based on decision and role ---
                $updates = [];
                $params = [];
                $types = "";

                if ($decision === 'Rejected') {
                    // 1. Set global final statuses
                    $updates[] = "final_status = ?";
                    $params[] = 'Rejected';
                    $types .= "s";
                    
                    $updates[] = "notification_status = ?";
                    $params[] = 'Rejected';
                    $types .= "s";
                    
                    // 2. Explicitly set ALL subsequent status columns to '-' 
                    $roles_in_order = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
                    $role_status_map = [
                        'Adviser' => 'adviser_status',
                        'Dean' => 'dean_status',
                        'OSAFA' => 'osafa_status',
                        'AFO' => 'afo_status',
                    ];
                    
                    $current_role_index = array_search($current_role, $roles_in_order);
                    
                    // Loop through all roles that follow the current one
                    for ($i = $current_role_index + 1; $i < count($roles_in_order); $i++) {
                        $subsequent_column = $role_status_map[$roles_in_order[$i]];
                        $updates[] = "{$subsequent_column} = ?";
                        $params[] = '-'; // Set subsequent statuses to '-'
                        $types .= "s";
                    }

                } elseif ($current_role === 'AFO') {
                    // AFO Approval (Final Step)
                    $updates[] = "final_status = ?";
                    $params[] = 'Approved';
                    $types .= "s";
                    
                    $updates[] = "notification_status = ?";
                    $params[] = 'Budget Available';
                    $types .= "s";

                } else {
                    // Approval at intermediary stage, advance notification to next stage
                    $updates[] = "notification_status = ?";
                    $params[] = $next_notification;
                    $types .= "s";
                }

                if (!empty($updates)) {
                    $update_notifications_sql = "UPDATE requests SET " . implode(", ", $updates) . " WHERE request_id = ?";
                    $params[] = $request_id;
                    $types .= "i";

                    if ($stmt = mysqli_prepare($link, $update_notifications_sql)) {
                        $bind_params = array_merge([$types], $params);
                        
                        // This section is for dynamic parameter binding
                        $refs = [];
                        foreach ($bind_params as $key => &$value) {
                            $refs[$key] = &$bind_params[$key];
                        }

                        // Use call_user_func_array for dynamic binding
                        if (!call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $refs))) {
                            throw new Exception("Error binding parameters for notification update: " . mysqli_stmt_error($stmt));
                        }

                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception("Error executing notification update query: " . mysqli_stmt_error($stmt));
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        throw new Exception("Error preparing notification update statement: " . mysqli_error($link));
                    }
                }

                // Commit if we reached here without exception
                if (!mysqli_commit($link)) {
                    throw new Exception("Failed to commit transaction: " . mysqli_error($link));
                }

                $success_message = "Decision recorded successfully.";
            } catch (Exception $e) {
                // Rollback on any exception and show the error
                mysqli_rollback($link);
                $error_message = "Transaction failed: " . $e->getMessage();
            }
        }
    }
}


// --- 2. Fetch Request Details (includes the critical rejection check) ---
if ($request_id > 0) {
    $sql = "
        SELECT 
            r.*, 
            o.org_name, 
            u.full_name AS submitted_by,
            u.org_id
        FROM 
            requests r
        JOIN 
            users u ON r.user_id = u.user_id
        JOIN 
            organizations o ON u.org_id = o.org_id
        WHERE 
            r.request_id = ?
    ";

    $types = "i"; // Type string starts with 'i' for request_id
    $params = [$request_id]; // Parameters array starts with request_id

    // Conditional filtering for Adviser role
    if ($current_role === 'Adviser') {
        if ($user_org_id > 0) {
            // Apply organization ID filter and add parameter
            $sql .= " AND u.org_id = ?";
            $types .= "i"; // Append 'i' for integer type
            $params[] = $user_org_id; // Add the org_id parameter
        } else {
            // If the Adviser has no org_id, show an explicit error and stop.
            $error_message = "Adviser account is not linked to any organization (org_id is missing or 0). Cannot view requests.";
            $request_id = 0; // Invalidates the fetch attempt below
        }
    }
    
    // Only proceed if request_id is still valid
    if ($request_id > 0 && $stmt = mysqli_prepare($link, $sql)) {

        // --- DYNAMIC BINDING for FETCH QUERY ---
        $bind_params = [];
        $bind_params[] = $types;
        foreach ($params as $key => &$value) {
            $bind_params[] = &$value;
        }
        
        if (!call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_params))) {
            $error_message = "Error binding parameters for fetch query: " . $stmt->error;
        }
        // --- END DYNAMIC BINDING ---

        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result) == 1) {
                $request = mysqli_fetch_assoc($result);
                // If the POST failed, we try to re-fetch the remark so the user doesn't lose it
                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remark'])) {
                    $remark = trim($_POST['remark']);
                }

                // --- CRITICAL NEW LOGIC: Check Previous Signatory's Status ---
                $previous_status_col = match($current_role) {
                    'Dean' => 'adviser_status',
                    'OSAFA' => 'dean_status',
                    'AFO' => 'osafa_status',
                    default => null, // Adviser has no previous signatory
                };

                if ($previous_status_col) {
                    $previous_status = $request[$previous_status_col];
                    
                    if ($previous_status === 'Rejected') {
                        // Case 1: Rejected by previous party
                        $error_message = "This request was **Rejected** by the previous signatory and is no longer available for your review.";
                        $request = null; // Block the request details from being displayed
                    }
                    elseif ($request[$status_column] === 'Pending' && $previous_status !== 'Approved') {
                        // Case 2: Not yet approved by previous party (and not rejected)
                        $previous_role_name = str_replace(['_status', 'adviser', 'dean', 'osafa'], ['', 'Adviser', 'Dean', 'OSAFA'], $previous_status_col);
                        $error_message = "This request is not yet ready for your review. It must first be **Approved** by the **" . ucfirst($previous_role_name) . "**.";
                        $request = null; // Prevent display
                    }
                }
                // --- END CRITICAL NEW LOGIC ---

            } else {
                // Improved message to hint at permission issues
                if ($current_role === 'Adviser') {
                    $error_message = "Request #".$request_id." not found or it does not belong to your organization (Org ID: ".$user_org_id.").";
                } else {
                    $error_message = "Request not found or you do not have permission to view it.";
                }
            }
        } else {
            $error_message = "Error executing request fetch query: " . mysqli_error($link);
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $error_message = "No request ID provided or user role error.";
}

// Close connection before HTML output
mysqli_close($link);

// Utility function (copied from list page for styling consistency)
function get_status_class($status) {
    return match ($status) {
        'Approved', 'Budget Available' => 'bg-green-600 text-white font-bold',
        'Rejected' => 'bg-red-600 text-white font-bold',
        'Awaiting AFO Approval' => 'bg-yellow-600 text-white font-bold',
        // --- ADD THIS LINE ---
        '-' => 'bg-gray-400 text-white font-bold', 
        // ---------------------
        default => 'bg-blue-600 text-white font-bold',
    };
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Request #<?php echo htmlspecialchars($request_id); ?> | AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .status-pill { padding: 4px 12px; border-radius: 9999px; font-size: 0.9rem; display: inline-block; }
    </style>
</head>
<body class="min-h-screen">
    
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Admin Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="admin_dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <a href="admin_request_list.php" class="hover:text-indigo-200 transition duration-150">Review Queue</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b> (<?php echo htmlspecialchars($current_role); ?>)
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="mb-8">
            <a href="admin_request_list.php" class="text-indigo-600 hover:text-indigo-800 font-medium transition duration-150">
                &larr; Back to Review Queue
            </a>
        </div>
        
        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-8 rounded-lg" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-8 rounded-lg" role="alert">
                <p class="font-bold">Success</p>
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($request): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 bg-white p-6 sm:p-8 rounded-xl shadow-2xl">
                <h2 class="text-3xl font-extrabold text-gray-900 border-b pb-4 mb-4">
                    Request #<?php echo htmlspecialchars($request['request_id']); ?>: <?php echo htmlspecialchars($request['title']); ?>
                </h2>

                <div class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm font-medium text-gray-500">Submitted By / Organization</p>
                            <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($request['submitted_by']); ?></p>
                            <p class="text-base text-gray-600"><?php echo htmlspecialchars($request['org_name']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-500">Request Type / Amount</p>
                            <p class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($request['type']); ?></p>
                            <p class="text-base text-gray-600">
                                <?php echo $request['amount'] !== null ? '₱' . number_format($request['amount'], 2) : 'N/A'; ?>
                            </p>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-2">Detailed Description</p>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-gray-700 whitespace-pre-wrap">
                            <?php echo htmlspecialchars($request['description']); ?>
                        </div>
                    </div>

                    <div class="border-t pt-4">
                        <h3 class="text-xl font-semibold text-gray-800 mb-3">Supporting Documents (Files)</h3>
                        <div class="bg-green-50 text-green-800 p-4 rounded-lg">
                            <p class="text-sm">File upload functionality is now implemented in **request_create.php**. This area should show attached documents.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-1 space-y-8">
                <div class="bg-white p-6 rounded-xl shadow-2xl">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 border-b pb-2">Approval Flow Status</h3>
                    
                    <div class="space-y-3">
                        <?php 
                        $stages = [
                            'Adviser' => 'adviser_status', 
                            'Dean' => 'dean_status', 
                            'OSAFA Head' => 'osafa_status', 
                            'AFO Head' => 'afo_status'
                        ];
                        foreach ($stages as $label => $col) {
                            $status = $request[$col];
                            echo '<div class="flex justify-between items-center">';
                            echo '<span class="font-medium text-gray-600">' . htmlspecialchars($label) . ':</span>';
                            echo '<span class="status-pill ' . get_status_class($status) . '">' . htmlspecialchars($status) . '</span>';
                            echo '</div>';
                        }
                        ?>
                        <div class="pt-4 border-t mt-4">
                            <p class="text-sm font-medium text-gray-500">Officer Final Notification Status:</p>
                            <span class="status-pill <?php echo get_status_class($request['notification_status']); ?> mt-1">
                                <?php echo htmlspecialchars($request['notification_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-2xl border-2 border-indigo-200">
                    <h3 class="text-2xl font-bold text-indigo-700 mb-4">Your Decision (<?php echo htmlspecialchars($current_role); ?>)</h3>
                    
                    <?php 
                    $current_status = $request[$status_column];
                    
                    if ($current_status !== 'Pending'): 
                    ?>
                        <div class="bg-gray-100 p-4 rounded-lg text-lg font-semibold text-gray-700">
                            Decision already recorded as: 
                            <span class="status-pill <?php echo get_status_class($current_status); ?> ml-2">
                                <?php echo htmlspecialchars($current_status); ?>
                            </span>
                        </div>
                        <p class="mt-4 text-sm text-gray-500">This request has already been processed by your office.</p>
                    
                    <?php else: ?>

                        <form method="POST" action="admin_review.php?id=<?php echo htmlspecialchars($request_id); ?>" class="space-y-4">
                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request_id); ?>">

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Select Action:</label>
                                <div class="flex space-x-4">
                                    <label class="flex items-center cursor-pointer bg-green-50 hover:bg-green-100 p-3 rounded-lg border-2 border-green-300">
                                        <input type="radio" name="decision" value="Approved" required class="form-radio h-5 w-5 text-green-600">
                                        <span class="ml-2 font-semibold text-green-700">Approve</span>
                                    </label>
                                    <label class="flex items-center cursor-pointer bg-red-50 hover:bg-red-100 p-3 rounded-lg border-2 border-red-300">
                                        <input type="radio" name="decision" value="Rejected" class="form-radio h-5 w-5 text-red-600">
                                        <span class="ml-2 font-semibold text-red-700">Reject</span>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="remark" class="block text-sm font-medium text-gray-700 mb-2">
                                    Remarks / Reason for Decision <span class="text-xs text-gray-500">(Required for rejection, recommended for approval)</span>
                                </label>
                                <textarea id="remark" name="remark" rows="4" 
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150"
                                        placeholder="Enter your detailed justification for the decision..."><?php echo htmlspecialchars($remark); ?></textarea>
                            </div>

                            <button type="submit" 
                                        class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md transform hover:scale-[1.01] active:scale-95">
                                Submit Decision
                            </button>
                        </form>

                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>