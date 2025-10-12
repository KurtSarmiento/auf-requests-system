<?php
// Initialize the session
session_start();
 
// Check if the user is logged in. If not, redirect to login page.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Include database connection
require_once "db_config.php";

// Get user info from session
$current_user_id = $_SESSION["user_id"];
$current_org_id = $_SESSION["org_id"];
$current_org_name = "";

// Initialize variables for request form
$type = $title = $description = $amount = "";
$type_err = $title_err = $description_err = $amount_err = "";
$submission_msg = "";

// Fetch Organization Name for display
$sql_org = "SELECT org_name FROM organizations WHERE org_id = ?";
if ($stmt_org = mysqli_prepare($link, $sql_org)) {
    mysqli_stmt_bind_param($stmt_org, "i", $param_org_id);
    $param_org_id = $current_org_id;
    if (mysqli_stmt_execute($stmt_org)) {
        mysqli_stmt_bind_result($stmt_org, $org_name_result);
        if (mysqli_stmt_fetch($stmt_org)) {
            $current_org_name = $org_name_result;
        }
    }
    mysqli_stmt_close($stmt_org);
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. Validate Request Type
    $request_types = ['Budget Request', 'Venue Reservation', 'Liquidation Report', 'Other'];
    if (empty(trim($_POST["type"])) || !in_array(trim($_POST["type"]), $request_types)) {
        $type_err = "Please select a valid request type.";
    } else {
        $type = trim($_POST["type"]);
    }

    // 2. Validate Title
    if (empty(trim($_POST["title"]))) {
        $title_err = "Please enter a descriptive title for your request.";
    } else {
        $title = trim($_POST["title"]);
    }

    // 3. Validate Description
    if (empty(trim($_POST["description"]))) {
        $description_err = "Please provide a detailed description.";
    } else {
        $description = trim($_POST["description"]);
    }

    // 4. Validate Amount (Optional, but if filled, must be numeric)
    $amount_input = trim($_POST["amount"]);
    if (!empty($amount_input)) {
        if (!is_numeric($amount_input) || $amount_input < 0) {
            $amount_err = "Amount must be a positive number.";
        } else {
            $amount = (float)$amount_input;
        }
    } else {
        $amount = NULL; // Store NULL in the database if empty
    }

    // Check input errors before inserting into database
    if (empty($type_err) && empty($title_err) && empty($description_err) && empty($amount_err)) {
        
        // Prepare an insert statement
        $sql = "INSERT INTO requests (user_id, type, title, description, amount, status) VALUES (?, ?, ?, ?, ?, 'Pending')";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            // Bind parameters
            // 'isssd' -> i: integer (user_id), s: string (type), s: string (title), s: string (description), d: double (amount)
            mysqli_stmt_bind_param($stmt, "isssd", $param_user_id, $param_type, $param_title, $param_description, $param_amount);
            
            // Set parameters
            $param_user_id = $current_user_id;
            $param_type = $type;
            $param_title = $title;
            $param_description = $description;
            $param_amount = $amount; // Will be NULL if not provided

            // Attempt to execute the prepared statement
            if (mysqli_stmt_execute($stmt)) {
                $submission_msg = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6' role='alert'>Success! Your request has been submitted and is currently **Pending** review.</div>";
                // Clear form inputs after successful submission
                $type = $title = $description = $amount = "";
            } else {
                $submission_msg = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6' role='alert'>Error: Could not submit request. Please try again.</div>";
            }

            // Close statement
            mysqli_stmt_close($stmt);
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
        .error { color: #dc2626; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Bar (Simple) -->
    <div class="bg-indigo-800 text-white p-4 shadow-lg flex justify-between items-center">
        <h1 class="text-xl font-bold">AUF Request System</h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="hover:text-indigo-200 transition duration-150">Dashboard</a>
            <span class="text-sm">Logged in as: <b><?php echo htmlspecialchars($_SESSION["username"]); ?></b></span>
            <a href="logout.php" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg transition duration-150">Logout</a>
        </div>
    </div>

    <div class="container mx-auto p-4 sm:p-8">
        <h2 class="text-3xl font-extrabold text-gray-800 mb-2">Submit New Request</h2>
        <p class="text-gray-500 mb-8">
            Submitting on behalf of your organization: <span class="font-semibold text-indigo-600"><?php echo htmlspecialchars($current_org_name); ?></span>.
        </p>
        
        <?php echo $submission_msg; ?>

        <div class="bg-white p-6 md:p-10 rounded-xl shadow-2xl">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                
                <!-- Request Type -->
                <div class="mb-6">
                    <label for="type" class="block text-gray-700 font-semibold mb-2">1. Type of Request</label>
                    <select name="type" id="type" 
                        class="w-full p-3 border rounded-lg bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                        <?php echo (!empty($type_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required>
                        <option value="">-- Select One --</option>
                        <option value="Budget Request" <?php echo ($type == 'Budget Request') ? 'selected' : ''; ?>>Budget Request</option>
                        <option value="Venue Reservation" <?php echo ($type == 'Venue Reservation') ? 'selected' : ''; ?>>Venue Reservation</option>
                        <option value="Liquidation Report" <?php echo ($type == 'Liquidation Report') ? 'selected' : ''; ?>>Liquidation Report</option>
                        <option value="Other" <?php echo ($type == 'Other') ? 'selected' : ''; ?>>Other / General Inquiry</option>
                    </select>
                    <span class="error"><?php echo $type_err; ?></span>
                </div>

                <!-- Request Title -->
                <div class="mb-6">
                    <label for="title" class="block text-gray-700 font-semibold mb-2">2. Title / Subject of Request</label>
                    <input type="text" name="title" id="title" placeholder="E.g., Event X Budget Proposal"
                        class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                        <?php echo (!empty($title_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                        value="<?php echo htmlspecialchars($title); ?>" required>
                    <span class="error"><?php echo $title_err; ?></span>
                </div>
                
                <!-- Amount (Optional) -->
                <div class="mb-6">
                    <label for="amount" class="block text-gray-700 font-semibold mb-2">3. Amount (If applicable - e.g., for Budget/Liquidation)</label>
                    <input type="number" step="0.01" name="amount" id="amount" placeholder="0.00 (Optional)"
                        class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                        <?php echo (!empty($amount_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                        value="<?php echo htmlspecialchars($amount); ?>">
                    <span class="error"><?php echo $amount_err; ?></span>
                </div>

                <!-- Description -->
                <div class="mb-6">
                    <label for="description" class="block text-gray-700 font-semibold mb-2">4. Detailed Description</label>
                    <textarea name="description" id="description" rows="5" placeholder="Include all necessary details like date, time, purpose, and specific requirements."
                        class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 resize-y 
                        <?php echo (!empty($description_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required><?php echo htmlspecialchars($description); ?></textarea>
                    <span class="error"><?php echo $description_err; ?></span>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" 
                    class="w-full bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700 transition duration-200 shadow-lg hover:shadow-xl">
                    Submit Request
                </button>
            </form>
        </div>
    </div>
</body>
</html>
