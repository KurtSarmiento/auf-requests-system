<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is NOT an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] === 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

$current_role = $_SESSION["role"];
$request = null;
$error_message = $success_message = "";
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Determine the SQL column name corresponding to the current user's role
$role_column = match($current_role) {
    'Adviser' => 'adviser_status',
    'Dean' => 'dean_status',
    'OSAFA' => 'osafa_status',
    'AFO' => 'afo_status',
    default => null,
};

if (!$role_column) {
    die("Error: Unrecognized administrative role.");
}

// --- 1. Handle Form Submission (Decision) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $request_id > 0) {
    $decision = trim($_POST['decision']); // 'Approved' or 'Rejected'
    $comment = trim($_POST['comment']);
    $valid_decisions = ['Approved', 'Rejected'];

    if (!in_array($decision, $valid_decisions)) {
        $error_message = "Invalid decision submitted.";
    } else {
        // Start building the update query
        $update_sql = "UPDATE requests SET $role_column = ?, date_updated = NOW()";
        $params = [$decision];
        $types = "s";
        
        // If rejected, set final_status immediately to Rejected
        if ($decision === 'Rejected') {
            $update_sql .= ", final_status = 'Rejected', notification_status = 'Rejected'";
        } 
        
        // Special logic for AFO approval (last stage)
        if ($current_role === 'AFO' && $decision === 'Approved') {
            $update_sql .= ", final_status = 'Approved', notification_status = 'Budget Available'";
        }

        // Add the request ID to the query parameters
        $update_sql .= " WHERE request_id = ?";
        $params[] = $request_id;
        $types .= "i";

        if ($stmt = mysqli_prepare($link, $update_sql)) {
            // Bind parameters dynamically
            $bind_params = [];
            $bind_params[] = &$types;
            foreach ($params as $key => &$value) {
                $bind_params[] = &$value;
            }
            call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $bind_params));

            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Request #$request_id successfully marked as **$decision** by the $current_role.";
                // Clear POST data to prevent resubmission on refresh
                header("location: admin_request_list.php?success=1");
                exit;
            } else {
                $error_message = "Error updating database: " . mysqli_error($link);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Error preparing statement: " . mysqli_error($link);
        }
    }
}

// --- 2. Fetch Request Details ---
if ($request_id > 0) {
    // Select all details, including current statuses
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

    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $request_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result) == 1) {
                $request = mysqli_fetch_assoc($result);
            } else {
                $error_message = "Request not found.";
            }
        } else {
            $error_message = "Error fetching request: " . mysqli_error($link);
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $error_message = "No request ID provided.";
}

// Close connection before HTML output
mysqli_close($link);

// Utility function (copied from list page for styling consistency)
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
            return 'bg-green-600 text-white font-bold';
        case 'Rejected':
            return 'bg-red-600 text-white font-bold';
        case 'Budget Available':
            return 'bg-purple-600 text-white font-bold';
        case 'Pending':
        case 'Awaiting AFO Approval':
        default:
            return 'bg-blue-600 text-white font-bold';
    }
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
    
    <!-- Navigation Bar -->
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

        <?php if ($request): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Column: Request Details -->
            <div class="lg:col-span-2 bg-white p-6 sm:p-8 rounded-xl shadow-2xl">
                <h2 class="text-3xl font-extrabold text-gray-900 border-b pb-4 mb-4">
                    Request #<?php echo htmlspecialchars($request['request_id']); ?>: <?php echo htmlspecialchars($request['title']); ?>
                </h2>

                <div class="space-y-6">
                    <!-- Basic Info -->
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

                    <!-- Description -->
                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-2">Detailed Description</p>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-gray-700 whitespace-pre-wrap">
                            <?php echo htmlspecialchars($request['description']); ?>
                        </div>
                    </div>

                    <!-- Placeholder for File Attachments (Future Step) -->
                    <div class="border-t pt-4">
                        <h3 class="text-xl font-semibold text-gray-800 mb-3">Supporting Documents (Files)</h3>
                        <div class="bg-yellow-50 text-yellow-800 p-4 rounded-lg">
                            <p class="text-sm">File upload functionality is not yet implemented. This area will show attached documents (e.g., proposals, receipts) in the next phase.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Status and Decision -->
            <div class="lg:col-span-1 space-y-8">
                <!-- Current Status Block -->
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

                <!-- Decision Form Block -->
                <div class="bg-white p-6 rounded-xl shadow-2xl border-2 border-indigo-200">
                    <h3 class="text-2xl font-bold text-indigo-700 mb-4">Your Decision (<?php echo htmlspecialchars($current_role); ?>)</h3>
                    
                    <?php 
                    $current_status = $request[$role_column];
                    
                    if ($current_status === 'Approved' || $current_status === 'Rejected'): 
                    ?>
                        <div class="bg-gray-100 p-4 rounded-lg text-lg font-semibold text-gray-700">
                            Decision already recorded as: 
                            <span class="status-pill <?php echo get_status_class($current_status); ?> ml-2">
                                <?php echo htmlspecialchars($current_status); ?>
                            </span>
                        </div>
                        <p class="mt-4 text-sm text-gray-500">To change the decision, you would need direct database access or a specialized permission level (not implemented).</p>
                    
                    <?php else: ?>

                        <form method="POST" action="admin_review.php?id=<?php echo htmlspecialchars($request_id); ?>" class="space-y-4">
                            <!-- Hidden input for request ID -->
                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request_id); ?>">

                            <!-- Decision Radios -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Select Action:</label>
                                <div class="flex space-x-4">
                                    <label class="flex items-center cursor-pointer bg-green-50 hover:bg-green-100 p-3 rounded-lg border-2 border-green-300">
                                        <input type="radio" name="decision" value="Approved" required class="form-radio h-5 w-5 text-green-600">
                                        <span class="ml-2 font-semibold text-green-700">Approve</span>
                                    </label>
                                    <label class="flex items-center cursor-pointer bg-red-50 hover:bg-red-100 p-3 rounded-lg border-2 border-red-300">
                                        <input type="radio" name="decision" value="Rejected" required class="form-radio h-5 w-5 text-red-600">
                                        <span class="ml-2 font-semibold text-red-700">Reject</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Comment/Note Field (Optional) -->
                            <div>
                                <label for="comment" class="block text-sm font-medium text-gray-700">Comments/Reason (Optional):</label>
                                <textarea id="comment" name="comment" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 p-2"></textarea>
                            </div>

                            <!-- Submit Button -->
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
