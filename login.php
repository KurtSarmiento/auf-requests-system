<?php
// Initialize the session
session_start();

// Check if the user is already logged in, if yes then redirect them to dashboard
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

// Include the database connection file
require_once "db_config.php";

$username = $password = "";
$username_err = $password_err = $login_err = "";

// Display success message from registration
$success_message = "";
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying
}

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
        $sql = "SELECT user_id, org_id, username, password_hash, full_name, role FROM users WHERE username = ?";

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
                            // Password is correct, start a new session
                            session_start();

                            // Store data in session variables
                            $_SESSION["loggedin"] = true;
                            $_SESSION["user_id"] = $user_id;
                            $_SESSION["org_id"] = $org_id;
                            $_SESSION["username"] = $username;
                            $_SESSION["full_name"] = $full_name;
                            $_SESSION["role"] = $role;

                            // Redirect user to dashboard page
                            header("location: dashboard.php");
                            exit;

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
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f7f7; }
        .error { color: #dc2626; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md bg-white p-8 rounded-xl shadow-2xl transition duration-300 hover:shadow-3xl">
        <h2 class="text-3xl font-extrabold text-center text-indigo-700 mb-6">Welcome Back</h2>
        <p class="text-center text-gray-500 mb-8">Sign in to access the AUF Request System.</p>

        <?php 
        // Display generic login error message
        if(!empty($login_err)){
            echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">' . $login_err . '</div>';
        }        
        // Display registration success message
        if(!empty($success_message)){
            echo '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">' . $success_message . '</div>';
        }
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            
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
            <div class="mb-6">
                <label for="password" class="block text-gray-700 font-semibold mb-2">Password</label>
                <input type="password" name="password" id="password" 
                    class="w-full p-3 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 
                    <?php echo (!empty($password_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required>
                <span class="error"><?php echo $password_err; ?></span>
            </div>
            
            <!-- Submit Button -->
            <div class="flex flex-col gap-4">
                <button type="submit" 
                    class="w-full bg-indigo-600 text-white font-bold py-3 rounded-lg hover:bg-indigo-700 transition duration-200 shadow-md hover:shadow-lg">
                    Login
                </button>
                <p class="text-center text-sm text-gray-500">
                    Don't have an account? <a href="register.php" class="text-indigo-600 hover:text-indigo-800 font-medium">Register your organization here</a>.
                </p>
            </div>
        </form>
    </div>
</body>
</html>
