<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";
 
// Define variables and initialize with empty values
$title = $type = $amount_str = $description = "";
$title_err = $type_err = $amount_err = $description_err = $general_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- 1. Validate inputs ---
    if (empty(trim($_POST["title"]))) {
        $title_err = "Please enter a request title.";
    } else {
        $title = trim($_POST["title"]);
    }
    
    if (empty(trim($_POST["type"]))) {
        $type_err = "Please select a request type.";
    } else {
        $type = trim($_POST["type"]);
    }

    $amount_str = trim($_POST["amount"]);
    if (!is_numeric($amount_str) || $amount_str <= 0) {
        $amount_err = "Please enter a valid amount (e.g., 500.00).";
        $amount = null; // Set to null if invalid
    } else {
        $amount = (float)$amount_str;
    }

    if (empty(trim($_POST["description"]))) {
        $description_err = "Please enter a detailed description.";
    } else {
        $description = trim($_POST["description"]);
    }
    
    // Check input errors before inserting into database
    if (empty($title_err) && empty($type_err) && empty($amount_err) && empty($description_err)) {
        
        // Prepare an insert statement
        $sql = "
            INSERT INTO requests (
                user_id, 
                title, 
                type, 
                amount, 
                description, 
                date_submitted,
                adviser_status,
                dean_status,
                osafa_status,
                afo_status,
                final_status,
                notification_status
            ) 
            VALUES (?, ?, ?, ?, ?, NOW(), 'Pending', 'Pending', 'Pending', 'Pending', 'Pending', 'Pending')
        ";
         
        if ($stmt = mysqli_prepare($link, $sql)) {
            // Bind variables to the prepared statement as parameters
            // 'isids' -> user_id(i), title(s), type(s), amount(d), description(s)
            mysqli_stmt_bind_param($stmt, "issds", 
                $param_user_id, 
                $param_title, 
                $param_type, 
                $param_amount, 
                $param_description
            );
            
            // Set parameters
            $param_user_id = $_SESSION["user_id"];
            $param_title = $title;
            $param_type = $type;
            $param_amount = $amount;
            $param_description = $description;
            
            // Attempt to execute the prepared statement
            if (mysqli_stmt_execute($stmt)) {
                // Redirect to request list page
                header("location: request_list.php?success=1");
                exit;
            } else {
                $general_err = "Database execution error: " . mysqli_error($link);
            }

            // Close statement
            mysqli_stmt_close($stmt);
        } else {
            $general_err = "Database statement preparation error: " . mysqli_error($link);
        }
    }
    
    // Close connection
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit New Request | AUF System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .is-invalid { border-color: #ef4444; }
        .invalid-feedback { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Bar -->
    <div class="bg-indigo-900 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Officer Panel</h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <a href="request_list.php" class="hover:text-indigo-200 transition duration-150">My Requests</a>
            <span class="text-sm font-light">
                Logged in as: <b><?php echo htmlspecialchars($_SESSION["full_name"]); ?></b>
            </span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h2 class="text-3xl font-extrabold text-gray-800">New Funding Request</h2>
            <p class="text-gray-500">Submitting for: **<?php echo htmlspecialchars($_SESSION["org_name"] ?? 'Your Organization'); ?>**</p>
        </div>

        <?php if (!empty($general_err)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Submission Error</p>
                <p><?php echo $general_err; ?></p>
            </div>
        <?php endif; ?>

        <div class="max-w-3xl mx-auto bg-white p-6 sm:p-10 rounded-xl shadow-2xl">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                
                <!-- Title -->
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Request Title (e.g., Annual Sports Fest Budget)</label>
                    <input type="text" name="title" id="title" 
                           class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($title_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                           value="<?php echo htmlspecialchars($title); ?>" required>
                    <span class="invalid-feedback"><?php echo $title_err; ?></span>
                </div>

                <!-- Type and Amount -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Request Type -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Request Type</label>
                        <select name="type" id="type" 
                                class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white <?php echo (!empty($type_err)) ? 'is-invalid' : 'border-gray-300'; ?>" required>
                            <option value="">-- Select Type --</option>
                            <option value="Fund Request" <?php echo ($type == 'Fund Request') ? 'selected' : ''; ?>>Fund Request</option>
                            <option value="Reimbursement" <?php echo ($type == 'Reimbursement') ? 'selected' : ''; ?>>Reimbursement</option>
                            <option value="Materials Purchase" <?php echo ($type == 'Materials Purchase') ? 'selected' : ''; ?>>Materials Purchase</option>
                        </select>
                        <span class="invalid-feedback"><?php echo $type_err; ?></span>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Total Amount (₱)</label>
                        <input type="text" name="amount" id="amount" 
                               class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($amount_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                               value="<?php echo htmlspecialchars($amount_str); ?>" placeholder="e.g. 15000.00" required>
                        <span class="invalid-feedback"><?php echo $amount_err; ?></span>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Detailed Justification / Breakdown</label>
                    <textarea name="description" id="description" rows="6" 
                              class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($description_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                              required><?php echo htmlspecialchars($description); ?></textarea>
                    <span class="invalid-feedback"><?php echo $description_err; ?></span>
                </div>

                <!-- Placeholder for File Upload (To be implemented next) -->
                <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                    <p class="font-semibold text-yellow-800">Attachment Upload</p>
                    <p class="text-sm text-yellow-700">The file upload field will be added here to include supporting documents like proposals or receipts.</p>
                </div>

                <!-- Submit Button -->
                <div class="pt-4">
                    <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md transform hover:scale-[1.01] active:scale-95">
                        Submit Request for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
