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
    <title>AUFthorize Login</title>
    <!-- Load Tailwind CSS from CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Link to the external stylesheet -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="login-bg min-h-screen flex items-center justify-center px-4 py-10 relative overflow-hidden">
    <span class="floating-bubble bubble-lg" aria-hidden="true"></span>
    <span class="floating-bubble bubble-sm" aria-hidden="true"></span>

    <main class="relative z-10 w-full max-w-5xl">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
            <section class="glass-panel p-8 lg:p-10 text-white flex flex-col justify-between">
                <div>
                    <div class="ios-pill mb-6">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-white/80"></span>
                        Developed by Pinacate & Sarmiento
                    </div>
                    <h1 class="text-4xl font-semibold leading-tight tracking-tight">
                        A unified, transparent command center for every AUF request.
                    </h1>
                    <p class="mt-20 text-white/80 text-base">
                        Designed to streamline the operational backbone of the AUF College Student Council, AUFthorize provides a unified digital platform for managing venue requests, funding workflows, and approval chains.
                    </p>
                </div>
                <div class="mt-6">
                    <p class="text-white/70 text-sm leading-relaxed">
                        AUFthorize keeps officers, admins, and organizations aligned with transparent statuses and glass-clear insights.
                    </p>
                </div>
            </section>

            <section class="glass-card p-8 lg:p-10 text-slate-900/90 flex flex-col gap-8">
                <div class="flex flex-col items-center text-center gap-4">
                    <!-- Logo placeholder block. Replace with the actual AUFthorize branding when available. -->
                    <div class="logo-placeholder" role="img" aria-label="AUFthorize logo placeholder">
                        <span><img src="pics/aufthorize.png" alt="AUFthorize Logo" class="h-20 w-auto"></span>
                    </div>
                    <div>
                        <p class="logo-caption mb-2">AUFthorize</p>
                        <h2 class="text-2xl font-semibold tracking-tight">Welcome back</h2>
                        <p class="text-white-500 text-sm">Sign in with your campus credentials to continue.</p>
                    </div>
                </div>

                <?php if(!empty($login_err)): ?>
                    <div class="bg-red-500/15 border border-red-400/60 text-red-600 px-4 py-3 rounded-2xl text-sm shadow-inner" role="alert">
                        <?php echo $login_err; ?>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-5">
                    <div>
                        <label for="username" class="text-xs font-semibold uppercase tracking-[0.4em] text-white-500 mb-2 block">
                            Username
                        </label>
                        <input 
                            type="text" 
                            name="username" 
                            id="username" 
                            class="glass-input <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" 
                            value="<?php echo htmlspecialchars($username); ?>" 
                            placeholder="Enter your username">
                        <?php if (!empty($username_err)): ?>
                            <p class="invalid-feedback"><?php echo $username_err; ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label for="password" class="text-xs font-semibold uppercase tracking-[0.4em] text-white-500 mb-2 block">
                            Password
                        </label>
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
                            class="glass-input <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" 
                            placeholder="Enter your password">
                        <?php if (!empty($password_err)): ?>
                            <p class="invalid-feedback"><?php echo $password_err; ?></p>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="glass-button w-full text-base">
                        Sign In
                    </button>
                </form>

                <div class="text-center text-sm text-white-500">
                    Need an account?
                    <a href="register.php" class="font-semibold text-slate-800 hover:text-indigo-500 transition">
                        Register for AUFthorize
                    </a>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
<?php
// NOTE: Additional PHP logic can be placed here if needed.
?>
