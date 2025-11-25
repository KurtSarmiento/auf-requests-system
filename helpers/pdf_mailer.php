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
 * Deletes the temporary PDF after sending the email
 */
function cleanupGeneratedPdf($attachment) {
    if ($attachment && isset($attachment['path']) && file_exists($attachment['path'])) {
        @unlink($attachment['path']);
    }
}
?>