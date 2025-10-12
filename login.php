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
 
$username = $password = "";
$username_err = $password_err = $login_err = "";
 
// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
 
    // Check if username is empty
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter username.";
    } else {
        $username = trim($_POST["username"]);
    }
    
    // Check if password is empty
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if (empty($username_err) && empty($password_err)) {
        // Prepare a select statement
        // *** FIX APPLIED: SELECT column is now 'password' ***
        $sql = "SELECT user_id, org_id, username, password, full_name, role FROM users WHERE username = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            
            // Set parameters
            $param_username = $username;
            
            // Attempt to execute the prepared statement
            if (mysqli_stmt_execute($stmt)) {
                // Store result
                mysqli_stmt_store_result($stmt);
                
                // Check if username exists, if yes then verify password
                if (mysqli_stmt_num_rows($stmt) == 1) {                    
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $user_id, $org_id, $username, $hashed_password, $full_name, $role);
                    if (mysqli_stmt_fetch($stmt)) {
                        if (password_verify($password, $hashed_password)) {
                            // Password is correct, so start a new session
                            session_start();
                            
                            // Store data in session variables
                            $_SESSION["loggedin"] = true;
                            $_SESSION["user_id"] = $user_id;
                            $_SESSION["org_id"] = $org_id;
                            $_SESSION["username"] = $username;
                            $_SESSION["full_name"] = $full_name;
                            $_SESSION["role"] = $role; // Store the user's role

                            // Redirect user to the appropriate dashboard based on role
                            if ($role === 'Officer') {
                                header("location: dashboard.php");
                            } else {
                                header("location: admin_dashboard.php");
                            }
                            
                        } else {
                            // Password is not valid, display a generic error message
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else {
                    // Username doesn't exist, display a generic error message
                    $login_err = "Invalid username or password.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
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
    <title>Login | AUF Request System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .is-invalid { border-color: #ef4444; }
        .invalid-feedback { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-sm bg-white p-8 sm:p-10 rounded-xl shadow-2xl border border-gray-100">
        <h2 class="text-3xl font-extrabold text-center text-indigo-700 mb-6">Welcome Back</h2>
        <p class="text-center text-gray-500 mb-8">Login with your credentials.</p>

        <?php 
        if (!empty($login_err)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Login Error</p>
                <p><?php echo $login_err; ?></p>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-4">
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
            
            <div class="pt-4">
                <button type="submit" class="w-full bg-indigo-600 text-white py-2.5 rounded-lg text-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md transform hover:scale-[1.01] active:scale-95">
                    Login
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-sm text-gray-600">
            Don't have an account? <a href="register.php" class="font-medium text-indigo-600 hover:text-indigo-500">Register here</a>.
        </p>
    </div>
</body>
</html>