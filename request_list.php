<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an Officer
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer'){
    header("location: login.php");
    exit;
}
 
// Include config file and layout template
require_once "db_config.php";
require_once "layout_template.php"; // NEW: Include the template file

// Call the start_page function to output the header and navigation
start_page("My Submitted Funding Requests");

// Prepare to fetch data
$user_id = $_SESSION["user_id"];
$request_list = [];
$error_message = "";

// Helper function to render status badges
function render_status_badge($status) {
    $class = "bg-gray-200 text-gray-700"; // Default (Pending)
    switch ($status) {
        case 'Approved':
            $class = "bg-green-100 text-green-700 border border-green-300";
            break;
        case 'Rejected':
            $class = "bg-red-100 text-red-700 border border-red-300";
            break;
        case 'Pending':
        default:
            $class = "bg-yellow-100 text-yellow-700 border border-yellow-300";
            break;
    }
    return '<span class="status-badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

// SQL to select all requests submitted by the logged-in officer
// FIX: Changed JOIN to connect requests -> users -> organizations because requests table does not have org_id.
$sql = "SELECT 
            r.request_id, r.title AS request_title, r.type AS request_type, r.amount AS amount_requested, 
            r.notification_status, r.date_submitted,
            r.adviser_status, r.dean_status, r.osafa_status, r.afo_status, 
            o.org_name
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON u.org_id = o.org_id
        WHERE r.user_id = ? 
        ORDER BY r.date_submitted DESC";

if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $request_list[] = $row;
        }
        mysqli_free_result($result);
    } else{
        $error_message = "ERROR: Could not execute query. " . mysqli_error($link);
    }

    mysqli_stmt_close($stmt);
} else {
    $error_message = "ERROR: Could not prepare statement. " . mysqli_error($link);
}

// Display error if present
if (!empty($error_message)) {
    echo '<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert"><p>' . htmlspecialchars($error_message) . '</p></div>';
}
?>

<div class="bg-white shadow-xl rounded-xl overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">ID</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-3/12">Request Title / Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-4/12">Approval Flow</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-2/12">Final Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">Submitted</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            <?php if (empty($request_list)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                        You have not submitted any funding requests yet.
                        <a href="request_create.php" class="text-indigo-600 hover:text-indigo-800 font-medium">Submit one now.</a>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($request_list as $request): ?>
                    <tr class="hover:bg-gray-50 transition duration-100">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?php echo htmlspecialchars($request['request_id']); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <a href="request_details.php?id=<?php echo $request['request_id']; ?>" class="text-indigo-600 hover:text-indigo-800 font-semibold truncate block">
                                <?php echo htmlspecialchars($request['request_title']); ?>
                            </a>
                            <span class="text-xs text-gray-500 italic"><?php echo htmlspecialchars($request['request_type']); ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            ₱<?php echo number_format($request['amount_requested'], 2); ?>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700 space-y-1">
                            <div class="flex items-center space-x-2">
                                <span class="text-xs w-16 text-gray-500">Adviser:</span>
                                <?php echo render_status_badge($request['adviser_status']); ?>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="text-xs w-16 text-gray-500">Dean:</span>
                                <?php echo render_status_badge($request['dean_status']); ?>
                            </div>
                            <!-- Show the final two steps only if the adviser/dean steps are completed to keep it clean -->
                            <?php if ($request['adviser_status'] === 'Approved' && $request['dean_status'] === 'Approved'): ?>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs w-16 text-gray-500">OSAFA:</span>
                                    <?php echo render_status_badge($request['osafa_status']); ?>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs w-16 text-gray-500">AFO:</span>
                                    <?php echo render_status_badge($request['afo_status']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php 
                                    if ($request['notification_status'] === 'Final Approved') {
                                        echo 'bg-green-100 text-green-800 border border-green-400';
                                    } elseif ($request['notification_status'] === 'Final Rejected') {
                                        echo 'bg-red-100 text-red-800 border border-red-400';
                                    } elseif (strpos($request['notification_status'], 'Awaiting') !== false) {
                                        echo 'bg-yellow-100 text-yellow-800 border border-yellow-400';
                                    } else {
                                        echo 'bg-blue-100 text-blue-800 border border-blue-400';
                                    }
                                ?>">
                                <?php echo htmlspecialchars($request['notification_status']); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('M d, Y', strtotime($request['date_submitted'])); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
// Call the end_page function to output the footer and closing tags
end_page();
// Close connection
mysqli_close($link);
?>
