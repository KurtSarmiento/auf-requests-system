<?php
session_start();
$cliPdfMode = defined('AUF_PDF_CLI_MODE') && AUF_PDF_CLI_MODE === true;
require_once __DIR__ . '/vendor/autoload.php';
require_once "db_config.php";

use Mpdf\Mpdf;
use Mpdf\MpdfException;

if (!$cliPdfMode && (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true)) {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"] ?? 0;
$role = $_SESSION["role"] ?? '';
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($request_id === 0) {
    die("Invalid request ID.");
}

$sql = "SELECT 
            r.*, 
            r.activity_date,
            r.budget_details_json,
            r.original_request_id,
            u.full_name AS officer_name,
            o.org_name,
            original.title AS original_title,
            original.amount AS original_amount
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON u.org_id = o.org_id
        LEFT JOIN requests original ON original.request_id = r.original_request_id
        WHERE r.request_id = ? AND r.type = 'Reimbursement'";

$params = [$request_id];
$types = "i";

if ($role === 'Officer' && !$cliPdfMode) {
    $sql .= " AND r.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

$stmt = mysqli_prepare($link, $sql);
if (!$stmt) {
    // Note: mysqli_error is often sensitive, use generic message in production
    die("Error preparing statement: " . mysqli_error($link));
}
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// NOTE: mysqli_close($link) is moved to AFTER the main logic (below)
if (!$request) {
    mysqli_close($link); // Close connection before dying
    die("Reimbursement Request not found or you don't have permission to view it.");
}

// ----------------------------------------------------------------------
// ✅ NEW: Fetch Attached Files
// ----------------------------------------------------------------------
$attached_files = [];
$files_sql = "
    SELECT file_path, file_name, original_file_name
    FROM files
    WHERE request_id = ?
";
$files_stmt = mysqli_prepare($link, $files_sql);
if ($files_stmt) {
    mysqli_stmt_bind_param($files_stmt, "i", $request_id);
    mysqli_stmt_execute($files_stmt);
    $files_result = mysqli_stmt_get_result($files_stmt);

    while ($file = mysqli_fetch_assoc($files_result)) {
        // Resolve actual path: prefer stored file_path, fallback to uploads/<file_name>
        $real_path = null;
        $paths_to_try = [];
        if (!empty($file['file_path'])) $paths_to_try[] = $file['file_path'];
        // Assuming 'uploads' folder is in the same directory as this script
        if (!empty($file['file_name'])) $paths_to_try[] = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file['file_name'];

        foreach ($paths_to_try as $p) {
            // Use the local file path structure for Mpdf to access it
            if (file_exists($p) && is_readable($p)) {
                $real_path = $p;
                break;
            }
        }

        if ($real_path) {
            $file['real_path'] = $real_path;
            $attached_files[] = $file;
        }
    }
    mysqli_stmt_close($files_stmt);
}
// ----------------------------------------------------------------------


/**
 * Build signature block data similar to other request PDFs.
 */
function get_signature_data($status_key, $date_key, $request, $role, $sig_paths) {
    $role_names = [
        'officer' => htmlspecialchars($request['officer_name'] ?? 'Requestor'),
        'adviser' => 'Dr. Ruel Reyes',
        'dean' => 'Engr. Jerrence Taguines',
        'osafa' => 'Mr. Prince Romel Pangilinan',
        'afo' => 'Mr. Paul Baluyut',
    ];

    $name = $role_names[$role] ?? '';
    $sig_img_path = $sig_paths[$role] ?? '';

    if ($role === 'officer') {
        $date_val = $request['date_submitted'] ?? '';
        $date_formatted = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : date('m/d/Y');
        $img_html = '<img src="' . $sig_img_path . '" style="height: 30px; width: auto; margin-top: -5px;">';
        return ['name' => $name, 'date' => $date_formatted, 'img_html' => $img_html];
    }

    $status = strtolower($request[$status_key] ?? '');
    $is_approved = ($status === 'approved');

    if ($is_approved) {
        $date_val = $request[$date_key] ?? '';
        $date_formatted = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : '';
        $img_html = '<img src="' . $sig_img_path . '" style="height: 30px; width: auto; margin-top: -5px;">';
        return ['name' => $name, 'date' => $date_formatted, 'img_html' => $img_html];
    }

    $empty_line_html = '<div style="height: 25px;"></div>';
    return ['name' => '', 'date' => '', 'img_html' => $empty_line_html];
}

$sig_paths = [
    'officer' => 'pics/sign.png',
    'adviser' => 'pics/sign.png',
    'dean' => 'pics/sign.png',
    'osafa' => 'pics/sign.png',
    'afo' => 'pics/sign.png',
];

$sig_officer = get_signature_data(null, null, $request, 'officer', $sig_paths);
$sig_adviser = get_signature_data('adviser_status', 'adviser_decision_date', $request, 'adviser', $sig_paths);
$sig_dean = get_signature_data('dean_status', 'dean_decision_date', $request, 'dean', $sig_paths);
$sig_osafa = get_signature_data('osafa_status', 'osafa_decision_date', $request, 'osafa', $sig_paths);
$sig_afo = get_signature_data('afo_status', 'afo_decision_date', $request, 'afo', $sig_paths);

$budget_items = [];
if (!empty($request['budget_details_json'])) {
    $budget_items = json_decode($request['budget_details_json'], true);
    if (!is_array($budget_items)) {
        $budget_items = [];
    }
}

$budget_html = '';
$total_expenses = 0.0;
if (!empty($budget_items)) {
    foreach ($budget_items as $item) {
        $desc = htmlspecialchars($item['description'] ?? 'N/A');
        $qty = (float)($item['qty'] ?? 0);
        $unit_raw = str_replace(',', '', (string)($item['cost'] ?? 0));
        $unit_cost = (float)$unit_raw;
        $line_total = $qty * $unit_cost;
        $total_expenses += $line_total;

        $budget_html .= "
            <tr>
                <td style='border: 1px solid #000; padding: 5px; text-align:left;'>$desc</td>
                <td style='border: 1px solid #000; padding: 5px; text-align:center;'>" . ($qty > 0 ? rtrim(rtrim(number_format($qty, 2), '0'), '.') : '0') . "</td>
                <td style='border: 1px solid #000; padding: 5px; text-align:right;'>&#8369; " . number_format($unit_cost, 2) . "</td>
                <td style='border: 1px solid #000; padding: 5px; text-align:right;'>&#8369; " . number_format($line_total, 2) . "</td>
            </tr>
        ";
    }
}

// Fallback logic for total expenses if budget_details_json was empty but 'amount' field has data
if ($total_expenses <= 0 && isset($request['amount'])) {
    $total_expenses = (float)str_replace(',', '', (string)$request['amount']);
}

if ($budget_html === '') {
    $budget_html = "<tr><td colspan='4' style='border: 1px solid #000; padding: 8px; text-align:center;'>No reimbursement items specified.</td></tr>";
}

$activity_date = !empty($request['activity_date']) ? date('M d, Y', strtotime($request['activity_date'])) : 'N/A';
$date_submitted = !empty($request['date_submitted']) ? date('M d, Y', strtotime($request['date_submitted'])) : 'N/A';
$original_title = !empty($request['original_title']) ? htmlspecialchars($request['original_title']) : 'N/A';
$original_amount = (float)str_replace(',', '', (string)($request['original_amount'] ?? 0));
$reim_amount = (float)str_replace(',', '', (string)($request['amount'] ?? 0)); // This is the requested reimbursement amount
$original_amount_display = number_format($original_amount, 2);
$reim_amount_display = number_format($reim_amount, 2);
$total_expenses_display = number_format($total_expenses, 2);
$remaining_balance = $original_amount - $total_expenses;

$purpose_html = nl2br(htmlspecialchars($request['description'] ?? ''));

$css = '
    body { font-family: Arial, sans-serif; font-size: 9pt; margin: 30px; }
    .header { text-align: center; margin-bottom: 10px; position: relative; width: 100%; height: 80px; }
    .header img { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 50px; }
    .header h2 { font-size: 13pt; margin: 0; }
    .header p { font-size: 10pt; margin: 0; }

    .title { font-size: 11pt; font-weight: bold; text-align: center; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0; margin-bottom: 10px; }
    .field-row { display: block; margin-bottom: 5px; }
    .line { border-bottom: 1px solid #000; display: inline-block; min-width: 80px; padding: 0 5px; font-size: 9pt; height: 12pt; text-align: left; }
    .two-cols::after { content: ""; display: table; clear: both; }
    .left-col { width: 48%; float: left; }
    .right-col { width: 48%; float: right; }

    .budget-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px; }
    .budget-table th, .budget-table td { border: 1px solid #000; padding: 5px; text-align: left; }
    .budget-table th { background-color: #f0f0f0; text-align: center; }

    .summary-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .summary-table td { border: 1px solid #000; padding: 6px; font-size: 10pt; }
    .summary-table .label { background-color: #f0f0f0; font-weight: bold; width: 60%; }
    .summary-table .amount { text-align: right; font-weight: bold; width: 40%; }

    .signature-section { margin-top: 20px; }
    .signature-box { text-align: center; margin-top: 30px; position: relative; }
    .signature-line { border-bottom: 1px solid #000; padding-top: 5px; font-weight: bold; }
    .sig-label { font-size: 8pt; color: #555; margin-top: 2px; }
    .note { font-size: 7pt; margin-top: 15px; border: 1px solid #000; padding: 5px; }
';

$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Reimbursement Request #' . htmlspecialchars($request_id) . '</title>
</head>
<body>
    <style>' . $css . '</style>

    <div class="header">
        <img src="pics/logo.png" style="width: 60px; float: left; margin-right: -35px; overflow: visible;" />
        <p><strong>ANGELES UNIVERSITY FOUNDATION</strong><br>Angeles City</p>
        <h2>ACCOUNTING AND FINANCE OFFICE</h2>
    </div>

    <div class="title">
        REIMBURSEMENT REQUEST FORM
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Requesting Organization: <span class="line" style="min-width: 250px;">' . htmlspecialchars($request['org_name']) . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Date Filed: <span class="line" style="min-width: 150px;">' . htmlspecialchars($date_submitted) . '</span>
        </div>
        <div style="clear: both;"></div>
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Activity/Project Title: <span class="line" style="min-width: 250px;">' . htmlspecialchars($request['title']) . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Date of Activity: <span class="line" style="min-width: 150px;">' . htmlspecialchars($activity_date) . '</span>
        </div>
        <div style="clear: both;"></div>
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Linked Budget Request: <span class="line" style="min-width: 250px;">' . $original_title . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Approved Budget Amount: <span class="line" style="min-width: 150px;">&#8369; ' . $original_amount_display . '</span>
        </div>
        <div style="clear: both;"></div>
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Total Receipted Expenses: <span class="line" style="min-width: 250px;">&#8369; ' . $total_expenses_display . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Reimbursement Amount Requested: <span class="line" style="min-width: 150px;">&#8369; ' . $reim_amount_display . '</span>
        </div>
        <div style="clear: both;"></div>
    </div>

    <div class="field-row">
        Purpose / Justification:
        <div style="border: 1px solid #000; min-height: 50px; padding: 5px; margin-top: 5px; font-size: 8pt;">
            ' . $purpose_html . '
        </div>
    </div>

    <p style="font-weight: bold; margin-top: 10px; margin-bottom: 2px;">DETAILED EXPENSES:</p>
    <div style="border: 1px solid #000; padding: 0; font-size: 8pt;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background-color: #f0f0f0;">
                    <th style="border: 1px solid #000; padding: 5px; width: 45%; text-align: left;">Particulars / Description</th>
                    <th style="border: 1px solid #000; padding: 5px; width: 15%;">Qty.</th>
                    <th style="border: 1px solid #000; padding: 5px; width: 20%;">Unit Cost</th>
                    <th style="border: 1px solid #000; padding: 5px; width: 20%;">Total Cost</th>
                </tr>
            </thead>
            <tbody>
                ' . $budget_html . '
                <tr>
                    <td colspan="3" style="text-align: right; font-weight: bold; border-top: 1px solid #000; border-right: 1px solid #000; padding: 5px;">TOTAL REIMBURSEMENT:</td>
                    <td style="font-weight: bold; border-top: 1px solid #000; text-align: right; padding: 5px;">&#8369; ' . $total_expenses_display . '</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="clear: both;"></div>

    <p style="font-weight: bold; margin-top: 15px; margin-bottom: 2px;">FINANCIAL SUMMARY:</p>
    <table class="summary-table">
        <tr>
            <td class="label">APPROVED BUDGET (FROM LINKED REQUEST):</td>
            <td class="amount">&#8369; ' . $original_amount_display . '</td>
        </tr>
        <tr>
            <td class="label">TOTAL RECEIPTED EXPENSES:</td>
            <td class="amount">&#8369; ' . $total_expenses_display . '</td>
        </tr>
        <tr style="background-color: ' . ($remaining_balance >= 0 ? '#ddffdd' : '#ffdddd') . ';">
            <td class="label">EXCESS / DEFICIT:</td>
            <td class="amount">
                ' . ($remaining_balance >= 0 ? '&#8369; ' . number_format($remaining_balance, 2) : '(DEFICIT) &#8369; ' . number_format(abs($remaining_balance), 2)) . '
            </td>
        </tr>
    </table>

    <div class="signature-section two-cols">
        <div class="left-col">
            <p style="margin-bottom: 0;">1.) Prepared by:</p>
            <div class="signature-box">
                ' . $sig_officer['img_html'] . '
                <div class="signature-line">' . $sig_officer['name'] . '</div>
                <div class="sig-label">Requesting Officer Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_officer['date'] . '</span></p>

            <p style="margin-top: 15px; margin-bottom: 0;">2.) Checked by:</p>
            <div class="signature-box">
                ' . $sig_adviser['img_html'] . '
                <div class="signature-line">' . $sig_adviser['name'] . '</div>
                <div class="sig-label">Adviser Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_adviser['date'] . '</span></p>

            <p style="margin-top: 15px; margin-bottom: 0;">3.) Recommended / Endorsed by:</p>
            <div class="signature-box">
                ' . $sig_dean['img_html'] . '
                <div class="signature-line">' . $sig_dean['name'] . '</div>
                <div class="sig-label">Dean/Head Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_dean['date'] . '</span></p>
        </div>

        <div class="right-col">
            <p style="margin-bottom: 0;">4.) Cleared by:</p>
            <div class="signature-box">
                ' . $sig_osafa['img_html'] . '
                <div class="signature-line">' . $sig_osafa['name'] . '</div>
                <div class="sig-label">Director, OSAFA Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_osafa['date'] . '</span></p>

            <p style="margin-top: 15px; margin-bottom: 0;">5.) Reimbursement Checked by:</p>
            <div class="signature-box">
                ' . $sig_afo['img_html'] . '
                <div class="signature-line">' . $sig_afo['name'] . '</div>
                <div class="sig-label">AFO Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_afo['date'] . '</span></p>
        </div>
        <div style="clear: both;"></div>
    </div>

    <div class="note">
        **NOTE:** Attach all supporting documents (official receipts, invoices, proof of payments) to validate this reimbursement. Provide one copy for AFO and one copy for the Organization.
    </div>

</body>
</html>
';

try {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'Legal']);
    $mpdf->SetTitle("Reimbursement Request #{$request_id}");
    $mpdf->WriteHTML($html);

    // ----------------------------------------------------------------------
    // Logic to add attachments to subsequent pages
    // ----------------------------------------------------------------------
    if (!empty($attached_files)) {
        $file_counter = 1;
        foreach ($attached_files as $file) {
            // Check if the file is an image using mime type (requires fileinfo extension) or extension
            $mime = @mime_content_type($file['real_path']);
            $is_image = str_starts_with($mime, 'image/') || in_array(strtolower(pathinfo($file['real_path'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif']);
            
            if ($is_image) {
                
                // Add a new page for each image attachment
                $mpdf->AddPage();
                
                $attachment_html = '
                    <div style="text-align: center; margin: 0 auto; font-family: Arial, sans-serif; padding-top: 10px;">
                        <h3 style="font-size: 14pt;">Attachment ' . $file_counter . ': ' . htmlspecialchars($file['original_file_name']) . '</h3>
                        <p style="font-size: 10pt; margin-top: -10px;">Reimbursement Request ID: ' . $request_id . '</p>
                        
                        <img 
                            src="' . $file['real_path'] . '" 
                            style="max-width: 100%; max-height: 90vh; display: block; margin: 15px auto; border: 1px solid #ccc; box-sizing: border-box;" 
                        />
                    </div>
                ';
                
                $mpdf->WriteHTML($attachment_html);
                $file_counter++;
            }
        }
    }
    // ----------------------------------------------------------------------


    $filename = "Reimbursement_Request_{$request_id}.pdf";
    if ($cliPdfMode) {
        echo $mpdf->Output($filename, 'S');
    } else {
        $mpdf->Output($filename, 'I');
    }
} catch (MpdfException $e) {
    echo "mPDF Error: " . $e->getMessage();
}

// Final database connection close (after all logic, including error checks)
mysqli_close($link);

?>