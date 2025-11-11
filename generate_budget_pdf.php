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
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($request_id === 0) {
    die("Invalid request ID.");
}

// --- Fetch Request Data ---
$sql = "SELECT 
            r.*, 
            r.activity_date,
            r.budget_details_json,
            u.full_name AS officer_name, 
            o.org_name
        FROM requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON u.org_id = o.org_id
        WHERE r.request_id = ? AND r.type = 'Budget Request'"; // 🔑 Ensure it's a Budget Request

$params = [$request_id];
$types = "i";

// Add permission check
if ($role === 'Officer') {
    $sql .= " AND r.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
// --- Budget Data Preparation ---
$budget_items = [];
$total_amount = $request['amount'] ?? 0;

// --- Budget Data Preparation ---
$budget_items = [];
// FIX 1: Clean $request['amount'] before setting $total_amount
$total_amount_raw = $request['amount'] ?? 0;
$total_amount = (float)str_replace(',', '', $total_amount_raw); 
$clean_total_amount = (float)str_replace(',', '', $total_amount);


if (!empty($request['budget_details_json'])) {
    $budget_items = json_decode($request['budget_details_json'], true);
    if (!is_array($budget_items)) {
        $budget_items = [];
    }
}

$budget_html = '';
if (!empty($budget_items)) {
    foreach ($budget_items as $item) {
        $item_description = htmlspecialchars($item['description'] ?? 'N/A');
        $item_qty = htmlspecialchars($item['qty'] ?? 1);
        
        // FIX 2: Clean the cost value before use/format
        $raw_item_cost = $item['cost'] ?? 0;
        $clean_item_cost = (float)str_replace(',', '', $raw_item_cost);
        $item_cost_formatted = number_format($clean_item_cost, 2);
        
        // Calculate total for this item using the CLEAN value
        $item_total_calc = ($item_qty * $clean_item_cost);
        $item_total_formatted = number_format($item_total_calc, 2);
        
        $budget_html .= '
            <tr>
                <td style="text-align: left; padding: 4px 5px; border-right: 1px solid #000;">' . $item_description . '</td>
                <td style="text-align: center; padding: 4px 5px; border-right: 1px solid #000;">' . $item_qty . '</td>
                <td style="text-align: right; padding: 4px 5px; border-right: 1px solid #000;">₱' . $item_cost_formatted . '</td>
                <td style="text-align: right; padding: 4px 5px;">₱' . $item_total_formatted . '</td>
            </tr>
        ';
    }
}

// ... after all data is fetched into $request ...

// Format the Activity Date
$activity_date_display = '';
if (!empty($request['activity_date'])) {
    // Format to a readable date (e.g., March 15, 2025)
    $activity_date_display = date('M d, Y', strtotime($request['activity_date']));
}

$date_available_display = '';

// Check if the date_budget_available field is set AND is not empty (i.e., not NULL or '0000-00-00 00:00:00')
if (!empty($request['date_budget_available'])) {
    $date_available_display = date('M d, Y', strtotime($request['date_budget_available']));
}

// ... rest of the PHP logic ...

mysqli_stmt_close($stmt);
mysqli_close($link);

if (!$request) {
    die("Budget Request not found or you don't have permission to view it.");
}


// --- Signature/Status Helper Function ---
/**
 * Retrieves signature data (name, date, digital signature image HTML) based on approval status.
 * @param string $status_key The database column name for the status (e.g., 'dean_status').
 * @param string $date_key The database column name for the decision date (e.g., 'dean_decision_date').
 * @param array $request The fetched request data array.
 * @param string $role The role identifier ('officer', 'dean', 'osafa', etc.).
 * @param array $sig_paths Map of role => signature image paths.
 * @return array Contains 'name', 'date', and 'img_html'.
 */
function get_signature_data($status_key, $date_key, $request, $role, $sig_paths) {
    
    // 🔹 Define official names and titles here
    $role_names = [
        'officer' => htmlspecialchars($request['officer_name'] ?? 'Requestor'),
        'adviser' => 'Dr. Ruel Reyes', // Replace with actual logic/name
        'dean' => 'Engr. Jerrence Taguines', // Replace with actual logic/name
        'osafa' => 'Mr. Prince Romel Pangilinan',
        'afo' => 'Mr. Paul Baluyut',
    ];

    $name = $role_names[$role] ?? '';
    $sig_img_path = $sig_paths[$role] ?? '';
    
    // Officer (Requestor) logic is slightly different: Always 'submitted'
    if ($role === 'officer') {
        $date_val = $request['date_submitted'] ?? '';
        $date_formatted = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : date('m/d/Y');
        // Requestor typically doesn't show a digital signature, just the printed name
        $img_html = '<img src="' . $sig_img_path . '" style="height: 30px; width: auto; margin-top: -5px;">';
        return ['name' => $name, 'date' => $date_formatted, 'img_html' => $img_html]; 
    }

    // Signatory logic
    $status = strtolower($request[$status_key] ?? '');
    $is_approved = ($status === 'approved');

    if ($is_approved) {
        $date_val = $request[$date_key] ?? '';
        $date_formatted = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : '';
        
        // Digital Signature HTML
        $img_html = '<img src="' . $sig_img_path . '" style="height: 30px; width: auto; margin-top: -5px;">';
        
        return ['name' => $name, 'date' => $date_formatted, 'img_html' => $img_html];
    }

    // Pending/Rejected state
    $empty_line_html = '<div style="height: 25px;"></div>';
    return ['name' => '', 'date' => '', 'img_html' => $empty_line_html];
}

// --- Signature Data and Paths (MUST BE UPDATED) ---
// NOTE: **REPLACE THESE PLACEHOLDER PATHS** with the actual paths to the signature images
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

// --- Financial Data Formatting ---
$total_amount = number_format($request['amount'] ?? 0, 2);
$expense_details = json_decode($request['details'] ?? '[]', true); // Assuming 'details' column stores budget breakdown

// --- PDF HTML Content Generation ---
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
    .budget-table td.center { text-align: center; }
    .budget-table td.right { text-align: right; }

    .signature-section { margin-top: 20px; } 
    .signature-box {
    text-align: center;
    margin-top: 30px;
    position: relative;
    }
    
    .signature-container { 
        height: 35px; /* Space for the signature image */
        text-align: center;
    }
    .signature-line {
    border-bottom: 1px solid #000;
    padding-top: 5px;
    font-weight: bold;
    }
    .sig-label {
    font-size: 10pt;
    color: #555;
    margin-top: 2px;
    }

    .signature-img {
    display: block;
    margin: 0 auto;
    margin-top: -45px;
    width: 100px;
    opacity: 0.8;
    }
    .date-line { border-bottom: 1px solid #000; display: inline-block; width: 15%; margin-left: 10px; font-size: 8pt; }
    
    .note { font-size: 7pt; margin-top: 15px; border: 1px solid #000; padding: 5px; }
';

$expense_rows = '';
$item_count = 0;
if (!empty($expense_details)) {
    foreach ($expense_details as $item) {
        $item_count++;
        $expense_rows .= '
            <tr>
                <td class="center">' . $item_count . '</td>
                <td>' . htmlspecialchars($item['description']) . '</td>
                <td class="right">' . number_format($item['cost'] ?? 0, 2) . '</td>
                <td class="center">' . htmlspecialchars($item['quantity']) . '</td>
                <td class="right">' . number_format(($item['cost'] ?? 0) * ($item['quantity'] ?? 0), 2) . '</td>
            </tr>
        ';
    }
} else {
    $expense_rows .= '<tr><td colspan="5" class="center">No detailed budget breakdown provided.</td></tr>';
}


$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Budget Request Letter #' . htmlspecialchars($request_id) . '</title>
</head>
<body>
    <style>' . $css . '</style>
    
    <div class="header">
        <img src="pics/logo.png" style="width: 60px; float: left; margin-right: -35px; overflow: visible;" />
        <p><strong>ANGELES UNIVERSITY FOUNDATION</strong><br>Angeles City</p>
        <h2>ACCOUNTING AND FINANCE OFFICE</h2>
    </div>

    <div class="title">
        BUDGET REQUEST FORM
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Requesting Organization: <span class="line" style="min-width: 250px;">' . htmlspecialchars($request['org_name']) . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Date of Request: <span class="line" style="min-width: 150px;">' . date('M d, Y', strtotime($request['date_submitted'])) . '</span>
        </div>
    </div>
    
    <div class="field-row two-cols">
        <div class="left-col" style="width: 55%;">
            Activity/Project Title: <span class="line" style="min-width: 250px;">' . htmlspecialchars($request['title']) . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Date of Activity: <span class="line" style="min-width: 150px;">' . htmlspecialchars($activity_date_display) . '</span>
        </div>
    </div>

    <div class="field-row">
        Purpose: 
        <div style="border: 1px solid #000; min-height: 50px; padding: 5px; margin-top: 5px;">
            ' . nl2br(htmlspecialchars($request['description'] ?? '')) . '
        </div>
    </div>
    
    <p style="font-weight: bold; margin-top: 10px; margin-bottom: 2px;">DETAILED BUDGET BREAKDOWN:</p>
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
                            ' . ($budget_html ?: '<tr><td colspan="4" style="text-align: center; padding: 10px; border: 1px solid #000;">No budget items specified.</td></tr>') . '
                            <tr>
                                <td colspan="3" style="text-align: right; font-weight: bold; border-top: 1px solid #000; border-right: 1px solid #000; padding: 5px;">GRAND TOTAL:</td>
                                <td style="font-weight: bold; border-top: 1px solid #000; text-align: right; padding: 5px;">₱' . number_format($clean_total_amount, 2) . '</td>
                            </tr>
                      </tbody>
                </table>
          </div>
      
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

            <p style="margin-top: 15px; margin-bottom: 0;">5.) Budget Availability Checked:</p>
            <div class="signature-box">
                ' . $sig_afo['img_html'] . '
                <div class="signature-line">' . $sig_afo['name'] . '</div>
                <div class="sig-label">AFO Signature over Printed Name</div>
            </div>
            <p style="font-size: 7pt; display: inline-block; margin-top: 3px;">Date: <span class="line" style="min-width: 70px;">' . $sig_afo['date'] . '</span></p>
            
            <div style="border: 1px solid #000; padding: 5px; margin-top: 15px;">
                <p style="margin: 0; font-size: 8pt; font-weight: bold;">BUDGET STATUS (For AFO Use)</p>
                <p style="margin: 3px 0; font-size: 8pt;">
                    [ ' . (($request['final_status'] ?? '') === 'Budget Available' ? 'X' : '&nbsp;&nbsp;') . ' ] Budget Available 
                    <span style="margin-left: 20px;">[ ' . (($request['final_status'] ?? '') === 'Budget Processing' ? 'X' : '&nbsp;&nbsp;') . ' ] Budget Processing</span>
                </p>
                <p style="margin: 3px 0; font-size: 8pt;">
                    Date Available: <span class="line" style="min-width: 150px;">' . htmlspecialchars($date_available_display) . '</span>
                </p>
            </div>
        </div>
        <div style="clear: both;"></div>
    </div>
    
    <div class="note">
        **NOTE:** Budget Requests must be submitted with a **Duly Approved** Activity Proposal. Please return this request to the Dean/Unit Head, **one (1) copy for AFO and one (1) copy for the Organization**.
    </div>

</body>
</html>
';

// --- mPDF Generation ---
try {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'Legal']);
    $mpdf->SetTitle("Budget Request #{$request_id}");
    $mpdf->WriteHTML($html);
    
    // Output the PDF in the browser
    $filename = "Budget_Request_{$request_id}.pdf";
    $mpdf->Output($filename, 'I'); 
    
} catch (MpdfException $e) {
    echo "mPDF Error: " . $e->getMessage();
}

?>