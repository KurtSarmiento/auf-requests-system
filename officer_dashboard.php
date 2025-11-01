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
$rejected_count = 0;
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

// --- Fetch Request Counts (Funding + Venue, Per Officer) ---
$sql_counts = "
    SELECT 
        COUNT(*) AS total_requests,
        SUM(CASE WHEN final_status IN ('Budget Available', 'Approved') THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN final_status IN ('Pending', 'Budget Processing') THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN final_status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM (
        SELECT final_status FROM requests WHERE user_id = ?
        UNION ALL
        SELECT final_status FROM venue_requests WHERE user_id = ?
    ) AS combined
";

if ($stmt_counts = mysqli_prepare($link, $sql_counts)) {
    mysqli_stmt_bind_param($stmt_counts, "ii", $user_id, $user_id);
    if (mysqli_stmt_execute($stmt_counts)) {
        $result = mysqli_stmt_get_result($stmt_counts);
        if ($row = mysqli_fetch_assoc($result)) {
            $request_count  = (int) ($row['total_requests'] ?? 0);
            $approved_count = (int) ($row['approved_count'] ?? 0);
            $pending_count  = (int) ($row['pending_count'] ?? 0);
            $rejected_count = (int) ($row['rejected_count'] ?? 0);
        }
    }
    mysqli_stmt_close($stmt_counts);
}

// Start the page using the template function
start_page("Officer Dashboard", $role, $full_name);
?>

<h2 class="text-5xl font-extrabold text-gray-900 mb-2">
    Welcome, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>!
</h2>
<p class="text-xl text-gray-600 mb-10">
    Dashboard for the <span class="font-bold text-blue-700"><?php echo htmlspecialchars($organization_name); ?></span> Officer.
</p>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-12">
    <a href="request_select.php" class="bg-blue-600 hover:bg-blue-700 text-white p-8 rounded-xl shadow-2xl transition duration-300 transform hover:scale-[1.03] flex flex-col justify-center">
        <h3 class="text-3xl font-bold mb-1">Submit New Request</h3>
        <p class="text-sm opacity-90 font-light">Choose from Budget, Liquidation, Reimbursement, or Venue forms.</p>
    </a>
    <a href="request_list.php" class="bg-indigo-600 hover:bg-indigo-700 text-white p-8 rounded-xl shadow-2xl transition duration-300 transform hover:scale-[1.03] flex flex-col justify-center">
        <h3 class="text-3xl font-bold mb-1">View My Submissions</h3>
        <p class="text-sm opacity-90 font-light">Track statuses and progress of all requests.</p>
    </a>
    <div class="bg-sky-600 text-white p-8 rounded-xl shadow-xl flex flex-col justify-center">
        <h3 class="text-xl font-semibold mb-1 opacity-80">Your Organization:</h3>
        <p class="text-3xl font-extrabold"><?php echo htmlspecialchars($organization_name); ?></p>
        <p class="text-sm opacity-90 mt-1">Role: <?php echo htmlspecialchars($role); ?></p>
    </div>
</div>

<h3 class="text-2xl font-semibold text-gray-800 mb-4">Request Summary</h3>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">

    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-gray-100 flex flex-col items-start">
        <p class="text-sm font-semibold text-gray-600 uppercase tracking-wider">Total Submissions</p>
        <p class="text-5xl font-extrabold text-gray-700 mt-2"><?php echo $request_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">All-time requests submitted</p>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-blue-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-blue-600 uppercase tracking-wider">Final Approved</p>
        <p class="text-5xl font-extrabold text-blue-700 mt-2"><?php echo $approved_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Ready for execution/liquidation</p>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-teal-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-teal-600 uppercase tracking-wider">Awaiting Approval</p>
        <p class="text-5xl font-extrabold text-teal-700 mt-2"><?php echo $pending_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Currently in the pipeline</p>
    </div>
    
    <div class="bg-white p-6 rounded-xl shadow-lg border-2 border-red-400 flex flex-col items-start">
        <p class="text-sm font-semibold text-red-600 uppercase tracking-wider">Total Rejected</p>
        <p class="text-5xl font-extrabold text-red-700 mt-2"><?php echo $rejected_count; ?></p>
        <p class="text-xs text-gray-500 mt-2">Requires review and resubmission</p>
    </div>
</div>

<?php 
// Close the database connection
mysqli_close($link);
// End the page using the template function
end_page(); 
?>
