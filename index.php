<?php
require_once "db_config.php";

$status_title = "Database Connection";
$status_message = "";
$status_details = "";
$status_ok = false;

if ($link) {
    $status_ok = true;
    $status_message = "SUCCESS: Connected to " . DB_NAME;

    $sql = "SELECT COUNT(*) AS total_requests FROM requests";
    if ($result = mysqli_query($link, $sql)) {
        $row = mysqli_fetch_assoc($result);
        $status_details = "Total requests in the database: " . number_format((int)$row['total_requests']);
        mysqli_free_result($result);
    } else {
        $status_details = "Query error: " . mysqli_error($link);
    }

    mysqli_close($link);
} else {
    $status_message = "FAILURE: Database link not established.";
    $status_details = "Please check db_config.php credentials.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AUF Request System</title>
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="aurora-bg min-h-screen flex items-center justify-center p-6">
    <div class="glass-panel max-w-2xl w-full p-10 rounded-3xl text-center space-y-4">
        <p class="pill-muted mx-auto w-max">AUF Request System</p>
        <h1 class="text-3xl font-extrabold"><?php echo htmlspecialchars($status_title); ?></h1>
        <p class="text-lg font-semibold <?php echo $status_ok ? 'text-emerald-600' : 'text-rose-600'; ?>">
            <?php echo htmlspecialchars($status_message); ?>
        </p>
        <p class="text-gray-600">
            <?php echo htmlspecialchars($status_details); ?>
        </p>
        <div class="text-sm text-gray-500">
            Continue to <a href="login.php" class="text-amber-600 font-semibold hover:text-amber-500">login</a> to access the system.
        </div>
    </div>
</body>
</html>
