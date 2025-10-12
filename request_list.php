<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Check if the user is logged in, if not then redirect to login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// --- DEFINE SESSION VARIABLES FOR TEMPLATE ---
$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];

// Check if the user is an Officer (this list is specifically for Officers)
if ($role !== 'Officer') {
    // If not an officer, redirect them to their appropriate dashboard
    header("location: dashboard.php");
    exit;
}

// Start the page using the template function, passing all 3 required arguments
start_page("My Submitted Funding Requests", $role, $full_name);

// Initialize array to hold requests
$requests = [];

// Prepare the SQL query
// This query joins requests (r), users (u), and organizations (o) to get the org name
$sql = "SELECT 
            r.request_id, r.title, r.amount, r.date_submitted,
            r.adviser_status, r.dean_status, r.osafa_status, r.afo_status, 
            r.final_status, r.notification_status, o.org_name
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON u.org_id = o.org_id
        WHERE r.user_id = ? 
        ORDER BY r.date_submitted DESC";

if ($stmt = mysqli_prepare($link, $sql)) {
    // Bind parameters
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    
    // Attempt to execute the prepared statement
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
    } else {
        echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-md'>ERROR: Could not execute query. " . mysqli_error($link) . "</div>";
    }

    mysqli_stmt_close($stmt);
}

mysqli_close($link);

?>

<h2 class="text-3xl font-bold text-gray-800 mb-6 border-b pb-2">My Submitted Funding Requests</h2>
<p class="text-lg text-gray-600 mb-8">Track the progress of your submissions through the multi-stage approval process.</p>

<?php if (empty($requests)): ?>
    <div class="bg-blue-50 border-l-4 border-blue-400 text-blue-700 p-4 rounded-lg shadow-md max-w-2xl mx-auto" role="alert">
        <p class="font-bold">No Requests Found</p>
        <p>You haven't submitted any funding requests yet. Click <a href="request_form.php" class="font-semibold underline hover:text-blue-800">here to start a new one</a>.</p>
    </div>
<?php else: ?>

    <div class="bg-white p-4 rounded-xl shadow-2xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Final Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Step</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($requests as $request): ?>
                <tr class="hover:bg-gray-50 transition duration-100">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        <?php echo htmlspecialchars($request['title']); ?>
                        <div class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($request['org_name']); ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        P<?php echo number_format($request['amount'], 2); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo date('M d, Y', strtotime($request['date_submitted'])); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php
                            $status_class = 'bg-gray-100 text-gray-800';
                            $final_status = htmlspecialchars($request['final_status']);
                            if ($final_status === 'Approved') {
                                $status_class = 'bg-green-100 text-green-800 font-semibold';
                            } elseif ($final_status === 'Rejected') {
                                $status_class = 'bg-red-100 text-red-800 font-semibold';
                            } elseif ($final_status === 'Pending') {
                                $status_class = 'bg-yellow-100 text-yellow-800';
                            }
                        ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                            <?php echo $final_status; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                        <?php echo htmlspecialchars($request['notification_status']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-center">
                        <a href="request_details.php?id=<?php echo $request['request_id']; ?>" 
                           class="text-blue-600 hover:text-blue-900 transition duration-150 font-semibold">
                            View Details
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?php
// End the page using the template function
end_page();
?>
