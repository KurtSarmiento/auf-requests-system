<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$requestId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($requestId <= 0) {
    fwrite(STDERR, "Invalid request ID supplied to generate_reimbursement_pdf_cli.php" . PHP_EOL);
    exit(2);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION["loggedin"] = true;
$_SESSION["role"] = 'SystemMailer';
$_SESSION["user_id"] = 0;
$_GET['id'] = $requestId;

if (!defined('AUF_PDF_CLI_MODE')) {
    define('AUF_PDF_CLI_MODE', true);
}

require __DIR__ . '/generate_reimbursement_pdf.php';
