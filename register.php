<?php
// Initialize the session
session_start();
 
// Check if the user is already logged in, if so redirect to dashboard.
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}
 
// Include config file
require_once "db_config.php";
 
// Define variables and initialize with empty values
$full_name = $username = $org_id = $password = $confirm_password = $email = "";
$full_name_err = $username_err = $org_id_err = $password_err = $confirm_password_err = $email_err = "";

// --- 1. Fetch Organizations for Dropdown ---
$organizations = [];
$org_sql = "SELECT org_id, org_name FROM organizations ORDER BY org_name";
if ($org_result = mysqli_query($link, $org_sql)) {
    while ($row = mysqli_fetch_assoc($org_result)) {
        $organizations[] = $row;
    }
    mysqli_free_result($org_result);
} else {
    // If fetching organizations fails, registration cannot proceed correctly
    $org_id_err = "Error loading organizations list from the database.";
}

// --- 2. Process form data on POST ---
if($_SERVER["REQUEST_METHOD"] == "POST"){
 
    // Validate Full Name
    if(empty(trim($_POST["full_name"]))){
        $full_name_err = "Please enter your full name.";
    } else{
        $full_name = trim($_POST["full_name"]);
    }

    // Validate Email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter an email address.";
    } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Please enter a valid email address.";
    } else {
        // Check if email is already taken
        $sql = "SELECT user_id FROM users WHERE email = ?";
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = trim($_POST["email"]);

            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                if(mysqli_stmt_num_rows($stmt) == 1){
                    $email_err = "This email is already taken.";
                } else{
                    $email = trim($_POST["email"]);
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // Validate Organization ID
    if(empty($_POST["org_id"])){
        $org_id_err = "Please select your organization.";
    } else{
        $org_id = intval($_POST["org_id"]);
    }

    // Validate Username
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter a username.";
    } elseif(!preg_match('/^[a-zA-Z0-9_]+$/', trim($_POST["username"]))){
        $username_err = "Username can only contain letters, numbers, and underscores.";
    } else{
        // Prepare a select statement to check if username already exists
        $sql = "SELECT user_id FROM users WHERE username = ?";
        
        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = trim($_POST["username"]);
            
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                
                if(mysqli_stmt_num_rows($stmt) == 1){
                    $username_err = "This username is already taken.";
                } else{
                    $username = trim($_POST["username"]);
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }

            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate Password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter a password.";     
    } elseif(strlen(trim($_POST["password"])) < 6){
        $password_err = "Password must have at least 6 characters.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate Confirm Password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm password.";     
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($password_err) && ($password != $confirm_password)){
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check input errors before inserting in database
    if(empty($full_name_err) && empty($email_err) && empty($username_err) && empty($org_id_err) && empty($password_err) && empty($confirm_password_err)){
        
        // =================================================================
        // === CRITICAL BUG FIX: Re-ordered SQL and bind_param to match ===
        // =================================================================
        
        // Prepare an insert statement in a logical order
        $sql = "INSERT INTO users (org_id, full_name, email, username, password, role) VALUES (?, ?, ?, ?, ?, ?)";
         
        if($stmt = mysqli_prepare($link, $sql)){
            // Hash the password for security
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'Officer'; // Default role for new registrations

            // Bind params to match the SQL query order
            // (i)nt org_id, (s)tring full_name, (s)tring email, (s)tring username, (s)tring password, (s)tring role
            mysqli_stmt_bind_param($stmt, "isssss", 
                $param_org_id, 
                $param_full_name, 
                $param_email, 
                $param_username, 
                $param_password, 
                $param_role
            );
            
            // Set parameters
            $param_org_id = $org_id;
            $param_full_name = $full_name;
            $param_email = $email;
            $param_username = $username;
            $param_password = $hashed_password;
            $param_role = $role;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Redirect to login page after successful registration
                header("location: login.php?success=registered");
            } else{
                // Display error message if insertion failed
                echo '<script>alert("Error: Could not register user. Please try again later.");</script>';
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
    <title>Register - AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom font and base colors */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #e5e7eb; /* Fallback */
        }
        
        /* * CSS for the Moving Gradient Background (Matching Login Page)
         */
        .register-bg {
            background: linear-gradient(-45deg, #1e3a8a, #172554, #4f46e5, #3730a3);
            background-size: 400% 400%; /* Makes the gradient huge for smooth movement */
            animation: gradient-shift 15s ease infinite; 
        }
        
        @keyframes gradient-shift {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }
    </style>
</head>
<body class="register-bg min-h-screen flex items-center justify-center p-4">

    <!-- Registration Container Card -->
    <!-- Increased max-width for the form to look less cramped -->
    <div class="w-full max-w-lg bg-white rounded-xl shadow-2xl overflow-hidden p-8 sm:p-10">
        
        <!-- Header Area -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-indigo-800">Register as Officer</h1>
            <p class="text-gray-500 mt-1">Create your account to submit funding requests.</p>
        </div>

        <!-- Registration Form -->
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">

            <!-- Full Name Field -->
            <div class="mb-5">
                <label for="full_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="full_name" id="full_name" 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                              <?php echo (!empty($full_name_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($full_name); ?>" 
                       placeholder="e.g., Juan Dela Cruz">
                <?php if (!empty($full_name_err)): ?>
                    <p class="text-red-500 text-xs mt-1"><?php echo $full_name_err; ?></p>
                <?php endif; ?>
            </div>

            <!-- Organization Dropdown -->
            <div class="mb-5">
                <label for="org_id" class="block text-sm font-medium text-gray-700 mb-1">Affiliated Organization</label>
                <select name="org_id" id="org_id" 
                        class="w-full px-4 py-2 border rounded-lg appearance-none bg-white focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                               <?php echo (!empty($org_id_err)) ? 'border-red-500' : 'border-gray-300'; ?>">
                    <option value="" disabled selected>Select an organization</option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?php echo htmlspecialchars($org['org_id']); ?>"
                                <?php echo ($org_id == $org['org_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($org['org_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($org_id_err)): ?>
                    <p class="text-red-500 text-xs mt-1"><?php echo $org_id_err; ?></p>
                <?php endif; ?>
            </div>

            <!-- Username Field -->
            <div class="mb-5">
                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="username" 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                              <?php echo (!empty($username_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($username); ?>" 
                       placeholder="Unique username for login">
                <?php if (!empty($username_err)): ?>
                    <p class="text-red-500 text-xs mt-1"><?php echo $username_err; ?></p>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <!-- Password Field -->
                <div class="mb-5 sm:mb-0">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" id="password" 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                                  <?php echo (!empty($password_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                           placeholder="••••••••">
                    <?php if (!empty($password_err)): ?>
                        <p class="text-red-500 text-xs mt-1"><?php echo $password_err; ?></p>
                    <?php endif; ?>
                </div>

                <!-- Confirm Password Field -->
                <div class="mb-6">
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                                  <?php echo (!empty($confirm_password_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                           placeholder="••••••••">
                    <?php if (!empty($confirm_password_err)): ?>
                        <p class="text-red-500 text-xs mt-1"><?php echo $confirm_password_err; ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- === FIELD MOVED HERE === -->
            <!-- Email Address Field -->
            <div class="mb-6">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="email" 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                              <?php echo (!empty($email_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                       value="<?php echo htmlspecialchars($email); ?>" 
                       placeholder="e.g., juandelacruz@auf.edu.ph">
                <?php if (!empty($email_err)): ?>
                    <p class="text-red-500 text-xs mt-1"><?php echo $email_err; ?></p>
                <?php endif; ?>
            </div>
            <!-- === END OF MOVED FIELD === -->

            <!-- Submit Button -->
            <button type="submit" 
                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out mt-4">
                Register Account
            </button>
        </form>

        <!-- Footer Link -->
        <div class="mt-6 text-center text-sm">
            <p class="text-gray-600">Already have an account? 
                <a href="login.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                    Sign in here
                </a>.
            </p>
        </div>
    </div>
</body>
</html>
