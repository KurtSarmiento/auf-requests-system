<?php
// helpers/pdf_mailer.php

define('AUF_PDF_CLI_MODE', true);   // bypasses session check

/**
 * Generates the budget PDF and returns an attachment array ready for PHPMailer
 * @param mysqli $link        DB connection (needed by generate_budget_pdf.php)
 * @param int    $request_id  The request ID
 * @return array|false        ['path' => '...', 'name' => '...'] or false
 */
function generateFundingPdfAttachment($link, $request_id) {
    // Temporarily store the PDF
    $temp_dir = __DIR__ . '/../temp_pdf';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }

    $temp_file = $temp_dir . "/Budget_Request_{$request_id}_" . time() . ".pdf";

    // Capture the PDF output
    ob_start();
    $_GET['id'] = $request_id;                 // fake the GET param
    define('PDF_INCLUDED', true);
    global $link;                              // make $link available inside the script
    $link = $link;                             // (in case it's not global already)
    require_once __DIR__ . '/../generate_budget_pdf.php';
    $pdf_content = ob_get_clean();

    if (empty($pdf_content)) {
        return false;
    }

    file_put_contents($temp_file, $pdf_content);

    return [
        'path' => $temp_file,
        'name' => "Budget_Request_{$request_id}_Approved.pdf"
    ];
}

/**
 * Generates the Liquidation PDF and returns an attachment array
 * @param mysqli $link
 * @param int    $request_id
 * @return array|false
 */
function generateLiquidationPdfAttachment($link, $request_id) {
    // Define CLI mode so the script knows it's being included

    $temp_dir = __DIR__ . '/../temp_pdf';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }

    $temp_file = $temp_dir . "/Liquidation_Report_{$request_id}_" . time() . ".pdf";

    // Capture the PDF output as string
    ob_start();
    
    // Fake the GET parameter
    $_GET['id'] = $request_id;

    // Make $link globally available inside the script
    global $link;
    $link_backup = $link;  // Save reference
    $link = $link;

    // Include the PDF generator (it will echo the PDF binary via Output('S'))
    require_once __DIR__ . '/../generate_liquidation_pdf.php';

    $pdf_content = ob_get_clean();

    // Restore global $link in case the script messed with it
    $link = $link_backup;

    if (empty($pdf_content) || strlen($pdf_content) < 1000) {
        return false; // Not a valid PDF
    }

    file_put_contents($temp_file, $pdf_content);

    return [
        'path' => $temp_file,
        'name' => "Liquidation_Report_{$request_id}_Approved.pdf"
    ];
}

/**
 * Generates the Reimbursement PDF and returns an attachment array
 * @param mysqli $link
 * @param int    $request_id
 * @return array|false
 */
function generateReimbursementPdfAttachment($link, $request_id) {

    $temp_dir = __DIR__ . '/../temp_pdf';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }

    $temp_file = $temp_dir . "/Reimbursement_Request_{$request_id}_" . time() . ".pdf";

    ob_start();

    $_GET['id'] = $request_id;

    global $link;
    $link_backup = $link;
    $link = $link;

    require_once __DIR__ . '/../generate_reimbursement_pdf.php';

    $pdf_content = ob_get_clean();

    $link = $link_backup; // restore just in case

    if (empty($pdf_content) || strlen($pdf_content) < 1000) {
        return false;
    }

    file_put_contents($temp_file, $pdf_content);

    return [
        'path' => $temp_file,
        'name' => "Reimbursement_Request_{$request_id}_Approved.pdf"
    ];
}

/**
 * Deletes the temporary PDF after sending the email
 */
function cleanupGeneratedPdf($attachment) {
    if ($attachment && isset($attachment['path']) && file_exists($attachment['path'])) {
        @unlink($attachment['path']);
    }
}
?>