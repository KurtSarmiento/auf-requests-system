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

    // --- IMPORTANT: CONFIGURE YOUR SMTP SETTINGS HERE ---
    // This example uses Gmail. You MUST update this with your mail server details.
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
        // You can log this error instead of just echoing it
        // error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Gets the requesting officer's details (email, name, activity name)
 *
 * @param mysqli $link          The database connection from db_config.php
 * @param int $requestId        The ID of the request
 * @param string $requestType   'funding' or 'venue'
 * @return array|null           An associative array of details or null if not found
 */
function getOfficerDetails($link, $requestId, $requestType = 'funding') {
    $details = null;

    if ($requestType === 'funding') {
        // For funding, we join users and requests. The activity name is in 'title'
        $sql = "SELECT u.email, u.full_name, r.title AS activity_name 
                FROM users u 
                JOIN requests r ON u.user_id = r.user_id 
                WHERE r.request_id = ?";
    } else { // 'venue'
        // --- THIS BLOCK IS NOW CORRECTED ---
        // It now selects 'v.title' and searches by 'v.venue_request_id'
        $sql = "SELECT u.email, u.full_name, v.title AS activity_name 
                FROM users u 
                JOIN venue_requests v ON u.user_id = v.user_id 
                WHERE v.venue_request_id = ?";
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
?>
