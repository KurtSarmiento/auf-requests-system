<?php
// layout_template.php
// This file contains the reusable HTML header, navigation, and footers.
// It relies on $_SESSION variables (like role, full_name) to be set.

// Function to start the page content, injecting the title
function start_page($page_title = "Dashboard") {
    $role = isset($_SESSION["role"]) ? $_SESSION["role"] : 'Guest';
    $full_name = isset($_SESSION["full_name"]) ? htmlspecialchars($_SESSION["full_name"]) : 'User';
    
    // Define navigation links based on role
    $nav_links = [];
    if ($role === 'Officer') {
        $nav_links = [
            ['href' => 'officer_dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'request_create.php', 'label' => 'New Request'],
            ['href' => 'request_list.php', 'label' => 'My Requests']
        ];
    } else { // All Admin Roles (Adviser, Dean, etc.)
        $nav_links = [
            ['href' => 'admin_dashboard.php', 'label' => 'Dashboard'],
            ['href' => 'admin_request_list.php', 'label' => 'Review Queue']
        ];
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        /* Style for the step status circles */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Header -->
    <header class="bg-indigo-900 text-white p-4 shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">AUF System | <?php echo htmlspecialchars($role); ?> Panel</h1>
            <nav class="flex items-center space-x-4">
                <?php foreach ($nav_links as $link): ?>
                    <a href="<?php echo $link['href']; ?>" class="px-3 py-1 rounded-lg hover:bg-indigo-700 transition duration-150 font-medium">
                        <?php echo $link['label']; ?>
                    </a>
                <?php endforeach; ?>
                
                <div class="text-sm font-light flex items-center bg-indigo-800 px-3 py-1 rounded-full space-x-2">
                    <span>
                        <?php echo $full_name; ?>
                    </span>
                    <span class="text-xs text-indigo-300">
                        (<?php echo htmlspecialchars($role); ?>)
                    </span>
                </div>
                <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
            </nav>
        </div>
    </header>

    <!-- Main Content Wrapper -->
    <main class="container mx-auto p-4 sm:p-8">
        <h2 class="text-3xl font-extrabold text-gray-900 mb-6"><?php echo htmlspecialchars($page_title); ?></h2>
        <!-- Page content will be inserted here -->

<?php
} // End of start_page function

// Function to end the page content
function end_page() {
?>
    </main>
    <!-- Footer -->
    <footer class="mt-10 p-4 text-center text-gray-500 text-sm border-t border-gray-200">
        AUF Funding Request System | &copy; 2024
    </footer>
</body>
</html>
<?php
} // End of end_page function
?>
