<?php
// request_create.php

// Initialize the session
session_start();
 
// Check if the user is logged in and is an Officer
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";
// Assuming layout_template.php contains start_page() and end_page() or similar functions
require_once "layout_template.php"; 
// New helper files
require_once "helpers/file_handler.php"; 

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

// Variables for file handling (These are only initialized here, not populated from the new handler)
$upload_dir = __DIR__ . "/uploads/"; 

// --- GET AND VALIDATE REQUEST TYPE FROM URL ---
$allowed_financial_types = ['Budget Request', 'Liquidation Report', 'Reimbursement'];
$allowed_all_types = array_merge($allowed_financial_types, ['Venue Request']);

// Map the short URL parameter to the full database type name
$type_map = [
    'Budget' => 'Budget Request',
    'Liquidation' => 'Liquidation Report',
    'Reimbursement' => 'Reimbursement',
    'Venue' => 'Venue Request'
];
$default_type = 'Budget Request';
$type_param = '';

if (isset($_GET['type'])) {
    $type_param = trim($_GET['type']);
    $mapped_type = $type_map[$type_param] ?? $type_param;
    
    if (in_array($mapped_type, $allowed_all_types)) {
        $type = $mapped_type;
    } else {
        $type = $default_type; 
        $type_err = "Invalid request type specified. Defaulting to {$default_type}.";
    }
} else {
    $type = $default_type;
}

// *** CRITICAL REDIRECTION FOR VENUE REQUESTS ***
if ($type === 'Venue Request') {
    header("location: request_venue.php" . (!empty($type_param) ? "?type=" . urlencode($type_param) : ""));
    exit;
}

$approved_brs = [];
$original_request_id_err = "";

if ($type === 'Liquidation Report' || $type === 'Reimbursement') {
    
    // 1. Get the list of all the officer's *Approved* Budget Requests (type='Budget Request').
    // 2. You might need to add a check here to ensure they haven't been fully liquidated already.
    //    For simplicity, we'll fetch all approved ones for now.
    $sql_br_list = "
        SELECT 
            request_id, 
            title, 
            amount 
        FROM requests 
        WHERE user_id = ? 
        AND type = 'Budget Request' 
        AND final_status = 'Budget Available' 
        ORDER BY activity_date DESC
    ";

    if ($stmt_br_list = mysqli_prepare($link, $sql_br_list)) {
        $param_user_id = $_SESSION["user_id"];
        mysqli_stmt_bind_param($stmt_br_list, "i", $param_user_id);
        mysqli_stmt_execute($stmt_br_list);
        $result_br_list = mysqli_stmt_get_result($stmt_br_list);

        while ($row = mysqli_fetch_assoc($result_br_list)) {
            $approved_brs[] = $row;
        }
        mysqli_stmt_close($stmt_br_list);
    }
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Detect obvious server-side limits exceeded (POST was discarded)
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    $postMax = phpSizeToBytes(ini_get('post_max_size'));

    if ($contentLength > 0 && $contentLength > $postMax) {
        $general_err = "Upload failed: the submitted data exceeds the server limit (post_max_size). Try a smaller file or contact the admin to increase post_max_size/upload_max_filesize.";
    }

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
        $raw_activity_date = $_POST["activity_date"] ?? '';
        $budget_items = [];
        $budget_details_json = null;

        if (empty(trim($raw_title))) {
            $title_err = "Please enter a request title.";
        } else {
            $title = trim($raw_title);
        }

        // Handle Budget Breakdown
        if (isset($_POST['item_description']) && is_array($_POST['item_description'])) {
            $total_calculated_amount = 0.00;
            $has_valid_items = false;

            foreach ($_POST['item_description'] as $index => $description_item) {
                $qty = (int)($_POST['item_qty'][$index] ?? 0);
                $cost = (float)str_replace(',', '', $_POST['item_cost'][$index] ?? 0.00);
                $description_item = trim($description_item);

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
                if ($type === 'Budget Request') {
                    $general_err = "Please enter at least one valid budget item with a description, quantity, and cost.";
                }
            } else {
                $user_entered_amount = (float) str_replace(',', '', $raw_amount);
                // Note: The JavaScript in the form enforces this, but a server-side check is still good.
                if (abs($user_entered_amount - $total_calculated_amount) > 0.01) {
                    // You might set a warning/error here, but for now, we trust the calculated total implicitly in the DB insert.
                }
                $budget_details_json = json_encode($budget_items);
            }
        } else {
            if ($type === 'Budget Request') {
                $general_err = "Budget request must include itemized breakdown.";
            }
        }
        
        $raw_original_request_id = 0;
        $param_original_request_id = null; // Default to NULL for non-linked requests

        if ($type === 'Liquidation Report' || $type === 'Reimbursement') {
            $raw_original_request_id = (int)($_POST["original_request_id"] ?? 0);
            
            if ($raw_original_request_id <= 0) {
                $original_request_id_err = "Please link this report to an approved Budget Request.";
            } else {
                // Ensure the selected ID is actually one of the approved requests
                $valid_link = false;
                foreach ($approved_brs as $br) {
                    if ((int)$br['request_id'] === $raw_original_request_id) {
                        $valid_link = true;
                        break;
                    }
                }

                if (!$valid_link) {
                    $original_request_id_err = "Invalid Budget Request selected for linking.";
                } else {
                    $param_original_request_id = $raw_original_request_id;
                }
            }
        }

        // Use the hidden field value for submission type
        if (empty(trim($raw_type)) || !in_array(trim($raw_type), $allowed_financial_types)) {
            $type_err = "Invalid or missing request type submitted. Please return to the request selection page or use the correct form.";
        } else {
            $type = trim($raw_type);
        }

        $amount_str = trim($raw_amount);
        if ($amount_str === '') {
            $amount_err = "Please enter the total amount.";
        } elseif (!is_numeric(str_replace(',', '', $amount_str)) || (float)str_replace(',', '', $amount_str) <= 0) {
            $amount_err = "Amount must be a positive number.";
        } else {
            $amount = (float) str_replace(',', '', $amount_str); 
        }

        if (empty(trim($raw_description))) {
            $description_err = "Please enter a detailed justification or breakdown.";
        } else {
            $description = trim($raw_description);
        }

        if (empty(trim($raw_activity_date))) {
            $activity_date_err = "Please select a date for the activity implementation.";
        } else {
            $activity_date = trim($raw_activity_date);
            if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $activity_date)) {
                   $activity_date_err = "Invalid date format. Use YYYY-MM-DD.";
            }
        }
        
        // --- 2. Validate and Handle File Uploads (Multi-File Handler) ---
        // This is the correct way to use the multi-file handler
        $upload_results = handleMultipleFileUpload($_FILES["supporting_files"] ?? null, $upload_dir, ['image/jpeg']);
        $file_err = $upload_results['error'];
        $successful_uploads = $upload_results['successful_uploads']; // This is the array we use for insertion

        // REMOVED: $uploaded_file_name, $uploaded_file_path, $original_file_name assignments 
        // that were causing the warnings. They are no longer needed globally.

        // Required File Check
        $file_required = ($type === 'Liquidation Report' || $type === 'Reimbursement');
        
        if ($file_required && empty($successful_uploads)) {
            $file_err = (empty($file_err) ? "" : $file_err . " ") . "At least one supporting JPEG file is required for {$type}.";
        }
        
        // Check input errors before inserting into database
        if (empty($title_err) && empty($type_err) && empty($amount_err) && empty($description_err) && empty($file_err) && empty($general_err) && empty($activity_date_err) && empty($original_request_id_err)) {
            
            mysqli_begin_transaction($link);
            $request_id = 0;
            $success = true;

            try {
                // --- 3. Insert Request Details ---
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
                        notification_status,
                        original_request_id
                    ) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending', 'Pending', 'Pending', 'Pending', 'Pending', 'Pending', ?)
                ";
                
                if ($stmt_request = mysqli_prepare($link, $sql_request)) {
                    mysqli_stmt_bind_param($stmt_request, "issdsssi", 
                        $param_user_id, 
                        $param_title, 
                        $param_type, 
                        $param_amount, 
                        $param_description,
                        $param_budget_json,
                        $param_activity_date,
                        $param_original_request_id
                    );
                    
                    $param_user_id = $_SESSION["user_id"];
                    $param_title = $title;
                    $param_type = $type;
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
                // --- 4. Insert File Details for ALL successfully uploaded files ---
                if ($success && $request_id > 0 && !empty($successful_uploads)) {
                    $sql_file = "
                        INSERT INTO files (request_id, file_name, file_path, original_file_name) 
                        VALUES (?, ?, ?, ?)
                    ";
                    
                    if ($stmt_file = mysqli_prepare($link, $sql_file)) {
                        // Loop through each successfully uploaded file
                        foreach ($successful_uploads as $file_data) {
                            mysqli_stmt_bind_param($stmt_file, "isss", 
                                $request_id, 
                                $file_data['uploaded_file_name'], 
                                $file_data['uploaded_file_path'],
                                $file_data['original_file_name']
                            );
                            
                            if (!mysqli_stmt_execute($stmt_file)) {
                                $general_err .= " File details recording failed for {$file_data['original_file_name']}: " . mysqli_error($link);
                                $success = false;
                                break; // Stop and rollback if one file record fails
                            }
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
                header("location: request_list.php?success=1");
                exit;
            } else {
                mysqli_rollback($link);
                // Clean up the uploaded file if the database transaction failed
                if (!empty($successful_uploads)) {
                    $cleanup_count = 0;
                    foreach ($successful_uploads as $file_data) {
                        $path_to_clean = $file_data['uploaded_file_path'];
                        if ($path_to_clean && file_exists($path_to_clean)) {
                            unlink($path_to_clean);
                            $cleanup_count++;
                        }
                    }
                    if ($cleanup_count > 0) {
                        $general_err .= " ({$cleanup_count} uploaded file(s) were cleaned up.)";
                    }
                }
            }
        }
    }

    // Close connection (only if form was submitted and we didn't redirect)
    mysqli_close($link);
}

// Start HTML output
start_page("Submit " . htmlspecialchars($type), $_SESSION['role'], $_SESSION['full_name']);

// Include the separate form file
include "forms/financial_request_form.php";

// Assuming end_page() closes the HTML tags if your template requires it.
// If not, you'd add:
// echo "</body></html>";
?>