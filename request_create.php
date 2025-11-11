<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";
require_once "layout_template.php";

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
$title = $type = $amount_str = $description = $budget_details_json = $activity_date = "";
$title_err = $type_err = $amount_err = $description_err = $file_err = $general_err = $activity_date_err = "";

// Variables for file handling
$uploaded_file_name = null; // The unique filename on the server
$original_file_name = null; // The file's original name
$uploaded_file_path = null; // The full path on the server
$file_upload_success = false;

// Directory for file uploads (Must be created manually)
$upload_dir = __DIR__ . "/uploads/"; 

// --- MODIFIED LOGIC: GET AND VALIDATE REQUEST TYPE FROM URL ---
$allowed_financial_types = ['Budget Request', 'Liquidation Report', 'Reimbursement'];
$allowed_all_types = array_merge($allowed_financial_types, ['Venue Request']); // Include the new type

// Map the short URL parameter to the full database type name
$type_map = [
    'Budget' => 'Budget Request',
    'Liquidation' => 'Liquidation Report',
    'Reimbursement' => 'Reimbursement',
    'Venue' => 'Venue Request' // ADDED: Map for venue requests
];
$default_type = 'Budget Request';
$type_param = ''; // Initialize variable to hold the raw URL parameter

if (isset($_GET['type'])) {
    $type_param = trim($_GET['type']);
    $mapped_type = $type_map[$type_param] ?? $type_param;
    
    if (in_array($mapped_type, $allowed_all_types)) { // Check against all types
        $type = $mapped_type; // Set $type for form display
    } else {
        // If an invalid type is specified, default and show error
        $type = $default_type; 
        $type_err = "Invalid request type specified. Defaulting to {$default_type}.";
    }
} else {
    // If no parameter is provided, default
    $type = $default_type;
}

// *** CRITICAL REDIRECTION FOR VENUE REQUESTS ***
if ($type === 'Venue Request') {
    // If the user attempts to load the venue request on this page, redirect to the correct form.
    // Use the raw parameter in the redirection to maintain the URL structure.
    header("location: request_venue.php" . (!empty($type_param) ? "?type=" . urlencode($type_param) : ""));
    exit;
}

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
        $budget_items = [];
        $budget_details_json = null;

        if (empty(trim($raw_title))) {
            $title_err = "Please enter a request title.";
        } else {
            $title = trim($raw_title);
        }

        if (isset($_POST['item_description']) && is_array($_POST['item_description'])) {
            $total_calculated_amount = 0.00;
            $has_valid_items = false;

            foreach ($_POST['item_description'] as $index => $description_item) {
                $qty = (int)($_POST['item_qty'][$index] ?? 0);
                $cost = (float)str_replace(',', '', $_POST['item_cost'][$index] ?? 0.00);
                $description_item = trim($description_item);

                // Simple validation: must have a description, positive quantity, and cost.
                if (!empty($description_item) && $qty > 0 && $cost >= 0) {
                    $item_total = $qty * $cost;
                    $total_calculated_amount += $item_total;
                    $has_valid_items = true;
                    
                    $budget_items[] = [
                        'description' => $description_item,
                        'qty' => $qty,
                        'cost' => $cost,
                        'total' => $item_total
                    ];
                }
            }
            
            if (!$has_valid_items) {
                // Only set an error if the request type REQUIRES a breakdown (like Budget Request)
                if ($type === 'Budget Request') {
                    $general_err = "Please enter at least one valid budget item with a description, quantity, and cost.";
                }
            } else {
                // Check if the calculated total matches the user-entered total (optional but recommended)
                $user_entered_amount = (float) str_replace(',', '', $raw_amount);
                if (abs($user_entered_amount - $total_calculated_amount) > 0.01) {
                    // You can make this an error or just a warning, let's make it a warning.
                    // For simplicity, we'll let the user's amount override, but warn them.
                    // A better system would force the form total to match the calculated total.
                    error_log("Budget breakdown total mismatch: User entered $user_entered_amount, calculated $total_calculated_amount");
                }
                $budget_details_json = json_encode($budget_items);
            }
        } else {
            // Check if the request type REQUIRES a breakdown
            if ($type === 'Budget Request') {
                $general_err = "Budget request must include itemized breakdown.";
            }
        }
        
        // Use the hidden field value for submission type
        // MODIFIED: Check against the specific financial types
        if (empty(trim($raw_type)) || !in_array(trim($raw_type), $allowed_financial_types)) {
            $type_err = "Invalid or missing request type submitted. Please return to the request selection page or use the correct form.";
        } else {
            $type = trim($raw_type); // IMPORTANT: Update $type for database binding
        }

        $amount_str = trim($raw_amount);
        if ($amount_str === '') {
            // ... error handling ...
        } elseif (!is_numeric(str_replace(',', '', $amount_str)) || (float)str_replace(',', '', $amount_str) <= 0) {
            // ... error handling ...
        } else {
            // THIS LINE IS CRUCIAL: It should store a clean float
            $amount = (float) str_replace(',', '', $amount_str); 
        }

        if (empty(trim($raw_description))) {
            $description_err = "Please enter a detailed description.";
        } else {
            $description = trim($raw_description);
        }
        
        // --- 2. Validate File Upload (No change needed here) ---
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
                $allowed_file_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                $max_size = 5 * 1024 * 1024; // 5MB limit in application

                // Check file extension
                if (!in_array(strtolower($file_extension), $allowed_file_extensions)) {
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
                // --- 3. Insert Request Details ---
                // The requests table is used for all financial requests
                $sql_request = "
                    INSERT INTO requests (
                        user_id, 
                        title, 
                        type, 
                        amount, 
                        description, 
                        budget_details_json,
                        activity_date,
                        date_submitted,
                        adviser_status,
                        dean_status,
                        osafa_status,
                        afo_status,
                        final_status,
                        notification_status
                    ) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending', 'Pending', 'Pending', 'Pending', 'Pending', 'Pending')
                ";
                
                if ($stmt_request = mysqli_prepare($link, $sql_request)) {
                    // 'issds' -> user_id(i), title(s), type(s), amount(d), description(s)
                    mysqli_stmt_bind_param($stmt_request, "issdsss", 
                        $param_user_id, 
                        $param_title, 
                        $param_type, 
                        $param_amount, 
                        $param_description,
                        $param_budget_json,
                        $param_activity_date
                    );
                    
                    $param_user_id = $_SESSION["user_id"];
                    $param_title = $title;
                    $param_type = $type; // Use the validated $type (e.g., 'Budget Request')
                    $param_amount = $amount;
                    $param_description = $description;
                    $param_budget_json = $budget_details_json;
                    $param_activity_date = $activity_date;
                    
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

    // Close connection (only if form was submitted and we didn't redirect)
    mysqli_close($link);
}
start_page("New Venue Request", $_SESSION['role'], $_SESSION['full_name']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit <?php echo htmlspecialchars($type); ?> | AUF System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .is-invalid { border-color: #ef4444; }
        .invalid-feedback { color: #ef4444; font-size: 0.875rem; margin-top: 0.25rem; }
    </style>
</head>
<body class="min-h-screen">
    <div class="container mx-auto p-4 sm:p-8">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h2 class="text-3xl font-extrabold text-gray-800">Submit <?php echo htmlspecialchars($type); ?></h2>
            <p class="text-gray-500">Submitting for: <?php echo htmlspecialchars($_SESSION["org_name"] ?? ''); ?></p>
        </div>

        <?php if (!empty($general_err)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Submission Error</p>
                <p><?php echo $general_err; ?></p>
            </div>
        <?php endif; ?>

        <div class="max-w-3xl mx-auto bg-white p-6 sm:p-10 rounded-xl shadow-2xl">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . (!empty($type_param) ? '?type=' . htmlspecialchars($type_param) : ''); ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
                <?php if (!empty($type_err)): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 p-3 rounded-lg">
                        <p class="text-sm font-medium">Error: <?php echo $type_err; ?></p>
                    </div>
                <?php endif; ?>

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Request Title (e.g., Annual Sports Fest Budget)</label>
                    <input type="text" name="title" id="title" 
                            class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($title_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                            value="<?php echo htmlspecialchars($title); ?>" required>
                    <span class="invalid-feedback"><?php echo $title_err; ?></span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Request Type (Selected)</label>
                        <p class="mt-1 block w-full px-4 py-2 bg-gray-100 text-gray-600 rounded-lg shadow-sm border border-gray-300 font-semibold">
                            <?php echo htmlspecialchars($type); ?>
                        </p>
                    </div>

                    <div>
                        <label for="amount" class="block text-sm font-medium text-gray-700">Total Amount (₱)</label>
                        <input type="text" name="amount" id="amount" 
                               class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($amount_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                               value="<?php echo htmlspecialchars($amount_str); ?>" placeholder="e.g. 15000.00" required>
                        <span class="invalid-feedback"><?php echo $amount_err; ?></span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="activity_date" class="block text-sm font-medium text-gray-700">Date of Activity Implementation</label>
                            <input type="date" name="activity_date" id="activity_date" 
                                    class="mt-1 block w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($activity_date_err)) ? 'is-invalid' : 'border-gray-300'; ?>" 
                                    value="<?php echo htmlspecialchars($activity_date); ?>" required>
                            <span class="invalid-feedback"><?php echo $activity_date_err; ?></span>
                        </div>
                    </div>

                    <div id="budget-breakdown-section" class="space-y-4 pt-4 border-t border-gray-200">
                        <h3 class="text-xl font-bold text-gray-800">Budget Itemized Breakdown</h3>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-5/12">Description</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-1/12">Qty</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-2/12">Unit Cost (₱)</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-2/12">Total (₱)</th>
                                    <th class="w-1/12"></th>
                                </tr>
                            </thead>
                            <tbody id="budget-items-container" class="bg-white divide-y divide-gray-200">
                                </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="4" class="px-3 py-2 text-right text-sm font-bold text-gray-700">Calculated Total:</td>
                                    <td class="px-3 py-2 text-left text-sm font-bold text-gray-800" id="calculated-total">₱0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        <button type="button" id="add-item-btn" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            + Add Item
                        </button>
                        <p class="text-xs text-gray-500 mt-1">
                            **NOTE:** The Total Amount field above will be automatically calculated or should match the sum of this breakdown.
                        </p>
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
                    <p class="text-xs text-gray-500 mt-1">Max 5MB. Allowed types: PDF, JPG, PNG, DOC/DOCX. (Optional)</p>
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
    // ... just before the </body> tag ...

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('budget-items-container');
            const addButton = document.getElementById('add-item-btn');
            const totalAmountInput = document.getElementById('amount');
            const calculatedTotalDisplay = document.getElementById('calculated-total');

            function formatCurrency(value) {
                return '₱' + parseFloat(value).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            }

            function calculateTotal() {
                let grandTotal = 0;
                const rows = container.querySelectorAll('tr');
                
                rows.forEach(row => {
                    const qtyInput = row.querySelector('[name="item_qty[]"]');
                    const costInput = row.querySelector('[name="item_cost[]"]');
                    const totalCell = row.querySelector('.item-total');

                    let qty = parseInt(qtyInput.value) || 0;
                    let cost = parseFloat(costInput.value.replace(/,/g, '')) || 0.00;

                    let itemTotal = qty * cost;
                    grandTotal += itemTotal;
                    
                    totalCell.textContent = formatCurrency(itemTotal);
                });

                calculatedTotalDisplay.textContent = formatCurrency(grandTotal);
                
                // Auto-update the main form's Total Amount field
                totalAmountInput.value = grandTotal.toFixed(2);
                
                // Re-apply currency formatting on the main input field for better UX (optional)
                totalAmountInput.addEventListener('blur', function() {
                    let value = parseFloat(this.value.replace(/,/g, '')) || 0;
                    this.value = value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
                });
            }

            function createItemRow(description = '', qty = 1, unit = '', cost = 0.00) {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-100 transition duration-100';
                row.innerHTML = `
                    <td class="px-3 py-2 whitespace-nowrap">
                        <input type="text" name="item_description[]" value="${description}" class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g. A4 Paper Ream" required>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <input type="number" name="item_qty[]" value="${qty}" min="1" class="item-qty w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" required onchange="calculateTotal()" onkeyup="calculateTotal()">
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <input type="text" name="item_cost[]" value="${cost.toFixed(2)}" class="item-cost w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="0.00" required onchange="calculateTotal()" onkeyup="calculateTotal()">
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap item-total text-sm font-medium text-gray-900">${formatCurrency(qty * cost)}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-right text-sm font-medium">
                        <button type="button" class="remove-item-btn text-red-600 hover:text-red-900" title="Remove Item">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4m-2 4h4m-4 0h-4"></path></svg>
                        </button>
                    </td>
                `;
                
                // Add event listeners to the new row's cost input for real-time calculation and currency formatting
                row.querySelector('.item-cost').addEventListener('blur', function() {
                    let value = parseFloat(this.value.replace(/,/g, '')) || 0;
                    this.value = value.toFixed(2);
                    calculateTotal();
                });

                // Add remove functionality
                row.querySelector('.remove-item-btn').addEventListener('click', function() {
                    row.remove();
                    calculateTotal();
                });

                container.appendChild(row);
                calculateTotal(); // Recalculate total after adding
            }

            // Event listener for the "Add Item" button
            addButton.addEventListener('click', () => createItemRow());
            
            // Initial setup: Add one blank row when the page loads
            createItemRow();
            
            // Initial call to set the total to 0.00
            calculateTotal();
        });
    </script>
</body>
</html>