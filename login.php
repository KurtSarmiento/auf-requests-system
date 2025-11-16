<?php
// Initialize the session
session_start();
 
// Check if the user is already logged in, if so redirect to the dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}
 
// Include config file
// NOTE: Make sure your "db_config.php" file is in the same directory.
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
    // NOTE: This will fail if the script hits a header redirect above, but is standard practice.
    // If you need the link object later in the script for other purposes, you might move this.
    mysqli_close($link); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - AUF System</title>
    <!-- Load Tailwind CSS from CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Link to the external stylesheet -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="login-bg min-h-screen flex items-center justify-center p-4">

    <!-- Login Container Card -->
    <!-- The glass-card class applies the blur and transparency defined in style.css -->
    <div class="glass-card w-full max-w-md rounded-xl shadow-2xl overflow-hidden p-8 sm:p-10">
        
        <!-- Header / Logo Area -->
        <div class="text-center mb-8">
            <!-- Logo Placeholder -->
            <!-- You can replace the 'placeholder.png' with your actual logo path -->
            <img src="https://placehold.co/128x64/2563EB/ffffff?text=LOGO" onerror="this.src='https://placehold.co/128x64/2563EB/ffffff?text=LOGO'" alt="System Logo" class="mx-auto h-16 w-auto mb-4 rounded-lg" id="app-logo">
            
            <h1 class="text-3xl font-extrabold text-white drop-shadow-lg">AUF Request System</h1>
            <p class="text-indigo-200 mt-1">Sign in to your account</p>
        </div>

        <?php 
        // Display login error if set
        if(!empty($login_err)){
            echo '<div class="bg-red-500/30 border border-red-400 text-white px-4 py-3 rounded-lg mb-4 text-sm backdrop-blur-sm" role="alert">' . $login_err . '</div>';
        }      
        ?>

        <!-- Login Form -->
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            
            <!-- Username Field -->
            <div class="mb-5">
                <label for="username" class="block text-sm font-medium text-white mb-1 drop-shadow">Username</label>
                <!-- glass-input class for transparency and clean look -->
                <input type="text" name="username" id="username" 
                        class="glass-input w-full px-4 py-2 rounded-lg transition duration-150 
                             <?php echo (!empty($username_err)) ? 'border-red-500' : ''; ?>" 
                        value="<?php echo htmlspecialchars($username); ?>" 
                        placeholder="Enter your username">
                <?php if (!empty($username_err)): ?>
                    <p class="text-red-300 text-xs mt-1 drop-shadow"><?php echo $username_err; ?></p>
                <?php endif; ?>
            </div>

            <!-- Password Field -->
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-white mb-1 drop-shadow">Password</label>
                <!-- glass-input class for transparency and clean look -->
                <input type="password" name="password" id="password" 
                        class="glass-input w-full px-4 py-2 rounded-lg transition duration-150 
                             <?php echo (!empty($password_err)) ? 'border-red-500' : ''; ?>" 
                        placeholder="••••••••">
                <?php if (!empty($password_err)): ?>
                    <p class="text-red-300 text-xs mt-1 drop-shadow"><?php echo $password_err; ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Submit Button -->
            <!-- glass-button class for the glassy look -->
            <button type="submit" 
                     class="glass-button w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-lg text-sm font-medium text-white hover:shadow-xl transition duration-300 ease-in-out">
                Sign In
            </button>
        </form>

        <!-- Footer Link -->
        <div class="mt-6 text-center text-sm">
            <p class="text-indigo-200">Don't have an account? 
                <a href="register.php" class="font-medium text-white hover:text-indigo-100 transition drop-shadow-lg">
                    Sign up now
                </a>.
            </p>
        </div>
    </div>
</body>
</html>
<?php
// NOTE: Your original PHP code block end tag was not present, adding it here.
?>