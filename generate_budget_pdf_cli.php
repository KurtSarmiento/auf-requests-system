<?php

// CLI wrapper to produce the same PDF as generate_budget_pdf.php without a browser context.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$requestId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($requestId <= 0) {
    fwrite(STDERR, "Invalid request ID supplied to generate_budget_pdf_cli.php" . PHP_EOL);
    exit(2);
}

// Prime the expected session variables so the included script thinks we are authenticated.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION["loggedin"] = true;
$_SESSION["role"] = 'SystemMailer';
$_SESSION["user_id"] = 0;
$_GET['id'] = $requestId;

// Flag to let the PDF script know it is running in a controlled CLI context.
if (!defined('AUF_PDF_CLI_MODE')) {
    define('AUF_PDF_CLI_MODE', true);
}

require __DIR__ . '/generate_budget_pdf.php';
