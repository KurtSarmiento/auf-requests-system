<?php
// Initialize the session and include template/config
session_start();
require_once "db_config.php";
require_once "layout_template.php";

// Check if the user is logged in and is an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

$full_name = $_SESSION["full_name"];
$role = $_SESSION["role"];

// Admin roles — update here to give admin privileges to additional roles.
// Use normalized comparisons to avoid case/whitespace issues.
$admin_roles = [
    'admin',
    'administrator',
    'superadmin',
    'admin services',
    'cfdo',
    'vp acad',
    'vp admin'
];

function normalize_role($r) {
    // trim, lower-case, collapse multiple spaces
    $s = is_string($r) ? trim(strtolower($r)) : '';
    return preg_replace('/\s+/', ' ', $s);
}

$userRole = '';
if (isset($current_user) && isset($current_user['role'])) {
    $userRole = normalize_role($current_user['role']);
} elseif (isset($_SESSION['role'])) {
    $userRole = normalize_role($_SESSION['role']);
}

// Normalize admin roles once
$normalizedAdminRoles = array_map('normalize_role', $admin_roles);

if (in_array($userRole, $normalizedAdminRoles, true)) {
    // user is admin for these review pages — allow admin actions
    $is_admin = true;
} else {
    $is_admin = false;
}

// Start the page using the template function
start_page("Select Request Type", $role, $full_name);
?>

<div class="container mx-auto p-6 md:p-10">
    <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">
        Submit a New Request
    </h2>
    <p class="text-gray-600 mb-10">
        Please select the type of financial or venue request you wish to submit for your organization.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">

        <a href="request_create.php?type=Budget" 
           class="block bg-white p-8 rounded-xl shadow-2xl hover:shadow-indigo-400/50 transition duration-300 transform hover:scale-[1.03] border-4 border-indigo-200">
            <div class="flex flex-col items-center text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-indigo-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                <h3 class="text-2xl font-bold text-indigo-800">Budget Request</h3>
                <p class="text-sm text-gray-500 mt-2">For requesting funds and general activity budgets.</p>
            </div>
        </a>

        <a href="request_create.php?type=Liquidation" 
           class="block bg-white p-8 rounded-xl shadow-2xl hover:shadow-teal-400/50 transition duration-300 transform hover:scale-[1.03] border-4 border-teal-200">
            <div class="flex flex-col items-center text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-teal-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="text-2xl font-bold text-teal-800">Liquidation Form</h3>
                <p class="text-sm text-gray-500 mt-2">For reporting the use of previously approved funds.</p>
            </div>
        </a>

        <a href="request_create.php?type=Reimbursement" 
           class="block bg-white p-8 rounded-xl shadow-2xl hover:shadow-orange-400/50 transition duration-300 transform hover:scale-[1.03] border-4 border-orange-200">
            <div class="flex flex-col items-center text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-orange-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 8v1m-9-5h2.25A1.75 1.75 0 0011 11.25v-.5a1.75 1.75 0 01-1.75-1.75V9A2 2 0 015 7.5V6a2 2 0 012-2h10a2 2 0 012 2v1.5a2 2 0 01-2 2v.5A1.75 1.75 0 0014 12.75h-2.25A1.75 1.75 0 019 11.25v-.5a1.75 1.75 0 00-1.75-1.75V9a2 2 0 00-2-2h-2z" />
                </svg>
                <h3 class="text-2xl font-bold text-orange-800">Reimbursement</h3>
                <p class="text-sm text-gray-500 mt-2">For recovering personal funds used for organization expenses.</p>
            </div>
        </a>

        <a href="request_venue.php" 
           class="block bg-white p-8 rounded-xl shadow-2xl hover:shadow-purple-400/50 transition duration-300 transform hover:scale-[1.03] border-4 border-purple-200">
            <div class="flex flex-col items-center text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-purple-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.828 0l-4.243-4.243m10.606 0L20.5 14.857A2 2 0 0022 13.0V6a2 2 0 00-2-2H4a2 2 0 00-2 2v7a2 2 0 001.5 1.986l4.243 1.786m10.606 0L17.657 16.657z" />
                </svg>
                <h3 class="text-2xl font-bold text-purple-800">Venue Booking</h3>
                <p class="text-sm text-gray-500 mt-2">For reserving rooms, halls, or facilities.</p>
            </div>
        </a>

    </div>
    </div>

<?php end_page(); ?>