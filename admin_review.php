<?php
// Initialize the session
session_start();
 
// Check if the user is logged in AND has an administrative role
$admin_roles = ['Adviser', 'Dean', 'OSAFA', 'AFO'];
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], $admin_roles)) {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

$role = $_SESSION["role"];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$request = null;
$files = []; // Initialize files array
$error_message = "";
$decision_err = "";

// Determine status column based on user role
$status_column = '';
switch ($role) {
    case 'Adviser': $status_column = 'adviser_status'; break;
    case 'Dean': $status_column = 'dean_status'; break;
    case 'OSAFA': $status_column = 'osafa_status'; break;
    case 'AFO': $status_column = 'afo_status'; break;
}

// Function to determine CSS class for status pill
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-100 text-green-800 border-green-500';
        case 'Rejected':
            return 'bg-red-100 text-red-800 border-red-500';
        case 'Awaiting AFO Approval':
            return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        case 'Budget Available':
            return 'bg-purple-100 text-purple-800 border-purple-500 font-bold';
        case 'Pending':
        default:
            return 'bg-blue-100 text-blue-800 border-blue-500';
    }
}

// --- 1. Fetch Request Details ---
$sql = "
    SELECT 
        r.*, 
        u.full_name AS submitted_by_name, 
        o.org_name
    FROM 
        requests r
    JOIN 
        users u ON r.user_id = u.user_id
    JOIN
        organizations o ON u.org_id = o.org_id
    WHERE 
        r.request_id = ? 
";

// If Adviser, restrict to their org
if ($role === 'Adviser') {
    $sql .= " AND u.org_id = " . $_SESSION['org_id'];
}

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $request_id);

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) == 1) {
            $request = mysqli_fetch_assoc($result);
            // Check if this request is actually pending for *this* role
            if ($request[$status_column] !== 'Pending') {
                $error_message = "This request is no longer pending your review. Its current status for you is " . htmlspecialchars($request[$status_column]) . ".";
            }
        } else {
            $error_message = "Request not found or you do not have permission to view it.";
        }
    } else {
        $error_message = "Database execution error fetching request: " . mysqli_error($link);
    }
    mysqli_stmt_close($stmt);
} else {
    $error_message = "Database statement preparation error for request: " . mysqli_error($link);
}

// --- 2. Fetch Attached Files (only if request was found) ---
if ($request) {
    $sql_files = "
        SELECT 
            file_id, original_file_name, file_name
        FROM 
            files 
        WHERE 
            request_id = ?
    ";
    
    if ($stmt_files = mysqli_prepare($link, $sql_files)) {
        mysqli_stmt_bind_param($stmt_files, "i", $request_id);
        
        if (mysqli_stmt_execute($stmt_files)) {
            $result_files = mysqli_stmt_get_result($stmt_files);
            $files = mysqli_fetch_all($result_files, MYSQLI_ASSOC);
        } else {
            error_log("Failed to fetch files for request ID $request_id: " . mysqli_error($link));
        }
        mysqli_stmt_close($stmt_files);
    }
}


// --- 3. Handle Form Submission (Decision) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error_message)) {
    $action = trim($_POST['action'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    
    if (empty($action)) {
        $decision_err = "Please select an action (Approve or Reject).";
    } elseif ($action === 'Reject' && empty($remarks)) {
        $decision_err = "Remarks are required for rejection.";
    }

    if (empty($decision_err)) {
        $new_status = $action === 'Approve' ? 'Approved' : 'Rejected';
        
        // Start transaction
        mysqli_begin_transaction($link);
        $success = true;

        try {
            // Update the specific status column for this role
            $sql_update_stage = "
                UPDATE requests 
                SET {$status_column} = ?, 
                    {$status_column}_remark = ?, 
                    {$status_column}_date = NOW()
                WHERE request_id = ?
            ";
            
            if ($stmt_stage = mysqli_prepare($link, $sql_update_stage)) {
                mysqli_stmt_bind_param($stmt_stage, "ssi", $new_status, $remarks, $request_id);
                if (!mysqli_stmt_execute($stmt_stage)) {
                    $success = false;
                }
                mysqli_stmt_close($stmt_stage);
            } else {
                $success = false;
            }

            // If approved, check if this is the final (AFO) approval stage
            if ($success && $new_status === 'Approved' && $role === 'AFO') {
                // If AFO approves, set final_status to Approved and notification_status to Budget Available
                $final_sql = "
                    UPDATE requests 
                    SET final_status = 'Approved', 
                        notification_status = 'Budget Available'
                    WHERE request_id = ?
                ";
                if ($stmt_final = mysqli_prepare($link, $final_sql)) {
                    mysqli_stmt_bind_param($stmt_final, "i", $request_id);
                    if (!mysqli_stmt_execute($stmt_final)) {
                        $success = false;
                    }
                    mysqli_stmt_close($stmt_final);
                } else {
                    $success = false;
                }
            } 
            // If rejected at ANY stage, set final_status to Rejected and notification_status to Rejected
            elseif ($success && $new_status === 'Rejected') {
                $final_sql = "
                    UPDATE requests 
                    SET final_status = 'Rejected', 
                        notification_status = 'Rejected'
                    WHERE request_id = ?
                ";
                if ($stmt_final = mysqli_prepare($link, $final_sql)) {
                    mysqli_stmt_bind_param($stmt_final, "i", $request_id);
                    if (!mysqli_stmt_execute($stmt_final)) {
                        $success = false;
                    }
                    mysqli_stmt_close($stmt_final);
                } else {
                    $success = false;
                }
            }
            // If approved, but not AFO, update notification status for the next signatory
            elseif ($success && $new_status === 'Approved' && $role !== 'AFO') {
                $next_stage_notification = '';
                if ($role === 'Adviser') {
                    $next_stage_notification = 'Awaiting Dean Approval';
                } elseif ($role === 'Dean') {
                    $next_stage_notification = 'Awaiting OSAFA Approval';
                } elseif ($role === 'OSAFA') {
                    $next_stage_notification = 'Awaiting AFO Approval';
                }
                
                if (!empty($next_stage_notification)) {
                     $final_sql = "
                        UPDATE requests 
                        SET notification_status = ?
                        WHERE request_id = ?
                    ";
                    if ($stmt_final = mysqli_prepare($link, $final_sql)) {
                        mysqli_stmt_bind_param($stmt_final, "si", $next_stage_notification, $request_id);
                        if (!mysqli_stmt_execute($stmt_final)) {
                            $success = false;
                        }
                        mysqli_stmt_close($stmt_final);
                    } else {
                        $success = false;
                    }
                }
            }


        } catch (Exception $e) {
            $success = false;
        }

        if ($success) {
            mysqli_commit($link);
            // Redirect back to the list on success
            header("location: admin_request_list.php?success=2");
            exit;
        } else {
            mysqli_rollback($link);
            $decision_err = "Failed to update request status in the database. Please try again. (" . mysqli_error($link) . ")";
        }
    }
}
// Close connection
mysqli_close($link);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Request #<?php echo $request_id; ?> | AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .status-pill { 
            padding: 4px 10px; 
            border-radius: 9999px; 
            font-size: 0.75rem; 
            font-weight: 600; 
            border: 1px solid; 
            display: inline-block;
        }
        .info-box {
            padding: 1.5rem;
            border-radius: 0.75rem;
            background-color: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 2px, 0, 0.1);
        }
    </style>
</head>
<body class="min-h-screen">
    
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold"><?php echo htmlspecialchars($role); ?> Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="admin_dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <a href="admin_request_list.php" class="hover:text-indigo-200 transition duration-150">My Inbox</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b>
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-extrabold text-gray-800">
                Review Request #<?php echo htmlspecialchars($request_id); ?>
            </h2>
            <a href="admin_request_list.php" class="text-indigo-600 hover:text-indigo-800 transition duration-150 font-medium flex items-center">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Back to Inbox
            </a>
        </div>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 mb-8 rounded-xl shadow-lg">
                <p class="font-bold text-xl">Review Status</p>
                <p class="mt-2"><?php echo $error_message; ?></p>
            </div>
        <?php elseif ($request): ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <div class="lg:col-span-2 info-box h-full">
                    <h3 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($request['title']); ?></h3>
                    <p class="text-sm text-gray-500 mb-4">Submitted by **<?php echo htmlspecialchars($request['submitted_by_name']); ?>** from **<?php echo htmlspecialchars($request['org_name']); ?>**</p>
                    
                    <div class="mt-4 pt-4 border-t border-gray-100 flex justify-between items-center">
                        <div class="text-lg font-semibold text-gray-700">Type: <?php echo htmlspecialchars($request['type']); ?></div>
                        <div class="text-2xl font-extrabold text-indigo-700">₱<?php echo number_format($request['amount'], 2); ?></div>
                    </div>
                </div>

                <div class="info-box border-l-4 border-blue-500 bg-blue-50/70">
                    <p class="text-sm font-medium text-gray-500 mb-2">My Current Status (<?php echo $role; ?>)</p>
                    <?php $my_status = htmlspecialchars($request[$status_column]); ?>
                    <div class="text-3xl font-bold text-gray-800">
                        <span class="status-pill <?php echo get_status_class($my_status); ?>">
                            <?php echo $my_status; ?>
                        </span>
                    </div>
                    <?php if ($request[$status_column.'_remark']): ?>
                        <p class="text-xs text-gray-600 mt-2 italic">Remarks: <?php echo htmlspecialchars($request[$status_column.'_remark']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <div class="lg:col-span-2 info-box h-full">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Detailed Justification</h3>
                    <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($request['description']); ?></p>
                </div>
                
                <?php if ($request[$status_column] === 'Pending'): ?>
                <div class="info-box border-l-4 border-green-500 bg-green-50/70">
                    <h3 class="text-xl font-semibold text-green-700 mb-4 border-b pb-2">Make Your Decision</h3>
                    
                    <?php if (!empty($decision_err)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded-lg text-sm" role="alert">
                            <?php echo $decision_err; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="admin_review.php?id=<?php echo $request_id; ?>" method="post" class="space-y-4">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Action:</label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="action" value="Approve" required class="form-radio text-green-600 h-4 w-4">
                                <span class="ml-2 text-gray-700">Approve</span>
                            </label>
                            <label class="inline-flex items-center ml-6">
                                <input type="radio" name="action" value="Reject" class="form-radio text-red-600 h-4 w-4">
                                <span class="ml-2 text-gray-700">Reject</span>
                            </label>
                        </div>
                        
                        <div>
                            <label for="remarks" class="block text-sm font-medium text-gray-700">Remarks (Required for Rejection):</label>
                            <textarea name="remarks" id="remarks" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                        </div>
                        
                        <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md">
                            Submit Decision
                        </button>
                    </form>
                </div>
                <?php else: ?>
                    <div class="info-box border-l-4 border-gray-500 bg-gray-50/70">
                        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Decision Log</h3>
                        <p class="text-gray-600">You have already processed this request with a status of **<?php echo htmlspecialchars($request[$status_column]); ?>**.</p>
                        <p class="text-sm text-gray-500 mt-2">Date: <?php echo $request[$status_column.'_date'] ? date('M d, Y', strtotime($request[$status_column.'_date'])) : 'N/A'; ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="lg:col-span-3 info-box bg-indigo-50 border-indigo-500 border-2">
                    <h3 class="text-xl font-semibold text-indigo-800 mb-4">Supporting Attachments (<?php echo count($files); ?>)</h3>
                    
                    <?php if (!empty($files)): ?>
                        <div class="space-y-3">
                            <?php foreach ($files as $file): ?>
                                <div class="flex items-center justify-between p-3 bg-white rounded-lg border border-indigo-200 shadow-sm">
                                    <p class="text-sm text-gray-700 font-medium truncate">
                                        <?php echo htmlspecialchars($file['original_file_name']); ?>
                                    </p>
                                    <a href="uploads/<?php echo urlencode($file['file_name']); ?>" 
                                       target="_blank" 
                                       download
                                       class="text-indigo-600 hover:text-indigo-800 flex items-center text-sm font-semibold ml-4 flex-shrink-0">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                        Download
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-600 italic">No supporting documents were attached to this request.</p>
                    <?php endif; ?>
                </div>

            </div>

        <?php endif; ?>
    </div>
</body>
</html>