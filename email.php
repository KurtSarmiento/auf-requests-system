<?php
// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
require 'vendor/autoload.php';

/**
 * Sends a notification email.
 *
 * @param string $toEmail   The recipient's email address.
 * @param string $subject   The email subject.
 * @param string $body      The HTML email body.
 * @return bool             True on success, false on failure.
 */
function sendNotificationEmail($toEmail, $subject, $body) {
    $mail = new PHPMailer(true);

    // --- YOUR SMTP SETTINGS (Unchanged) ---
    try {
        //Server settings
        $mail->isSMTP();                                    // Send using SMTP
        $mail->Host       = 'smtp.gmail.com';             // Set the SMTP server to send through
        $mail->SMTPAuth   = true;                           // Enable SMTP authentication
        $mail->Username   = 'afo.auf@gmail.com';         // SMTP username
        $mail->Password   = 'bcyprmznjtnfirto';          // SMTP password (Use App Password for Gmail)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;      // Enable implicit TLS encryption
        $mail->Port       = 465;                            // TCP port to connect to

        //Recipients
        $mail->setFrom('afo.auf@gmail.com', 'AUF Request System'); // Set who the email is from
        $mail->addAddress($toEmail);     // Add a recipient

        // Content
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); // For non-HTML mail clients

        $mail->send();
        return true;
    } catch (Exception $e) {
        // You can log this error
        // error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Gets all request details for building a notification email.
 *
 * @param mysqli $link          The database connection from db_config.php
 * @param int $requestId        The ID of the request
 * @param string $requestType   'funding' or 'venue'
 * @return array|null           An associative array of details or null if not found
 */
function getOfficerDetails($link, $requestId, $requestType = 'funding') {
    $details = null;

    if ($requestType === 'funding') {
        // === START: UPGRADED FUNDING QUERY ===
        // Now fetches amount, type, and date_submitted
        $sql = "SELECT 
                    u.email, 
                    u.full_name, 
                    r.title AS activity_name,
                    r.amount,
                    r.type,
                    r.date_submitted
                FROM users u 
                JOIN requests r ON u.user_id = r.user_id 
                WHERE r.request_id = ?";
        // === END: UPGRADED FUNDING QUERY ===

    } else { // 'venue'
        // === START: UPGRADED VENUE QUERY ===
        // Now fetches all date, time, and venue name details
        $sql = "SELECT 
                    u.email, 
                    u.full_name, 
                    v.title AS activity_name,
                    v.activity_date,
                    v.start_time,
                    v.end_time,
                    v.venue_name,
                    v.venue_other_name,
                    v.date_submitted
                FROM users u 
                JOIN venue_requests v ON u.user_id = v.user_id 
                WHERE v.venue_request_id = ?";
        // === END: UPGRADED VENUE QUERY ===
    }

    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $requestId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $details = $row;
        }
        mysqli_stmt_close($stmt);
    }
    return $details;
}


/**
 * Helper function to create a clean HTML email template.
 * @param string $greeting - The opening line (e.g., "Dear John Doe,")
 * @param string $message - The main paragraph of text.
 * @param array $details - An associative array of details (e.g., ['Title' => 'My Event'])
 * @param string $reason_heading - (Optional) e.g., "Reason for Rejection"
 * @param string $reason_text - (Optional) The remark text.
 * @return string - A full HTML email body.
 */
function buildEmailTemplate($greeting, $message, $details = [], $reason_heading = "", $reason_text = "") {
    $body = "<!DOCTYPE html><html><head><style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; }
        .container { width: 90%; max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .header { font-size: 24px; font-weight: bold; color: #1e3a8a; }
        .content { margin-top: 20px; }
        .details-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .details-table th, .details-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .details-table th { background-color: #f9f9f9; width: 30%; }
        .reason-box { margin-top: 20px; padding: 15px; background-color: #fffbe6; border: 1px solid #fde68a; border-radius: 4px; }
        .reason-box strong { color: #b45309; }
        .footer { margin-top: 20px; font-size: 12px; color: #777; }
    </style></head><body>";
    
    $body .= "<div class='container'>";
    $body .= "<div class='header'>AUF Request System Update</div>";
    $body .= "<div class='content'>";
    $body .= "<p>" . htmlspecialchars($greeting) . "</p>";
    $body .= "<p>" . $message . "</p>"; // This message can contain HTML

    if (!empty($details)) {
        $body .= "<table class='details-table'>";
        $body .= "<tbody>";
        foreach ($details as $key => $value) {
            $body .= "<tr><th>" . htmlspecialchars($key) . "</th><td>" . htmlspecialchars($value) . "</td></tr>";
        }
        $body .= "</tbody></table>";
    }

    if (!empty($reason_heading) && !empty($reason_text)) {
        $body .= "<div class='reason-box'>";
        $body .= "<strong>" . htmlspecialchars($reason_heading) . ":</strong>";
        $body .= "<p style='margin-top: 5px;'>" . nl2br(htmlspecialchars($reason_text)) . "</p>";
        $body .= "</div>";
    }

    $body .= "<p class='footer'>This is an automated message. Please do not reply.</p>";
    $body .= "</div></div></body></html>";
    
    return $body;
}
?>