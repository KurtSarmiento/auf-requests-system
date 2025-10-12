<?php
// Initialize the session
session_start();
 
// Check if the user is already logged in, if so redirect to the dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}
 
// Include config file
require_once "db_config.php";
 
$username = $password = "";
$username_err = $password_err = $login_err = "";
 
// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    // Check if username is empty
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    // Check if password is empty
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if(empty($username_err) && empty($password_err)){
        // Prepare a select statement
        $sql = "SELECT user_id, username, password, role, full_name, org_id FROM users WHERE username = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            
            // Set parameters
            $param_username = $username;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Store result
                mysqli_stmt_store_result($stmt);
                
                // Check if username exists, if yes then verify password
                if(mysqli_stmt_num_rows($stmt) == 1){                    
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $user_id, $username, $hashed_password, $role, $full_name, $org_id);
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            // Password is correct, so start a new session
                            session_start();
                            
                            // Store data in session variables
                            $_SESSION["loggedin"] = true;
                            $_SESSION["user_id"] = $user_id;
                            $_SESSION["username"] = $username;
                            $_SESSION["role"] = $role;
                            $_SESSION["full_name"] = $full_name;
                            $_SESSION["org_id"] = $org_id;
                            
                            // Redirect user to dashboard page
                            header("location: dashboard.php");
                        } else{
                            // Password is not valid, display a generic error message
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else{
                    // Username doesn't exist, display a generic error message
                    $login_err = "Invalid username or password.";
                }
            } else{
                $login_err = "Oops! Something went wrong with the database query. Please try again later.";
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
    <title>Login - AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom font and base colors for an institutional look */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #e5e7eb; /* Light gray background */
        }
        .login-bg {
            /* Example: Darker blue for background contrast */
            background-image: linear-gradient(135deg, #1e3a8a 0%, #172554 100%);
        }
    </style>
</head>
<body class="login-bg min-h-screen flex items-center justify-center p-4">

    <!-- Login Container Card -->
    <div class="w-full max-w-md bg-white rounded-xl shadow-2xl overflow-hidden p-8 sm:p-10">
        
        <!-- Header / Logo Area -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-indigo-800">AUF Request System</h1>
            <p class="text-gray-500 mt-1">Sign in to your account</p>
        </div>

        <?php 
        // Display login error if set
        if(!empty($login_err)){
            echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4 text-sm" role="alert">' . $login_err . '</div>';
        }        
        ?>

        <!-- Login Form -->
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            
            <!-- Username Field -->
            <div class="mb-5">
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="username" 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                              <?php echo (!empty($username_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($username); ?>" 
                       placeholder="Enter your username">
                <?php if (!empty($username_err)): ?>
                    <p class="text-red-500 text-xs mt-1"><?php echo $username_err; ?></p>
                <?php endif; ?>
            </div>

            <!-- Password Field -->
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                              <?php echo (!empty($password_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                       placeholder="••••••••">
                <?php if (!empty($password_err)): ?>
                    <p class="text-red-500 text-xs mt-1"><?php echo $password_err; ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Submit Button -->
            <button type="submit" 
                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                Sign In
            </button>
        </form>

        <!-- Footer Link -->
        <div class="mt-6 text-center text-sm">
            <p class="text-gray-600">Don't have an account? 
                <a href="register.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                    Sign up now
                </a>.
            </p>
        </div>
    </div>
</body>
</html>
