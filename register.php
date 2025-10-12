<?php
// Start session for potential future use (e.g., storing success messages)
session_start(); 

// Include the database connection file
require_once "db_config.php";

$org_name = $full_name = $username = $password = $confirm_password = "";
$org_name_err = $full_name_err = $username_err = $password_err = $confirm_password_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. Validate Organization Name
    if (empty(trim($_POST["org_name"]))) {
        $org_name_err = "Please enter an organization name.";
    } else {
        $org_name = trim($_POST["org_name"]);
        // Check if organization already exists
        $sql = "SELECT org_id FROM organizations WHERE org_name = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_org_name);
            $param_org_name = $org_name;
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    $org_name_err = "This organization already exists.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // 2. Validate Full Name
    if (empty(trim($_POST["full_name"]))) {
        $full_name_err = "Please enter your full name.";
    } else {
        $full_name = trim($_POST["full_name"]);
    }

    // 3. Validate Username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter a username.";
    } else {
        $username = trim($_POST["username"]);
        // Check if username already exists
        $sql = "SELECT user_id FROM users WHERE username = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = $username;
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    $username_err = "This username is already taken.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // 4. Validate Password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";     
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    // 5. Validate Confirm Password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";     
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check input errors before inserting into database
    if (empty($org_name_err) && empty($full_name_err) && empty($username_err) && empty($password_err) && empty($confirm_password_err)) {
        
        // Start transaction: we need to insert into two tables (organizations, users)
        mysqli_begin_transaction($link);
        $success = true;

        // A. Insert into organizations table
        $sql_org = "INSERT INTO organizations (org_name) VALUES (?)";
        if ($stmt_org = mysqli_prepare($link, $sql_org)) {
            mysqli_stmt_bind_param($stmt_org, "s", $param_org_name);
            $param_org_name = $org_name;
            if (mysqli_stmt_execute($stmt_org)) {
                $org_id = mysqli_insert_id($link); // Get the ID of the new organization
            } else {
                $success = false;
                echo "ERROR: Organization registration failed.";
            }
            mysqli_stmt_close($stmt_org);
        } else {
            $success = false;
        }

        // B. Insert into users table (only if organization insert was successful)
        if ($success) {
            $sql_user = "INSERT INTO users (org_id, username, password_hash, full_name, role) VALUES (?, ?, ?, ?, 'officer')";
            if ($stmt_user = mysqli_prepare($link, $sql_user)) {
                mysqli_stmt_bind_param($stmt_user, "isss", $param_org_id, $param_username, $param_password, $param_full_name);
                
                $param_org_id = $org_id;
                $param_username = $username;
                $param_password = password_hash($password, PASSWORD_DEFAULT); // Creates a password hash
                $param_full_name = $full_name;
                
                if (mysqli_stmt_execute($stmt_user)) {
                    // Transaction successful. Commit changes and redirect.
                    mysqli_commit($link);
                    $_SESSION['success_message'] = "Your organization and account were registered successfully. Please log in.";
                    header("location: login.php");
                    exit();
                } else {
                    $success = false;
                    echo "ERROR: User registration failed.";
                }
                mysqli_stmt_close($stmt_user);
            } else {
                $success = false;
            }
        }

        // If any part of the transaction failed, roll back
        if (!$success) {
            mysqli_rollback($link);
            echo "An error occurred during registration. Please try again.";
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
    <title>Register | AUF Request System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom font for better readability */
        body { font-family: 'Inter', sans-serif; background-color: #f7f7f7; }
        .error { color: #dc2626; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md bg-white p-8 rounded-xl shadow-2xl transition duration-300 hover:shadow-3xl">
        <h2 class="text-3xl font-extrabold text-center text-indigo-700 mb-6">Organization & User Registration</h2>
        <p class="text-center text-gray-500 mb-8">Register your organization and first user account (Officer Role).</p>
        
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            
            <!-- Organization Name -->
            <div class="mb-4">
                <label for="org_name" class="block text-gray-700 font-semibold mb-2">Organization Name</label>
                <input type="text" name="org_name" id="org_name" 
                    class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                    <?php echo (!empty($org_name_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                    value="<?php echo $org_name; ?>" required>
                <span class="error"><?php echo $org_name_err; ?></span>
            </div>

            <!-- Full Name -->
            <div class="mb-4">
                <label for="full_name" class="block text-gray-700 font-semibold mb-2">Your Full Name</label>
                <input type="text" name="full_name" id="full_name" 
                    class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                    <?php echo (!empty($full_name_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                    value="<?php echo $full_name; ?>" required>
                <span class="error"><?php echo $full_name_err; ?></span>
            </div>
            
            <!-- Username -->
            <div class="mb-4">
                <label for="username" class="block text-gray-700 font-semibold mb-2">Username</label>
                <input type="text" name="username" id="username" 
                    class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                    <?php echo (!empty($username_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                    value="<?php echo $username; ?>" required>
                <span class="error"><?php echo $username_err; ?></span>
            </div>
            
            <!-- Password -->
            <div class="mb-4">
                <label for="password" class="block text-gray-700 font-semibold mb-2">Password</label>
                <input type="password" name="password" id="password" 
                    class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                    <?php echo (!empty($password_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required>
                <span class="error"><?php echo $password_err; ?></span>
            </div>
            
            <!-- Confirm Password -->
            <div class="mb-6">
                <label for="confirm_password" class="block text-gray-700 font-semibold mb-2">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" 
                    class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                    <?php echo (!empty($confirm_password_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required>
                <span class="error"><?php echo $confirm_password_err; ?></span>
            </div>
            
            <!-- Submit Button -->
            <div class="flex flex-col gap-4">
                <button type="submit" 
                    class="w-full bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700 transition duration-200 shadow-md hover:shadow-lg">
                    Register Organization & User
                </button>
                <p class="text-center text-sm text-gray-500">
                    Already have an account? <a href="login.php" class="text-indigo-600 hover:text-indigo-800 font-medium">Login here</a>.
                </p>
            </div>
        </form>
    </div>
</body>
</html>
