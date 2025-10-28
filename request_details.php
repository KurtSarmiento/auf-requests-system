<?php
// Initialize the session
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Check if the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Get request ID from URL (ensure it's an integer)
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$request = null;
$files = [];
$error_message = "";

// Security check
$is_officer = $_SESSION["role"] === 'Officer';
$user_id = $_SESSION["user_id"];
$org_id = $_SESSION["org_id"];

// ✅ PHP 7.4 compatible: function for status pill
if (!function_exists('get_status_class')) {
    function get_status_class($status) {
        if ($status == 'Approved') return 'bg-green-100 text-green-800 border-green-500';
        elseif ($status == 'Rejected') return 'bg-red-100 text-red-800 border-red-500';
        elseif ($status == 'Awaiting AFO Approval') return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        elseif ($status == 'Budget Available') return 'bg-purple-100 text-purple-800 border-purple-500 font-bold';
        elseif ($status == '-') return 'bg-gray-100 text-gray-800 border-gray-500';
        else return 'bg-blue-100 text-blue-800 border-blue-500';
    }
}

// Fetch request data
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
        $request = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        // Security Check: Ensure the user is allowed to see this
        if (!$request) {
            $error_message = "Request not found.";
        } elseif ($is_officer && $request['user_id'] != $user_id) {
            // If officer, they must be the one who submitted it
             $error_message = "Access Denied. You did not submit this request.";
             $request = null; // Don't show the data
        }

        // Fetch files if request is valid and user has access
        if ($request) {
            $sql_files = "SELECT * FROM files WHERE request_id = ?";
            if ($stmt_files = mysqli_prepare($link, $sql_files)) {
                mysqli_stmt_bind_param($stmt_files, "i", $request_id);
                mysqli_stmt_execute($stmt_files);
                $result_files = mysqli_stmt_get_result($stmt_files);
                while ($row = mysqli_fetch_assoc($result_files)) {
                    $files[] = $row;
                }
                mysqli_stmt_close($stmt_files);
            }
        }
    } else {
        $error_message = "Error fetching request data.";
    }
} else {
    $error_message = "Invalid request ID.";
}

mysqli_close($link);

// Start the page
start_page("Request Details", $_SESSION["role"], $_SESSION["full_name"]);

// --- Define approval chain for display ---
$approval_chain = [
    'Adviser' => 'adviser_status',
    'Dean' => 'dean_status',
    'OSAFA' => 'osafa_status',
    'AFO' => 'afo_status'
];

// Helper for info boxes
function info_box($title, $value, $extra_class = '') {
    echo "<div class='info-box {$extra_class}'>";
    echo "<h4 class='text-sm font-semibold text-gray-500 uppercase tracking-wider'>{$title}</h4>";
    echo "<p class='text-lg font-bold text-gray-800'>" . htmlspecialchars($value) . "</p>";
    echo "</div>";
}
?>

<style>
    .info-box {
        background-color: #f9fafb; /* bg-gray-50 */
        padding: 16px;
        border-radius: 8px; /* rounded-lg */
        border: 1px solid #e5e7eb; /* border-gray-200 */
    }
    .status-pill {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 9999px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid;
    }
</style>

<div class="container mx-auto px-4 py-8">

    <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 rounded-lg shadow-md">
            <p class="font-bold text-xl">Access Denied</p>
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <a href="request_list.php" class="inline-block bg-red-600 text-white px-4 py-2 rounded-lg mt-4 hover:bg-red-700">← Back to My Requests</a>
        </div>
    <?php elseif ($request): ?>
        <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 mb-6">
            <div class="flex flex-wrap justify-between items-center gap-4">
                <div>
                    <span class="text-sm font-semibold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full"><?php echo htmlspecialchars($request['type']); ?> Request</span>
                    <h1 class="text-4xl font-extrabold text-gray-900 mt-2"><?php echo htmlspecialchars($request['title']); ?></h1>
                    <p class="text-lg text-gray-600">
                        Submitted to <span class="font-semibold"><?php echo htmlspecialchars($request['org_name']); ?></span>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-500">Total Amount</p>
                    <p class="text-4xl font-bold text-gray-800">
                        ₱<?php echo number_format($request['amount'], 2); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Request Progress</h3>
                    <ol class="relative border-l border-gray-200">
                        <?php 
                        // ===================================
                        // --- NEW REJECTION LOGIC (START) ---
                        // ===================================
                        $rejection_has_occurred = false;
                        foreach ($approval_chain as $role_name => $status_key):
                            
                            $status = 'Pending';
                            $status_date = null;

                            if ($rejection_has_occurred) {
                                $status = '-';
                            } else {
                                $status = $request[$status_key];
                                $status_date_key = str_replace('_status', '_decision_date', $status_key);
                                $status_date = isset($request[$status_date_key]) ? $request[$status_date_key] : null;
                                
                                if ($status === 'Rejected') {
                                    $rejection_has_occurred = true;
                                }
                            }
                            $status_class = get_status_class($status);
                            ?>
                            <li class="mb-6 ml-6">
                                <span class="absolute flex items-center justify-center w-6 h-6 bg-blue-100 rounded-full -left-3 ring-8 ring-white">
                                    <svg class="w-4 h-4 text-blue-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                </span>
                                <h4 class="flex items-center mb-1 text-md font-semibold text-gray-900">
                                    <?php echo htmlspecialchars($role_name); ?>
                                </h4>
                                <span class="status-pill text-xs <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                                <?php if ($status_date): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo date('M d, Y g:i A', strtotime($status_date)); ?>
                                </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; 
                        // --- NEW REJECTION LOGIC (END) ---
                        ?>
                        
                        <li class="ml-6">
                            <span class="absolute flex items-center justify-center w-6 h-6 bg-purple-100 rounded-full -left-3 ring-8 ring-white">
                                <svg class="w-4 h-4 text-purple-800" fill="currentColor" viewBox="0 0 20 20"><path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V4zm3 1a1 1 0 000 2h.01a1 1 0 100-2H8zm-1 4a1 1 0 100 2h6a1 1 0 100-2H7z"></path></svg>
                            </span>
                            <h4 class="mb-1 text-md font-semibold text-gray-900">Budget Status</h4>
                            <span class="status-pill text-xs <?php echo get_status_class($request['final_status']); ?>">
                                <?php echo htmlspecialchars($request['notification_status']); // This correctly shows "Rejected by..." ?>
                            </span>
                        </li>
                    </ol>
                </div>
                
                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Signatory Remarks</h3>
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

            <div class="lg:col-span-2 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php info_box('Date Submitted', date('M d, Y h:i A', strtotime($request['date_submitted']))); ?>
                    <?php info_box('Submitted By', $request['full_name']); ?>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Description / Purpose</h3>
                    <div class="prose max-w-none text-gray-700">
                        <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Supporting Attachments (<?php echo count($files); ?>)</h3>
                    <?php if (!empty($files)): ?>
                        <div class="space-y-3">
                            <?php foreach ($files as $file): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-200">
                                    <p class="text-sm text-gray-700 font-medium truncate"><?php echo htmlspecialchars($file['original_file_name']); ?></p>
                                    <a href="download_file.php?fid=<?php echo (int)$file['file_id']; ?>" class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold" target="_blank" rel="noopener noreferrer">Download</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-50 text-gray-700 p-4 rounded-lg border border-gray-200">
                            <p class="text-sm">No supporting documents were attached to this request.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    <?php endif; ?>
</div>

<?php
end_page();
?>