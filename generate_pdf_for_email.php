<?php
// generate_pdf_for_email.php
define('AUF_PDF_CLI_MODE', true); // This bypasses session check for background use

function generateBudgetPdfAsAttachment($request_id, $output_path = null) {
    // Capture the output of generate_budget_pdf.php instead of displaying it
    ob_start();
    $_GET['id'] = $request_id; // Simulate the GET parameter
    
    // Include the original PDF generator (it will output PDF binary)
    require_once __DIR__ . '/generate_budget_pdf.php';
    
    $pdf_content = ob_get_clean();

    if (empty($pdf_content)) {
        return false; // Failed to generate
    }

    // If no path given, save temporarily
    if (!$output_path) {
        $output_path = __DIR__ . "/temp_pdf/Budget_Request_{$request_id}_" . time() . ".pdf";
        if (!is_dir(dirname($output_path))) {
            mkdir(dirname($output_path), 0755, true);
        }
    }

    file_put_contents($output_path, $pdf_content);
    return $output_path;
}
?>