<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Check if the user is logged in and is an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];
$org_id = $_SESSION["org_id"];

$request_count = 0;
$pending_count = 0;
$approved_count = 0;
$organization_name = "N/A";

// --- Fetch Organization Name ---
$org_sql = "SELECT org_name FROM organizations WHERE org_id = ?";
if ($stmt = mysqli_prepare($link, $org_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $org_id);
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_bind_result($stmt, $organization_name);
        mysqli_stmt_fetch($stmt);
    }
    mysqli_stmt_close($stmt);
}

// --- Fetch Request Counts ---
$count_sql = "SELECT 
    COUNT(request_id) AS total,
    SUM(CASE WHEN notification_status = 'Awaiting Adviser Review' OR notification_status = 'Awaiting Dean Review' OR notification_status = 'Awaiting OSAFA Review' OR notification_status = 'Awaiting AFO Review' THEN 1 ELSE 0 END) AS pending,
    SUM(CASE WHEN notification_status = 'Final Approved' THEN 1 ELSE 0 END) AS approved
FROM requests 
WHERE user_id = ?";

if ($stmt = mysqli_prepare($link, $count_sql)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    if (mysqli_stmt_execute($stmt)) {
        // Renamed variable to match SQL aliases for clarity
        mysqli_stmt_bind_result($stmt, $request_count, $pending_count, $approved_count);
        mysqli_stmt_fetch($stmt);
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($link);

// Start the page using the template function
start_page("Officer Dashboard", $role, $full_name);

?>

<h2 class="text-4xl font-extrabold text-gray-800 mb-2">Welcome, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>!</h2>
<p class="text-xl text-gray-600 mb-8">
    Dashboard for the <span class="font-semibold text-primary"><?php echo htmlspecialchars($organization_name); ?></span> Officer.
</p>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
    <a href="request_create.php" class="bg-blue-600 hover:bg-blue-700 text-white p-6 rounded-xl shadow-lg transition duration-300 transform hover:scale-[1.02]">
        <h3 class="text-2xl font-bold mb-1">Submit New Request</h3>
        <p class="text-sm opacity-90">Start the multi-stage approval process.</p>
    </a>
    <a href="request_list.php" class="bg-indigo-600 hover:bg-indigo-700 text-white p-6 rounded-xl shadow-lg transition duration-300 transform hover:scale-[1.02]">
        <h3 class="text-2xl font-bold mb-1">View My Submissions</h3>
        <p class="text-sm opacity-90">Track statuses and progress.</p>
    </a>
    <div class="bg-sky-600 text-white p-6 rounded-xl shadow-lg">
        <h3 class="text-2xl font-bold mb-1">Organization:</h3>
        <p class="text-lg font-semibold"><?php echo htmlspecialchars($organization_name); ?></p>
        <p class="text-sm opacity-90">Role: <?php echo htmlspecialchars($role); ?></p>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
    
    <div class="bg-card p-6 rounded-xl shadow-xl border border-gray-200">
        <p class="text-sm font-medium text-gray-500">Total Requests</p>
        <p class="text-4xl font-extrabold text-gray-900 mt-1"><?php echo $request_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">All-time submissions</p>
    </div>

    <div class="bg-card p-6 rounded-xl shadow-xl border border-gray-200">
        <p class="text-sm font-medium text-gray-500">Final Approved</p>
        <p class="text-4xl font-extrabold text-blue-900 mt-1"><?php echo $approved_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Ready for execution</p>
    </div>

    <div class="bg-card p-6 rounded-xl shadow-xl border border-gray-200">
        <p class="text-sm font-medium text-gray-500">Awaiting Approval</p>
        <p class="text-4xl font-extrabold text-navyblue-900 mt-1"><?php echo $pending_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Currently in the pipeline</p>
    </div>
    
    <div class="bg-card p-6 rounded-xl shadow-xl border border-gray-200">
        <p class="text-sm font-medium text-gray-500">Other Closed Requests</p>
        <p class="text-4xl font-extrabold text-gray-900 mt-1"><?php echo $request_count - $pending_count - $approved_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Completed or Rejected</p>
    </div>

</div>


<?php
// End the page using the template function
end_page();
?>