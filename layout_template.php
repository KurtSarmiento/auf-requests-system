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
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Off-white background as requested */
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f8f9fa; /* Off-white / light gray */
        }
        /* Custom blue shades for consistency */
        .header-bg { background-color: #1e3a8a; } /* Dark Blue for Header */
        .text-primary { color: #2563eb; } /* Indigo/Blue for primary actions */
        .border-primary { border-color: #3b82f6; }
        .bg-card { background-color: #ffffff; }
    </style>
</head>
<body class="min-h-screen">
    
    <nav class="header-bg text-white p-4 shadow-xl">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-extrabold tracking-tight">AUF Request System</h1>
            <div class="flex items-center space-x-6">
                <?php if ($role === 'Officer'): ?>
                    <a href="officer_dashboard.php" class="hover:text-blue-300 transition duration-150 font-medium">Dashboard</a>
                    <a href="request_form.php" class="hover:text-blue-300 transition duration-150 font-medium">New Request</a>
                    <a href="request_list.php" class="hover:text-blue-300 transition duration-150 font-medium">My Submissions</a>
                <?php else: // Signatory Roles ?>
                    <a href="admin_dashboard.php" class="hover:text-blue-300 transition duration-150 font-medium">Admin Dashboard</a>
                    <a href="admin_request_list.php" class="hover:text-blue-300 transition duration-150 font-medium">Review Queue</a>
                <?php endif; ?>

                <div class="flex items-center space-x-3 text-sm">
                    <span class="font-light">
                        Hello, <b><?php echo htmlspecialchars($full_name); ?></b> (<?php echo htmlspecialchars($role); ?>)
                    </span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-xs font-semibold transition duration-150 shadow-md">
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