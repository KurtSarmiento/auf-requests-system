<?php
// Initialize the session
session_start();
 
// Check if the user is already logged in, if so redirect to dashboard
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    // Redirect based on role
    if ($_SESSION["role"] === 'Officer') {
        header("location: dashboard.php");
    } else {
        header("location: admin_dashboard.php");
    }
    exit;
}

require_once "db_config.php";

// Define variables and initialize with empty values
$org_name = $username = $full_name = $password = $confirm_password = "";
$org_name_err = $username_err = $full_name_err = $password_err = $confirm_password_err = $general_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- 1. Validate inputs ---
    if (empty(trim($_POST["org_name"]))) {
        $org_name_err = "Please enter an organization name.";
    } else {
        $org_name = trim($_POST["org_name"]);
    }
    
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter a username.";
    } else {
        $username = trim($_POST["username"]);
    }

    if (empty(trim($_POST["full_name"]))) {
        $full_name_err = "Please enter your full name.";
    } else {
        $full_name = trim($_POST["full_name"]);
    }

    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";     
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";     
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Passwords did not match.";
        }
    }

    // Check input errors before interacting with database
    if (empty($org_name_err) && empty($username_err) && empty($full_name_err) && empty($password_err) && empty($confirm_password_err)) {
        
        // Use transactions for safety
        mysqli_begin_transaction($link);
        $org_id = 0;
        $success = true;

        try {
            // --- 2. Check if Organization already exists or insert new Organization ---
            $sql_org_check = "SELECT org_id FROM organizations WHERE org_name = ?";
            if ($stmt_org_check = mysqli_prepare($link, $sql_org_check)) {
                mysqli_stmt_bind_param($stmt_org_check, "s", $param_org_name);
                $param_org_name = $org_name;
                if (mysqli_stmt_execute($stmt_org_check)) {
                    mysqli_stmt_store_result($stmt_org_check);
                    if (mysqli_stmt_num_rows($stmt_org_check) == 1) {
                        // Organization exists, retrieve ID
                        mysqli_stmt_bind_result($stmt_org_check, $org_id);
                        mysqli_stmt_fetch($stmt_org_check);
                    } else {
                        // Organization does not exist, insert new one
                        $sql_org_insert = "INSERT INTO organizations (org_name) VALUES (?)";
                        if ($stmt_org_insert = mysqli_prepare($link, $sql_org_insert)) {
                            mysqli_stmt_bind_param($stmt_org_insert, "s", $param_org_name);
                            if (mysqli_stmt_execute($stmt_org_insert)) {
                                $org_id = mysqli_insert_id($link);
                            } else {
                                $general_err = "Organization registration failed: " . mysqli_error($link);
                                $success = false;
                            }
                            mysqli_stmt_close($stmt_org_insert);
                        } else {
                            $general_err = "Organization statement failed: " . mysqli_error($link);
                            $success = false;
                        }
                    }
                } else {
                    $general_err = "Organization check failed: " . mysqli_error($link);
                    $success = false;
                }
                mysqli_stmt_close($stmt_org_check);
            }

            // --- 3. Insert User if organization process was successful ---
            if ($success && $org_id > 0) {
                // Check if username already exists
                $sql_user_check = "SELECT user_id FROM users WHERE username = ?";
                if ($stmt_user_check = mysqli_prepare($link, $sql_user_check)) {
                    mysqli_stmt_bind_param($stmt_user_check, "s", $param_username);
                    $param_username = $username;
                    if (mysqli_stmt_execute($stmt_user_check)) {
                        mysqli_stmt_store_result($stmt_user_check);
                        if (mysqli_stmt_num_rows($stmt_user_check) >= 1) {
                            $username_err = "This username is already taken.";
                            $success = false;
                        }
                    }
                    mysqli_stmt_close($stmt_user_check);
                }

                // If username is available, insert the new user
                if ($success) {
                    // *** FIX APPLIED: Column name is now 'password' ***
                    $sql_user_insert = "INSERT INTO users (org_id, username, password, full_name, role) VALUES (?, ?, ?, ?, 'Officer')";
                    
                    if ($stmt_user_insert = mysqli_prepare($link, $sql_user_insert)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT); // Hash the password
                        
                        mysqli_stmt_bind_param($stmt_user_insert, "isss", $org_id, $param_username, $hashed_password, $param_full_name);
                        
                        $param_username = $username;
                        $param_full_name = $full_name;
                        
                        if (mysqli_stmt_execute($stmt_user_insert)) {
                            // Commit transaction
                            mysqli_commit($link);
                            
                            // Redirect to login page
                            header("location: login.php");
                            exit;
                        } else {
                            $general_err = "User registration failed: " . mysqli_error($link);
                            $success = false;
                        }
                        mysqli_stmt_close($stmt_user_insert);
                    } else {
                        $general_err = "User statement failed: " . mysqli_error($link);
                        $success = false;
                    }
                }
            }

        } catch (Exception $e) {
            $general_err = "An error occurred during registration: " . $e->getMessage();
            $success = false;
        }

        // If any part failed, roll back the transaction
        if (!$success) {
            mysqli_rollback($link);
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
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .is-invalid { border-color: #ef4444; }
        .invalid-feedback { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-lg bg-white p-8 sm:p-10 rounded-xl shadow-2xl border border-gray-100">
        <h2 class="text-3xl font-extrabold text-center text-indigo-700 mb-6">Create Officer Account</h2>
        <p class="text-center text-gray-500 mb-8">This will create a new organization (if it doesn't exist) and your first officer account.</p>

        <?php if (!empty($general_err)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo $general_err; ?></p>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-4">
            
            <div>
                <label for="org_name" class="block text-sm font-medium text-gray-700">Organization Name</label>
                <input type="text" name="org_name" id="org_name" 
                       class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($org_name_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($org_name); ?>">
                <span class="invalid-feedback"><?php echo $org_name_err; ?></span>
            </div>

            <div>
                <label for="full_name" class="block text-sm font-medium text-gray-700">Your Full Name</label>
                <input type="text" name="full_name" id="full_name" 
                       class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($full_name_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($full_name); ?>">
                <span class="invalid-feedback"><?php echo $full_name_err; ?></span>
            </div>

            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username" 
                       class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($username_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($username); ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>
            
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password" 
                       class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($password_err)) ? 'is-invalid' : 'border-gray-300'; ?>">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            
            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                <input type="password" name="confirm_password" id="confirm_password" 
                       class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : 'border-gray-300'; ?>">
                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full bg-indigo-600 text-white py-2.5 rounded-lg text-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md transform hover:scale-[1.01] active:scale-95">
                    Register
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-gray-600">
            Already have an account? <a href="login.php" class="font-medium text-indigo-600 hover:text-indigo-500">Log in here</a>.
        </p>
    </div>
</body>
</html>