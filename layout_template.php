<?php
// Function to start the HTML structure, includes header and navigation.
function start_page($title, $role, $full_name) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> | AUF System</title>
    <link rel="stylesheet" href="css/styles.css"> 
    <script src="https://cdn.tailwindcss.com"></script>
    </head>
<body class="min-h-screen">
    
    <nav class="header-bg text-white p-4 shadow-xl">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-extrabold tracking-tight">AUF Request System</h1>
            <div class="flex items-center space-x-6">
                <?php if ($role === 'Officer'): // Officer Role ?>
    <a href="officer_dashboard.php" class="hover:text-blue-300 transition duration-150 font-medium">Dashboard</a>
    <a href="request_select.php" class="hover:text-blue-300 transition duration-150 font-medium">New Request</a>
    <a href="request_list.php" class="hover:text-blue-300 transition duration-150 font-medium">View Submissions</a>
<?php else: // Signatory Roles ?>
                    <a href="admin_dashboard.php" class="hover:text-blue-300 transition duration-150 font-medium">Admin Dashboard</a>
                    <a href="admin_request_list.php" class="hover:text-blue-300 transition duration-150 font-medium">Review Queue</a>
                <?php endif; ?>

                <div class="flex items-center space-x-3 text-sm">
                    <span class="font-light">
                        Hello, <b><?php echo htmlspecialchars($full_name); ?></b>
                    </span>
                    <a href="logout.php" class="bg-teal-500 hover:bg-teal-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition duration-150 shadow-md">
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container mx-auto p-4 sm:p-8">
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