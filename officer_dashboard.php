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

<div class="space-y-8">
    <section class="page-hero">
        <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
            <div>
                <span class="hero-pill">Officer Workspace</span>
                <h2 class="hero-title">
                    Welcome, <?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?>.
                </h2>
                <p class="hero-subtext">
                    Launch new AUFthorize requests or monitor every venue, reimbursement, and liquidation
                    filing from a clean, glassy control board.
                </p>
                <div class="hero-actions">
                    <a href="request_select.php" class="btn-primary">Create Request</a>
                    <a href="request_list.php" class="detail-link">Track submissions</a>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="stat-grid space">
            <div class="stat-card stat-card--accent">
                <p class="stat-card__label">Total submissions</p>
                <p class="stat-card__value"><?php echo $request_count; ?></p>
                <p class="stat-card__meta">All requests filed under your account</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__label">Approved</p>
                <p class="stat-card__value"><?php echo $approved_count; ?></p>
                <p class="stat-card__meta">Ready for execution/liquidation</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__label">In review</p>
                <p class="stat-card__value"><?php echo $pending_count; ?></p>
                <p class="stat-card__meta">Currently in routing across offices</p>
            </div>
            <div class="stat-card">
                <p class="stat-card__label">Returned</p>
                <p class="stat-card__value"><?php echo $rejected_count; ?></p>
                <p class="stat-card__meta">Requires revision or follow-up</p>
            </div>
        </div>
    </section>
</div>

<?php
// Close the database connection
mysqli_close($link);
// End the page using the template function
end_page(); 
?>

