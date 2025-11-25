<?php
if (!defined('AUF_PDF_CLI_MODE')) {
    define('AUF_PDF_CLI_MODE', false);
}
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

// --- Fetch Request Data (Modified to include signatory names/dates) ---
// NOTE: Ensure your 'venue_requests' table has columns like *_status_name and *_status_date
$sql = "SELECT 
            r.*, 
            u.full_name AS officer_name, 
            o.org_name
        FROM venue_requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON r.org_id = o.org_id
        WHERE r.venue_request_id = ?";

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
mysqli_stmt_close($stmt);

if (!$request) {
    die("Request not found or you don't have permission to view it.");
}

// --- Data Preparation ---
$venue_display = (!empty(trim($request['venue_other_name'])) ? 
    htmlspecialchars(trim($request['venue_other_name'])) : 
    htmlspecialchars($request['venue_name']));

// Equipment List (Prepared for Checkbox logic)
$equipment_data = json_decode($request['equipment_details'], true) ?? [];
$other_materials = $equipment_data['other'] ?? '';

// Checkbox Logic for FACILITIES NEEDED (Based on the image)
$main_venue_name = $request['venue_name'] ?? '';

$venue_map_col1 = [
    'The Struggle Square', 'St. Cecilia\'s Auditorium', 'Silungan', 'AUF Quadrangle', 
    'Classroom(s)', 'AUF Boardroom', 'Cabalen Hall'
];
$venue_map_col2 = [
    'PS 108', 'St. John Paul II - A (PS-307)', 'St. John Paul II - B (PS-308)', 
    'PS - 517', 'Others (Specify)'
];

$venue_col1 = '';
$venue_col2 = '';

// Helper to generate a single venue checkbox line
function get_venue_line($venue_text, $request_venue, $other_venue_detail) {
    $is_checked = ($request_venue === $venue_text || 
                   ($venue_text === 'Others (Specify)' && !empty($other_venue_detail)));
    $check = $is_checked ? 'X' : '&nbsp;&nbsp;';
    
    $line_content = htmlspecialchars($venue_text);
    if ($venue_text === 'Others (Specify)') {
        $line_content .= ': <span class="line" style="min-width: 120px;">' . htmlspecialchars($other_venue_detail) . '</span>';
    }
    return "<p style='margin: 1px 0;'>" . $line_content . "</p>"; // Removed [X] for cleaner alignment
}

// Populate venue columns
foreach ($venue_map_col1 as $v) {
    $venue_col1 .= get_venue_line($v, $main_venue_name, $request['venue_other_name']);
}
foreach ($venue_map_col2 as $v) {
    $venue_col2 .= get_venue_line($v, $main_venue_name, $request['venue_other_name']);
}


// Equipment Checkbox Logic
$eq = $equipment_data; // Use alias for shorter access
$tables_check = ($eq['tables'] ?? 0) > 0 ? 'X' : '&nbsp;&nbsp;';
$chairs_check = ($eq['chairs'] ?? 0) > 0 ? 'X' : '&nbsp;&nbsp;';
$flags_check = ($eq['flags'] ?? 0) > 0 ? 'X' : '&nbsp;&nbsp;';
$rostrum_check = ($eq['rostrum'] ?? 0) > 0 ? 'X' : '&nbsp;&nbsp;';
$housekeepers_check = ($eq['housekeepers'] ?? 0) > 0 ? 'X' : '&nbsp;&nbsp;';
$other_equip_check = !empty($other_materials) ? 'X' : '&nbsp;&nbsp;';

// Helper function for signature lines
function get_signature_line($name_key, $date_key, $request) {
    $name = $request[$name_key] ?? '';
    $date_val = $request[$date_key] ?? '';
    
    $signature_content = !empty($name) ? htmlspecialchars($name) : '';
    $date_content = !empty($date_val) && strtotime($date_val) ? date('m/d/Y', strtotime($date_val)) : '';
    
    return [
        'name' => $signature_content,
        'date' => $date_content
    ];
}

function show_if_approved($role, $status_key, $date_key, $request) {
    $status = strtolower($request[$status_key] ?? '');
    $is_approved = ($status === 'approved');

    // 🔹 Define official names here once
    $role_names = [
        'officer' => htmlspecialchars($request['officer_name'] ?? 'Requestor'),
        'dean' => 'Engr. Jerrence Taguines',
        'admin' => 'Ms. Maria Garcia',
        'osafa' => 'Mr. Prince Romel Pangilinan',
        'cfdo' => 'Engr. Jerrence Taguines',
        'afo' => 'Mr. Paul Baluyut',
        'vp_acad' => 'Dr. Olga Angelinetta Tulabut',
        'vp_admin' => 'Mr. Larry Carbonel'
    ];

    $name = $role_names[$role] ?? '';
    $sig_img = 'pics/sign.png'; // ✅ same file for all

    if ($role === 'officer') {
        $date_val = $request['date_submitted'] ?? '';
        $date = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : date('m/d/Y');
        return [
            'approved' => true,
            'name' => $name,
            'date' => $date,
            'img' => $sig_img
        ];
    }

    $date_val = $is_approved ? ($request[$date_key] ?? '') : '';
    $date = (!empty($date_val) && strtotime($date_val)) ? date('m/d/Y', strtotime($date_val)) : '';

    return [
        'approved' => $is_approved,
        'name' => $is_approved ? $name : '',
        'date' => $date,
        'img' => $is_approved ? $sig_img : ''
    ];
}




// --- PDF HTML Content Generation ---

// Basic CSS for PDF readability and form simulation
$css = '
    body { font-family: Arial, sans-serif; font-size: 9pt; margin: 30px; }
    .header { text-align: center; margin-bottom: 10px; position: relative; width: 100%; height: 80px; }
    .header img { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 50px; }
    .header h2 { font-size: 13pt; margin: 0; }
    .header p { font-size: 10pt; margin: 0; }
    .title { font-size: 11pt; font-weight: bold; text-align: center; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 0; margin-bottom: 10px; }
    .field-row { display: block; margin-bottom: 5px; } /* Reduced spacing */
    .field-row p { margin: 0; padding: 0; }
    .line { border-bottom: 1px solid #000; display: inline-block; min-width: 80px; padding: 0 5px; font-size: 9pt; height: 12pt; text-align: left; }
    .two-cols::after { content: ""; display: table; clear: both; }
    .left-col { width: 55%; float: left; } /* Adjusted width for better fit */
    .right-col { width: 45%; float: right; } /* Adjusted width for better fit */
    
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
    
    .approval-table { width: 100%; border-collapse: collapse; font-size: 8pt; margin-top: 5px; } /* Smaller font for table */
    .approval-table th, .approval-table td { border: 1px solid #000; padding: 3px; text-align: center; } /* Reduced padding */
    .approval-table th { background-color: #f0f0f0; }
';

$sig_requestor = show_if_approved('officer', 'officer_status', 'date_submitted', $request);
$sig_dean = show_if_approved('dean', 'dean_status', 'dean_decision_date', $request);
$sig_admin = show_if_approved('admin', 'admin_services_status', 'admin_services_decision_date', $request);
$sig_osafa = show_if_approved('osafa', 'osafa_status', 'osafa_decision_date', $request);
$sig_cfdo = show_if_approved('cfdo', 'cfdo_status', 'cfdo_decision_date', $request);
$sig_afo = show_if_approved('afo', 'afo_status', 'afo_decision_date', $request);
$sig_vp_acad = show_if_approved('vp_acad', 'vp_acad_status', 'vp_acad_decision_date', $request);
$sig_vp_admin = show_if_approved('vp_admin', 'vp_admin_status', 'vp_admin_decision_date', $request);

$html = '
<!DOCTYPE html>
<html>
<head>
    <title>Venue Request Letter #'.htmlspecialchars($request_id).'</title>
</head>
<body>
    <style>' . $css . '</style>
    
    <div class="header">
        <img src="pics/logo.png" style="width: 60px; float: left; margin-right: -35px; overflow: visible;" />
        <p><strong>ANGELES UNIVERSITY FOUNDATION</strong><br>Angeles City</p>
        <h2>OFFICE OF THE ADMINISTRATIVE SERVICES</h2>
    </div>

    <div class="title">
        REQUEST FOR USE OF FACILITIES / EQUIPMENT SERVICES
    </div>

    <div class="field-row two-cols">
        <div class="left-col" style="width: 60%;">
            Requesting Organization: <span class="line" style="min-width: 300px;">' . htmlspecialchars($request['org_name']) . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            Control no. <span class="line" style="min-width: 150px;">' . htmlspecialchars($request['venue_request_id']) . '</span>
        </div>
    </div>
    
    <div class="field-row two-cols">
        <div class="left-col" style="width: 60%;">
            Activity: <span class="line" style="min-width: 300px;">' . htmlspecialchars($request['title']) . '</span>
        </div>
        <div class="right-col" style="width: 40%;">
            College/Unit: <span class="line" style="min-width: 150px;">CEA</span> </div>
    </div>

    <div class="field-row">
        Nature: [' . ($request['is_fund_raising'] ? 'X' : '&nbsp;&nbsp;') . '] Fund Raising &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; [' . (!$request['is_fund_raising'] ? 'X' : '&nbsp;&nbsp;') . '] Non-Fund Raising 
    </div>

    <div class="field-row">
        Date(s): <span class="line" style="min-width: 100px;">' . date('F j, Y', strtotime($request['activity_date'])) . '</span> 
        Time: (Start) <span class="line" style="min-width: 50px;">' . date('h:i A', strtotime($request['start_time'])) . '</span> 
        (End) <span class="line" style="min-width: 50px;">' . date('h:i A', strtotime($request['end_time'])) . '</span>
    </div>
    
    <div class="two-cols" style="margin-top: 10px;">
        <div class="left-col" style="width: 50%; padding-right: 5px;">
            <p style="font-weight: bold; text-decoration: underline; margin-bottom: 2px;">FACILITY NEEDED:</p>
            <div style="border: 1px solid #000; padding: 3px; font-size: 8pt;">
                <div style="width: 48%; float: left;">
                    ' . $venue_col1 . '
                </div>
                <div style="width: 48%; float: right;">
                    ' . $venue_col2 . '
                </div>
                <div style="clear: both;"></div>
            </div>
            <p style="margin-top: 10px; font-size: 8pt;"><strong>Activity Description:</strong><br>
            <div style="border: 1px solid #000; min-height: 40px; padding: 3px; font-size: 8pt;">
                ' . nl2br(htmlspecialchars($request['description'])) . '
            </div>
        </div>
        
        <div class="right-col" style="width: 45%;">
            <p style="font-weight: bold; text-decoration: underline; margin-bottom: 2px;">EQUIPMENTS / MANPOWER NEEDED:</p>
            <div style="border: 1px solid #000; padding: 3px; font-size: 8pt;">
                <div style="width: 48%; float: left;">
                    <p>[' . $tables_check . '] Tables <span class="line" style="min-width: 30px;">' . ($eq['tables'] ?? '') . '</span></p>
                    <p>[' . $chairs_check . '] Chairs <span class="line" style="min-width: 30px;">' . ($eq['chairs'] ?? '') . '</span></p>
                    <p>[' . $flags_check . '] Flags <span class="line" style="min-width: 30px;">' . ($eq['flags'] ?? '') . '</span></p>
                </div>
                <div style="width: 48%; float: right;">
                    <p>[' . $rostrum_check . '] Rostrum <span class="line" style="min-width: 30px;">' . ($eq['rostrum'] ?? '') . '</span></p>
                    <p>[' . $housekeepers_check . '] Housekeepers <span class="line" style="min-width: 30px;">' . ($eq['housekeepers'] ?? '') . '</span></p>
                    <p>[' . $other_equip_check . '] Others (Specify) <span class="line" style="min-width: 30px;"></span></p>
                </div>
                <div style="clear: both;"></div>
                <p style="margin-top: 5px;">Other Specs: <span class="line" style="min-width: 150px; padding-left: 0;">' . nl2br(htmlspecialchars($other_materials)) . '</span></p>
            </div>
        </div>
        <div style="clear: both;"></div>
    </div>
    
    <div class="signature-section two-cols">
        <div class="left-col" style="width: 55%; font-size: 8pt;">
            <p style="margin-bottom: 5px;">1.) Requested by:</p>
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_requestor['approved'] ? '<img src="' . $sig_requestor['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_requestor['name'] . '</div>
                <div class="sig-label">Requestor Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_requestor['date'] . '</span></span>

            <p style="margin-top: 10px; margin-bottom: 5px;">2.) Endorsed by:</p>
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_dean['approved'] ? '<img src="' . $sig_dean['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_dean['name'] . '</div>
                <div class="sig-label">Dean/Head Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_dean['date'] . '</span></span>

            
            <p style="margin-top: 10px; margin-bottom: 5px;">3.) Request cleared by:</p>
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_admin['approved'] ? '<img src="' . $sig_admin['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_admin['name'] . '</div>
                <div class="sig-label">Director, Admin Services Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_admin['date'] . '</span></span>

            <p style="margin-top: 10px; margin-bottom: 5px;">4.) Activity cleared by: (for student-related activity)</p>
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_osafa['approved'] ? '<img src="' . $sig_osafa['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_osafa['name'] . '</div>
                <div class="sig-label">Director, OSAFA Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_osafa['date'] . '</span></span>
            
            <p style="margin-top: 10px; margin-bottom: 5px;">5.) Request for Admin Support cleared by:</p>
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_cfdo['approved'] ? '<img src="' . $sig_cfdo['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_cfdo['name'] . '</div>
                <div class="sig-label">Director, CFDO Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_cfdo['date'] . '</span></span>

        </div>

        <div class="right-col" style="width: 45%; font-size: 8pt;">
            <p style="margin-bottom: 5px;">6.) Fees assessed / checked by:</p>
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_afo['approved'] ? '<img src="' . $sig_afo['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_afo['name'] . '</div>
                <div class="sig-label">AFO Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_afo['date'] . '</span></span>
            
            <table class="approval-table" style="margin-top: 5px;">
                <thead>
                    <tr>
                        <th style="width: 40%;">Remark(s)</th>
                        <th style="width: 30%;">With charge</th>
                        <th style="width: 30%;">No charge</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Amount Paid</td>
                        <td></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>Date:</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            
            <p style="margin-top: 10px; margin-bottom: 5px;">7.) [' . ($request['final_status'] === 'Approved' ? 'X' : '&nbsp;&nbsp;') . '] Approved &nbsp;&nbsp;&nbsp;&nbsp; [' . ($request['final_status'] === 'Rejected' ? 'X' : '&nbsp;&nbsp;') . '] Disapproved</p>
            
            <div class="signature-box" style="width: 90%;">
                ' . ($sig_vp_acad['approved'] ? '<img src="' . $sig_vp_acad['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_vp_acad['name'] . '</div>
                <div class="sig-label">VP for Academic Affairs Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_vp_acad['date'] . '</span></span>

            <div class="signature-box" style="width: 90%;">
                ' . ($sig_vp_admin['approved'] ? '<img src="' . $sig_vp_admin['img'] . '" class="signature-img" />' : '') . '
                <div class="signature-line">' . $sig_vp_admin['name'] . '</div>
                <div class="sig-label">VP for Admin Signature over Printed Name</div>
            </div>
            <span style="font-size: 7pt;">Date <span class="date-line">' . $sig_vp_admin['date'] . '</span></span>
        </div>
        <div style="clear: both;"></div>
    </div>
    
    <div style="border: 1px solid #000; padding: 3px; margin-top: 10px; font-size: 7pt;">
        **Note**: A separate request form is needed for OUR technical assistance (e.g. Audio, Photo, Video coverage, TV, LCD, Backdrop, etc.)<br>
        * Please return this request to the Dean/Unit Head, **one (1) copy for Administrative Services**<br>
        * Must be filed at least **one (1) week** in advance.
    </div>
    <p style="font-size: 6pt; float: right; margin-top: 2px;">AUF-Form-AS-22<br>January 25, 2023 Rev.3</p>

</body>
</html>
';

// --- mPDF Generation ---
try {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'Legal']);
    $mpdf->SetTitle("Venue Request #{$request_id}");
    $mpdf->WriteHTML($html);
    
    // Output the PDF in the browser
    $filename = "Venue_Request_{$request_id}.pdf";
    if (defined('AUF_PDF_CLI_MODE') && AUF_PDF_CLI_MODE === true) {
        echo $mpdf->Output($filename, 'S'); // Return as string
    } else {
        $mpdf->Output($filename, 'I');      // Show in browser
    }
    
} catch (MpdfException $e) {
    echo $e->getMessage();
}