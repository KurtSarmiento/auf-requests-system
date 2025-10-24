<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";

// --- Ensure we have an organization name to display (fallback to DB lookup if session missing) ---
$organization_name = '';
if (!empty($_SESSION['org_name'])) {
    $organization_name = $_SESSION['org_name'];
} elseif (!empty($_SESSION['org_id'])) {
    $org_id = (int)$_SESSION['org_id'];
    $org_sql = "SELECT org_name FROM organizations WHERE org_id = ?";
    if ($stmt = mysqli_prepare($link, $org_sql)) {
        mysqli_stmt_bind_param($stmt, "i", $org_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $org_name_db);
            mysqli_stmt_fetch($stmt);
            $organization_name = $org_name_db ?? '';
            // cache it in session for subsequent pages
            if ($organization_name !== '') {
                $_SESSION['org_name'] = $organization_name;
            }
        }
        mysqli_stmt_close($stmt);
    }
}
 
// Define variables and initialize with empty values
$title = $type = $amount_str = $description = "";
$title_err = $type_err = $amount_err = $description_err = $file_err = $general_err = "";

// Directory for file uploads (Must be created manually)
$upload_dir = __DIR__ . "/uploads/"; 

// Helper: convert php ini size like "8M" to bytes
function phpSizeToBytes(string $size): int {
    $unit = strtoupper(substr($size, -1));
    $bytes = (int) $size;
    switch ($unit) {
        case 'G': $bytes *= 1024;
        case 'M': $bytes *= 1024;
        case 'K': $bytes *= 1024;
    }
    return $bytes;
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Detect obvious server-side limits exceeded (POST was discarded)
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    $postMax = phpSizeToBytes(ini_get('post_max_size'));
    $uploadMax = phpSizeToBytes(ini_get('upload_max_filesize'));

    if ($contentLength > 0 && $contentLength > $postMax) {
        $general_err = "Upload failed: the submitted data exceeds the server limit (post_max_size). Try a smaller file or contact the admin to increase post_max_size/upload_max_filesize.";
    }

    // If PHP discarded POST due to size you may also see empty $_POST/$_FILES
    if (empty($general_err) && empty($_POST) && empty($_FILES)) {
        $general_err = "No form data received. The upload may have exceeded server limits or the request was blocked. Try a smaller file or check server upload settings.";
    }

    // Only attempt normal validation if we have POST data and no pre-existing general error
    if (empty($general_err)) {

        // --- 1. Validate inputs (use null-coalescing to avoid undefined index warnings) ---
        $raw_title = $_POST["title"] ?? '';
        $raw_type = $_POST["type"] ?? '';
        $raw_amount = $_POST["amount"] ?? '';
        $raw_description = $_POST["description"] ?? '';

        if (empty(trim($raw_title))) {
            $title_err = "Please enter a request title.";
        } else {
            $title = trim($raw_title);
        }
        
        if (empty(trim($raw_type))) {
            $type_err = "Please select a request type.";
        } else {
            $type = trim($raw_type);
        }

        $amount_str = trim($raw_amount);
        if ($amount_str === '') {
            $amount_err = "Please enter an amount.";
            $amount = null;
        } elseif (!is_numeric(str_replace(',', '', $amount_str)) || (float)str_replace(',', '', $amount_str) <= 0) {
            $amount_err = "Please enter a valid amount (e.g., 500.00).";
            $amount = null; // Set to null if invalid
        } else {
            $amount = (float) str_replace(',', '', $amount_str);
        }

        if (empty(trim($raw_description))) {
            $description_err = "Please enter a detailed description.";
        } else {
            $description = trim($raw_description);
        }
        
        // --- 2. Validate File Upload ---
        $file_upload_success = true; // Assume success initially
        $uploaded_file_name = null;
        $uploaded_file_path = null;
        $original_file_name = null;
        
        if (isset($_FILES["supporting_file"]) && is_array($_FILES["supporting_file"])) {
            $fileError = $_FILES["supporting_file"]["error"];
            // Handle INI size error explicitly
            if ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
                $file_err = "File is too large. Max allowed by server is " . ini_get('upload_max_filesize') . ".";
                $file_upload_success = false;
            } elseif ($fileError === UPLOAD_ERR_OK) {
                $original_file_name = basename($_FILES["supporting_file"]["name"]);
                $file_extension = pathinfo($original_file_name, PATHINFO_EXTENSION);
                $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                $max_size = 5 * 1024 * 1024; // 5MB limit in application

                // Check file extension
                if (!in_array(strtolower($file_extension), $allowed_types)) {
                    $file_err = "Invalid file type. Only PDF, images (JPG/PNG), and Word documents are allowed.";
                    $file_upload_success = false;
                }
                
                // Check file size (application limit)
                if ($_FILES["supporting_file"]["size"] > $max_size) {
                    $file_err = "File is too large. Max size is 5MB.";
                    $file_upload_success = false;
                }

                if ($file_upload_success) {
                    // Create a unique file name to prevent overwrites
                    $uploaded_file_name = uniqid("file_") . "." . $file_extension;
                    $uploaded_file_path = $upload_dir . $uploaded_file_name;

                    // Ensure the upload directory exists
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    // Move the uploaded file
                    if (!move_uploaded_file($_FILES["supporting_file"]["tmp_name"], $uploaded_file_path)) {
                        $file_err = "Failed to move uploaded file.";
                        $file_upload_success = false;
                    }
                }
            } elseif ($fileError === UPLOAD_ERR_NO_FILE) {
                // No file uploaded - acceptable
                $file_upload_success = true;
            } else {
                $file_err = "A file was selected but an error occurred during upload (Code: " . $fileError . ").";
                $file_upload_success = false;
            }
        } else {
            // No file field present - continue
            $file_upload_success = true;
        }
        
        // Check input errors before inserting into database
        if (empty($title_err) && empty($type_err) && empty($amount_err) && empty($description_err) && empty($file_err)) {
            
            mysqli_begin_transaction($link);
            $request_id = 0;
            $success = true;

            try {
                // --- 3. Insert Request Details (same as fixed version) ---
                $sql_request = "
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
                
                if ($stmt_request = mysqli_prepare($link, $sql_request)) {
                    // 'isids' -> user_id(i), title(s), type(s), amount(d), description(s)
                    mysqli_stmt_bind_param($stmt_request, "issds", 
                        $param_user_id, 
                        $param_title, 
                        $param_type, 
                        $param_amount, 
                        $param_description
                    );
                    
                    $param_user_id = $_SESSION["user_id"];
                    $param_title = $title;
                    $param_type = $type;
                    $param_amount = $amount;
                    $param_description = $description;
                    
                    if (mysqli_stmt_execute($stmt_request)) {
                        $request_id = mysqli_insert_id($link);
                    } else {
                        $general_err = "Request insertion failed: " . mysqli_error($link);
                        $success = false;
                    }
                    mysqli_stmt_close($stmt_request);
                } else {
                    $general_err = "Request statement failed: " . mysqli_error($link);
                    $success = false;
                }

                // --- 4. Insert File Details if a file was uploaded successfully ---
                if ($success && $request_id > 0 && $uploaded_file_name !== null) {
                    $sql_file = "
                        INSERT INTO files (request_id, file_name, file_path, original_file_name) 
                        VALUES (?, ?, ?, ?)
                    ";
                    
                    if ($stmt_file = mysqli_prepare($link, $sql_file)) {
                        mysqli_stmt_bind_param($stmt_file, "isss", 
                            $request_id, 
                            $uploaded_file_name, 
                            $uploaded_file_path,
                            $original_file_name
                        );
                        
                        if (!mysqli_stmt_execute($stmt_file)) {
                            $general_err .= " File details recording failed: " . mysqli_error($link);
                            $success = false;
                        }
                        mysqli_stmt_close($stmt_file);
                    } else {
                        $general_err .= " File statement failed: " . mysqli_error($link);
                        $success = false;
                    }
                }

            } catch (Exception $e) {
                $general_err = "An unhandled error occurred during submission: " . $e->getMessage();
                $success = false;
            }

            // --- 5. Commit or Rollback Transaction ---
            if ($success) {
                mysqli_commit($link);
                // Redirect to request list page on successful commit
                header("location: request_list.php?success=1");
                exit;
            } else {
                mysqli_rollback($link);
                // If transaction failed and a file was moved, attempt to delete it
                if ($uploaded_file_path && file_exists($uploaded_file_path)) {
                    unlink($uploaded_file_path);
                    $general_err .= " (Uploaded file was cleaned up.)";
                }
            }
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
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .is-invalid { border-color: #ef4444; }
        .invalid-feedback { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="min-h-screen">
    
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
            <p class="text-gray-500">Submitting for: <?php echo htmlspecialchars($_SESSION["org_name"] ?? ''); ?></p>
        </div>

        <?php if (!empty($general_err)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Submission Error</p>
                <p><?php echo $general_err; ?></p>
            </div>
        <?php endif; ?>

        <div class="max-w-3xl mx-auto bg-white p-6 sm:p-10 rounded-xl shadow-2xl">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Request Title (e.g., Annual Sports Fest Budget)</label>
                    <input type="text" name="title" id="title" 
                           class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($title_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                           value="<?php echo htmlspecialchars($title); ?>" required>
                    <span class="invalid-feedback"><?php echo $title_err; ?></span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Request Type</label>
                        <select name="type" id="type" 
                                class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 bg-white <?php echo (!empty($type_err)) ? 'is-invalid' : 'border-gray-300'; ?>" required>
                            <option value="">-- Select Type --</option>
                            <option value="Budget Request" <?php echo ($type == 'Budget Request') ? 'selected' : ''; ?>>Budget Request</option>
                            <option value="Liquidation Report" <?php echo ($type == 'Liquidation Report') ? 'selected' : ''; ?>>Liquidation Report</option>
                            <option value="Reimbursement" <?php echo ($type == 'Reimbursement') ? 'selected' : ''; ?>>Reimbursement</option>
                        </select>
                        <span class="invalid-feedback"><?php echo $type_err; ?></span>
                    </div>

                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Total Amount (₱)</label>
                        <input type="text" name="amount" id="amount" 
                               class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($amount_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                               value="<?php echo htmlspecialchars($amount_str); ?>" placeholder="e.g. 15000.00" required>
                        <span class="invalid-feedback"><?php echo $amount_err; ?></span>
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Detailed Justification / Breakdown</label>
                    <textarea name="description" id="description" rows="6" 
                              class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($description_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                              required><?php echo htmlspecialchars($description); ?></textarea>
                    <span class="invalid-feedback"><?php echo $description_err; ?></span>
                </div>

                <div class="p-5 border border-gray-200 rounded-lg bg-gray-50/70">
                    <label for="supporting_file" class="block text-sm font-medium text-gray-700 mb-2">Supporting Document (e.g., Proposal, Quotation, Receipt)</label>
                    <input type="file" name="supporting_file" id="supporting_file" 
                           class="mt-1 block w-full border border-gray-300 rounded-lg p-2 bg-white text-sm <?php echo (!empty($file_err)) ? 'is-invalid' : ''; ?>">
                    <p class="text-xs text-gray-500 mt-1">Max 5MB. Allowed types: PDF, JPG, PNG, DOC/DOCX.</p>
                    <span class="invalid-feedback"><?php echo $file_err; ?></span>
                </div>

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