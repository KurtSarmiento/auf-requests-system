<?php
// Initialize the session
session_start();

// Check if the user is logged in, if not then redirect them to the login page
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | AUF Request System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
    </style>
</head>
<body class="min-h-screen">

    <div class="bg-indigo-800 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Request System</h1>
        <div class="flex items-center space-x-4">
            <span class="text-sm">Welcome, <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b> (<?php echo htmlspecialchars($_SESSION["role"]); ?>)</span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-8">
        <h2 class="text-2xl font-semibold text-gray-800 mb-6">User Dashboard</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            
            <!-- User Info Card -->
            <div class="bg-white p-6 rounded-xl shadow-lg border-t-4 border-indigo-500">
                <h3 class="text-lg font-bold text-gray-700 mb-3">Your Details</h3>
                <p><strong>Username:</strong> <?php echo htmlspecialchars($_SESSION["username"]); ?></p>
                <p><strong>Org ID:</strong> <?php echo htmlspecialchars($_SESSION["org_id"]); ?></p>
                <p><strong>Role:</strong> <span class="font-bold text-indigo-600"><?php echo htmlspecialchars($_SESSION["role"]); ?></span></p>
            </div>

            <!-- Main Feature Placeholder -->
            <div class="bg-white p-6 rounded-xl shadow-lg border-t-4 border-green-500">
                <h3 class="text-lg font-bold text-gray-700 mb-3">Submit New Request</h3>
                <p class="text-gray-600 mb-4">Start by creating a new budget request, venue reservation, or liquidation report.</p>
                <a href="request_create.php" class="inline-block bg-green-500 hover:bg-green-600 text-white font-medium px-4 py-2 rounded-lg transition duration-150">
                    Create Request
                </a>
            </div>

            <!-- View Requests Placeholder -->
            <div class="bg-white p-6 rounded-xl shadow-lg border-t-4 border-yellow-500">
                <h3 class="text-lg font-bold text-gray-700 mb-3">View My Requests</h3>
                <p class="text-gray-600 mb-4">Check the status of your pending, approved, or rejected submissions.</p>
                <a href="request_list.php" class="inline-block bg-yellow-500 hover:bg-yellow-600 text-white font-medium px-4 py-2 rounded-lg transition duration-150">
                    View List
                </a>
            </div>

        </div>
    </div>
</body>
</html>
