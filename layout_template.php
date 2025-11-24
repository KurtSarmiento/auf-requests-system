<?php
// Function to start the HTML structure, includes header and navigation.
function start_page($title, $role, $full_name, $review_queue_count = 0) {
    $current_page = basename($_SERVER["PHP_SELF"] ?? '');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> | AUFthorize</title>
    <link rel="stylesheet" href="css/styles.css"> 
    <script src="https://cdn.tailwindcss.com"></script>
    </head>
<body class="app-shell">
    
    <nav class="nav-glass">
        <div class="nav-inner">
            <div class="nav-brand">
                <div class="nav-logo">
                    <img src="pics/aufthorize.png" alt="AUFthorize Logo" class="h-8 w-auto">
                </div>
                <div>
                    <strong>AUFthorize</strong>
                    <span>Requests OS</span>
                </div>
            </div>
            <div class="nav-links">
                <?php if ($role === 'Officer'): ?>
                    <a href="officer_dashboard.php" class="nav-link <?php echo ($current_page === 'officer_dashboard.php') ? 'is-active' : ''; ?>">Dashboard</a>
                    <a href="request_select.php" class="nav-link <?php echo ($current_page === 'request_select.php') ? 'is-active' : ''; ?>">New Request</a>
                    <a href="request_list.php" class="nav-link <?php echo ($current_page === 'request_list.php') ? 'is-active' : ''; ?>">My Submissions</a>
                <?php else: ?>
                    <a href="admin_dashboard.php" class="nav-link <?php echo ($current_page === 'admin_dashboard.php') ? 'is-active' : ''; ?>">Admin Dashboard</a>
                    <a href="admin_request_list.php" class="nav-link nav-link--notif <?php echo ($current_page === 'admin_request_list.php') ? 'is-active' : ''; ?>">
                        Review Queue
                        <?php if ($review_queue_count > 0): ?>
                            <span class="notification-bubble"><?php echo $review_queue_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="admin_history_list.php" class="nav-link <?php echo ($current_page === 'admin_history_list.php') ? 'is-active' : ''; ?>">History</a>
                <?php endif; ?>
            </div>

            <div class="nav-user">
                <div class="text-center">
                    <span>Signed in</span>
                    <div><strong><?php echo htmlspecialchars($full_name); ?></strong></div>
                </div>
                <div class="nav-role"><?php echo htmlspecialchars($role); ?></div>
                <a href="logout.php" class="nav-logout">
                    Logout
                </a>
            </div>
        </div>
    </nav>

    <main class="app-main">
        <?php
}

// Function to end the HTML structure.
function end_page() {
    ?>
    </main>
</body>
</html>
    <?php
}
?>
