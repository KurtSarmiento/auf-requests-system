<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php'; // Path to mPDF's autoloader
require_once "db_config.php"; // Database connection setup (assuming $link is available)

use Mpdf\Mpdf;
use Mpdf\MpdfException;

// Check user authentication and role
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$role = $_SESSION["role"];
$liquidation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($liquidation_id === 0) {
    die("Invalid Liquidation ID.");
}

// --- Helper for Signatures (Function Definition) ---
function get_signature_data($status_key, $date_key, $request, $role, $sig_paths) {
     // Define official names and titles here
    $role_names = [
        'officer' => htmlspecialchars($request['officer_name'] ?? 'Requestor'),
        'adviser' => 'Dr. Ruel Reyes', // Placeholder
        'dean' => 'Engr. Jerrence Taguines', // Placeholder
        'osafa' => 'Mr. Prince Romel Pangilinan',
        'afo' => 'Mr. Paul Baluyut',
    ];

    $name = $role_names[$role] ?? '';
    $sig_img_path = $sig_paths[$role] ?? '';
    
    // Officer (Requestor) logic
    if ($role === 'officer') {
        $date_val = $request['date_submitted'] ?? $request['created_at'] ?? '';
        $date_formatted = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : date('m/d/Y');
        $img_html = '<img src="' . $sig_img_path . '" style="height: 30px; width: auto; margin-top: -5px;">';
        return ['name' => $name, 'date' => $date_formatted, 'img_html' => $img_html]; 
    }

    // Signatory logic
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

// --- 1. Fetch LIQUIDATION Data (Actual Expenses) ---
$sql_liq = "SELECT 
            r.*, 
            u.full_name AS officer_name, 
            o.org_name
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON u.org_id = o.org_id
        WHERE r.request_id = ? AND r.type = 'Liquidation Report'";

$params = [$liquidation_id];
$types = "i";

// Add permission check
if ($role === 'Officer') {
    $sql_liq .= " AND r.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

$stmt_liq = mysqli_prepare($link, $sql_liq);
mysqli_stmt_bind_param($stmt_liq, $types, ...$params);
mysqli_stmt_execute($stmt_liq);
$result_liq = mysqli_stmt_get_result($stmt_liq);
$liq = mysqli_fetch_assoc($result_liq);
mysqli_stmt_close($stmt_liq);


if (!$liq) {
    die("Liquidation Report not found or access denied.");
}

// ----------------------------------------------------------------------
// 🚨 CRITICAL STEP: Fetch the Projected Budget from the Original Request
// ----------------------------------------------------------------------

$original_br_id = $liq['original_request_id'] ?? 0;
$projected_budget = 0.00; // Default Projected Budget

if ($original_br_id > 0) {
    // Select the 'amount' (approved budget) from the original Budget Request
    $sql_br = "SELECT amount FROM requests WHERE request_id = ? AND type = 'Budget Request'";
    
    $stmt_br = mysqli_prepare($link, $sql_br);
    mysqli_stmt_bind_param($stmt_br, "i", $original_br_id);
    mysqli_stmt_execute($stmt_br);
    $result_br = mysqli_stmt_get_result($stmt_br);
    $original_br = mysqli_fetch_assoc($result_br);
    
    if ($original_br) {
        $projected_budget = (float)str_replace(',', '', $original_br['amount'] ?? 0);
    }
    mysqli_stmt_close($stmt_br);
} else {
    // Fallback: If no original request ID is linked, use the 'amount' from the liquidation row itself 
    // (This assumes 'amount' holds the budget received/approved for liquidation)
    $projected_budget = (float)str_replace(',', '', $liq['amount'] ?? 0); 
}

// --- 2. Process Actual Expenses (Liquidation Details) ---

$expense_details = json_decode($liq['budget_details_json'] ?? '[]', true); 
if (!is_array($expense_details)) $expense_details = [];

$total_actual_expenses = 0;
$expense_rows_html = '';

if (!empty($expense_details)) {
    foreach ($expense_details as $item) {
        $desc = htmlspecialchars($item['description'] ?? 'N/A');
        $qty = (float)($item['qty'] ?? 0);
        $unit_cost = (float)str_replace(',', '', $item['cost'] ?? 0);
        $subtotal = $qty * $unit_cost;
        $total_actual_expenses += $subtotal;

        $expense_rows_html .= "
            <tr>
                <td style='padding:5px; border:1px solid #000; text-align:left; width:45%;'>$desc</td>
                <td style='padding:5px; border:1px solid #000; text-align:center; width:15%;'>$qty</td>
                <td style='padding:5px; border:1px solid #000; text-align:right; width:20%;'>₱" . number_format($unit_cost, 2) . "</td>
                <td style='padding:5px; border:1px solid #000; text-align:right; width:20%;'>₱" . number_format($subtotal, 2) . "</td>
            </tr>
        ";
    }
} else {
    $expense_rows_html = "<tr><td colspan='4' style='text-align:center; border:1px solid #000; padding:10px;'>No detailed expenses submitted for liquidation.</td></tr>";
}

// --- 3. Calculate Final Summary ---
$remaining_balance = $projected_budget - $total_actual_expenses;

// --- 4. Format Dates ---
$activity_date_display = !empty($liq['activity_date']) ? date('M d, Y', strtotime($liq['activity_date'])) : 'N/A';
$date_submitted_display = !empty($liq['date_submitted'] ?? $liq['created_at']) ? date('M d, Y', strtotime($liq['date_submitted'] ?? $liq['created_at'])) : 'N/A';


// --- Signature Data and Paths ---
$sig_paths = [
    'officer' => 'pics/sign.png',
    'adviser' => 'pics/sign.png', 
    'dean' => 'pics/sign.png',
    'osafa' => 'pics/sign.png',
    'afo' => 'pics/sign.png',
];

$sig_officer = get_signature_data(null, null, $liq, 'officer', $sig_paths);
$sig_adviser = get_signature_data('adviser_status', 'adviser_decision_date', $liq, 'adviser', $sig_paths);
$sig_dean = get_signature_data('dean_status', 'dean_decision_date', $liq, 'dean', $sig_paths);
$sig_osafa = get_signature_data('osafa_status', 'osafa_decision_date', $liq, 'osafa', $sig_paths);
$sig_afo = get_signature_data('afo_status', 'afo_decision_date', $liq, 'afo', $sig_paths);


mysqli_close($link);


// --- CSS (Adjusted for the Summary Table) ---
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
    
    .signature-section { margin-top: 20px; } 
    .signature-box {
        text-align: center;
        margin-top: 30px;
        position: relative;
    }
    
    .signature-line {
        border-bottom: 1px solid #000;
        padding-top: 5px;
        font-weight: bold;
    }
    .sig-label {
        font-size: 8pt; /* Reduced font size for better fit */
        color: #555;
        margin-top: 2px;
    }
    .note { font-size: 7pt; margin-top: 15px; border: 1px solid #000; padding: 5px; }

    /* Summary Table Style */
    .summary-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .summary-table td { border: 1px solid #000; padding: 6px; font-size: 10pt; }
    .summary-table .label { background-color: #f0f0f0; font-weight: bold; width: 60%; }
    .summary-table .amount { text-align: right; font-weight: bold; width: 40%; }
';


$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Liquidation Report #' . htmlspecialchars($liquidation_id) . '</title>
</head>
<body>
    <style>' . $css . '</style>
    
    <div class="header">
        <img src="pics/logo.png" style="width: 60px; float: left; margin-right: -35px; overflow: visible;" />
        <p><strong>ANGELES UNIVERSITY FOUNDATION</strong><br>Angeles City</p>
        <h2>ACCOUNTING AND FINANCE OFFICE</h2>
    </div>

    <div class="title">
        LIQUIDATION REPORT FORM
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Requesting Organization: <span class="line" style="min-width: 250px;">' . htmlspecialchars($liq['org_name'] ?? 'N/A') . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Date Submitted: <span class="line" style="min-width: 150px;">' . htmlspecialchars($date_submitted_display) . '</span>
        </div>
    </div>
    
    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Activity/Project Title: <span class="line" style="min-width: 250px;">' . htmlspecialchars($liq['title'] ?? 'N/A') . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Date of Activity: <span class="line" style="min-width: 150px;">' . htmlspecialchars($activity_date_display) . '</span>
        </div>
    </div>

    <div class="field-row">
        Purpose/Justification: 
        <div style="border: 1px solid #000; min-height: 50px; padding: 5px; margin-top: 5px; font-size: 8pt;">
            ' . nl2br(htmlspecialchars($liq['description'] ?? 'No justification provided.')) . '
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
                        ' . $expense_rows_html . '
                        <tr>
                            <td colspan="3" style="text-align: right; font-weight: bold; border-top: 1px solid #000; border-right: 1px solid #000; padding: 5px;">SUB-TOTAL EXPENSES:</td>
                            <td style="font-weight: bold; border-top: 1px solid #000; text-align: right; padding: 5px;">₱' . number_format($total_actual_expenses, 2) . '</td>
                        </tr>
                    </tbody>
                </table>
          </div>
    
    <div style="clear: both;"></div>
    
    <p style="font-weight: bold; margin-top: 15px; margin-bottom: 2px;">FINANCIAL SUMMARY:</p>
    <table class="summary-table">
        <tr>
            <td class="label">PROJECTED/APPROVED BUDGET:</td>
            <td class="amount">₱' . number_format($projected_budget, 2) . '</td>
        </tr>
        <tr>
            <td class="label">ACTUAL EXPENSES:</td>
            <td class="amount">₱' . number_format($total_actual_expenses, 2) . '</td>
        </tr>
        <tr style="background-color: ' . ($remaining_balance >= 0 ? '#ddffdd' : '#ffdddd') . ';">
            <td class="label">EXCESS/DEFICIT BUDGET:</td>
            <td class="amount">
                ' . ($remaining_balance >= 0 ? '₱' . number_format($remaining_balance, 2) : '(DEFICIT) ₱' . number_format(abs($remaining_balance), 2)) . '
            </td>
        </tr>
    </table>
    
    <div style="clear: both;"></div>

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

            <p style="margin-top: 15px; margin-bottom: 0;">5.) Liquidation Checked by:</p>
            <div class="signature-box">
                ' . $sig_afo['img_html'] . '
                <div class="signature-line">' . $sig_afo['name'] . '</div>
                <div class="sig-label">AFO Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_afo['date'] . '</span></p>
        </div>
        <div style="clear: both;"></div>
    </div>
    
    <div style="clear: both;"></div>
    
    <div class="note">
        **NOTE:** Attach all supporting documents such as official receipts, invoices, and proof of payments to this form. Please return this request to the Dean/Unit Head, **one (1) copy for AFO and one (1) copy for the Organization**.
    </div>

</body>
</html>
';

// --- mPDF Generation ---
try {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'Legal']);
    $mpdf->SetTitle("Liquidation Report #{$liquidation_id}");
    $mpdf->WriteHTML($html);
    
    // Output the PDF in the browser
    $filename = "Liquidation_Report_{$liquidation_id}.pdf";
    $mpdf->Output($filename, 'I'); 
    
} catch (MpdfException $e) {
    echo "mPDF Error: " . $e->getMessage();
}

?>