<?php
// send_pending_notifications.php
// This file will be called in the background — no user waiting!

require_once "db_config.php";
require_once "email.php";
require_once "helpers/pdf_mailer.php";

$lock_file = __DIR__ . '/.email_lock';
if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 60) {
    exit; // Prevent overlapping runs
}
touch($lock_file);

// Find requests that need notification but email not sent yet
$sql = "SELECT request_id, notification_status, type 
        FROM requests 
        WHERE notification_status IN ('Budget Available', 'Rejected by Adviser', 'Rejected by Dean', 'Rejected by OSAFA', 'Rejected by AFO')
          AND email_sent = 0
        LIMIT 5";

$result = mysqli_query($link, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $request_id = $row['request_id'];
    $status     = $row['notification_status'];
    $type       = $row['type'];

    // Mark as processing so we don't send twice
    mysqli_query($link, "UPDATE requests SET email_sent = 2 WHERE request_id = $request_id");

    // === Generate PDF ===
    $attachment = null;
    if (in_array($type, ['Budget Request', 'Funding Request', 'Reimbursement', 'Liquidation Report'])) {
        $attachment = generateFundingPdfAttachment($link, $request_id);
    }

    // === Build & Send Email ===
    $details = getOfficerDetails($link, $request_id, $type === 'Venue Request' ? 'venue' : 'funding');
    if ($details) {
        $attachments = $attachment ? [$attachment] : [];

        if (str_contains($status, 'Budget Available')) {
            $subject = "Budget Available for Your Request (ID: $request_id)";
            $message = "Good news! The budget for your request is now <strong>available for claiming</strong>.";
            $body = buildEmailTemplate("Dear {$details['full_name']},", $message, [
                "Request Title" => $details['activity_name'] ?? $details['title'],
                "Amount"       => "PHP " . number_format($details['amount'] ?? 0, 2),
            ]);
        } elseif (str_contains($status, 'Rejected')) {
            $rejector = trim(str_replace('Rejected by', '', $status));
            $remark   = mysqli_fetch_assoc(mysqli_query($link, "SELECT {$rejector}_remark FROM requests WHERE request_id = $request_id"))["{$rejector}_remark"] ?? '';
            $subject  = "Your Request Has Been Rejected (ID: $request_id)";
            $message  = "Your request has been rejected by the $rejector.";
            $body = buildEmailTemplate("Dear {$details['full_name']},", $message, [], "Reason", $remark);
        }

        $sent = sendNotificationEmail($details['email'], $subject, $body, $attachments);

        // Cleanup
        if ($attachment) cleanupGeneratedPdf($attachment);

        // Mark as sent (or failed)
        $flag = $sent ? 1 : 0;
        mysqli_query($link, "UPDATE requests SET email_sent = $flag WHERE request_id = $request_id");
    }
}

unlink($lock_file);
?>