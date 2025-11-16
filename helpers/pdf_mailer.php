<?php

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

require_once __DIR__ . '/../vendor/autoload.php';

function generateFundingPdfAttachment(mysqli $link, int $requestId): ?array
{
    $stmt = mysqli_prepare($link, "SELECT type FROM requests WHERE request_id = ?");
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, "i", $requestId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $type);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$type) {
        return null;
    }

    $scriptMap = [
        'Budget Request' => ['generate_budget_pdf_cli.php', "Budget_Request_{$requestId}.pdf"],
        'Reimbursement' => ['generate_reimbursement_pdf_cli.php', "Reimbursement_Request_{$requestId}.pdf"],
        'Liquidation Report' => ['generate_liquidation_pdf_cli.php', "Liquidation_Report_{$requestId}.pdf"],
    ];

    if (!isset($scriptMap[$type])) {
        return null;
    }

    [$script, $name] = $scriptMap[$type];
    return renderCliPdfAttachment($script, $requestId, $name);
}

function renderCliPdfAttachment(string $scriptRelativePath, int $requestId, string $downloadName): ?array
{
    $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cliScript = __DIR__ . '/../' . ltrim($scriptRelativePath, '/');

    if (!file_exists($cliScript)) {
        return null;
    }

    $tempBase = tempnam(sys_get_temp_dir(), 'auf_pdf_');
    if ($tempBase === false) {
        return null;
    }
    $tempFile = $tempBase . '.pdf';
    rename($tempBase, $tempFile);

    $cmd = escapeshellarg($phpBinary) . ' ' . escapeshellarg($cliScript) . ' ' . escapeshellarg((string)$requestId);

    $descriptorspec = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($process)) {
        @unlink($tempFile);
        return null;
    }

    $pdfBinary = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || empty($pdfBinary)) {
        @unlink($tempFile);
        return null;
    }

    if (file_put_contents($tempFile, $pdfBinary) === false) {
        @unlink($tempFile);
        return null;
    }

    return [
        'path' => $tempFile,
        'name' => $downloadName
    ];
}

/**
 * Creates a temporary PDF summary for venue requests.
 *
 * @param mysqli $link
 * @param int $requestId
 * @return array|null
 */
function generateVenuePdfAttachment(mysqli $link, int $requestId): ?array
{
    $sql = "SELECT 
                vr.*, 
                u.full_name AS officer_name,
                u.email,
                o.org_name
            FROM venue_requests vr
            JOIN users u ON vr.user_id = u.user_id
            JOIN organizations o ON u.org_id = o.org_id
            WHERE vr.venue_request_id = ?";

    if (!$stmt = mysqli_prepare($link, $sql)) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $requestId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$request) {
        return null;
    }

    $schedule = [];
    $schedule_sql = "SELECT venue_name, activity_date, start_time, end_time 
                     FROM venue_schedule 
                     WHERE venue_request_id = ?
                     ORDER BY activity_date, start_time";
    if ($sched_stmt = mysqli_prepare($link, $schedule_sql)) {
        mysqli_stmt_bind_param($sched_stmt, "i", $requestId);
        mysqli_stmt_execute($sched_stmt);
        $sched_result = mysqli_stmt_get_result($sched_stmt);
        while ($row = mysqli_fetch_assoc($sched_result)) {
            $schedule[] = $row;
        }
        mysqli_stmt_close($sched_stmt);
    }

    $styles = "
        <style>
            body { font-family: 'Plus Jakarta Sans', DejaVu Sans, Arial, sans-serif; color: #0f172a; font-size: 11px; }
            h1 { font-size: 20px; margin-bottom: 5px; color: #0f172a; }
            h2 { font-size: 14px; margin: 18px 0 6px; color: #111827; }
            .meta-grid { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .meta-grid td { padding: 6px 8px; border: 1px solid #e2e8f0; }
            .schedule-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .schedule-table th, .schedule-table td { border: 1px solid #cbd5f5; padding: 6px 8px; font-size: 10px; }
            .schedule-table th { background: #eef2ff; text-transform: uppercase; letter-spacing: 0.05em; }
            .brand { font-weight: 700; letter-spacing: 0.2em; font-size: 11px; color: #3b82f6; text-transform: uppercase; }
            .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
            .badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 10px; background: #e0f2fe; color: #0c4a6e; font-weight: 600; }
        </style>
    ";

    $html = '<div class="header">
                <div>
                    <div class="brand">AUFthorize</div>
                    <h1>Venue Request Summary</h1>
                </div>
                <div class="badge">Request #' . htmlspecialchars($requestId) . '</div>
            </div>';

    $venue_name = !empty($request['venue_other_name']) ? $request['venue_other_name'] : $request['venue_name'];

    $html .= '<table class="meta-grid">
                <tr>
                    <td><strong>Officer</strong><br>' . htmlspecialchars($request['officer_name']) . '</td>
                    <td><strong>Organization</strong><br>' . htmlspecialchars($request['org_name']) . '</td>
                </tr>
                <tr>
                    <td><strong>Venue</strong><br>' . htmlspecialchars($venue_name) . '</td>
                    <td><strong>Purpose</strong><br>' . htmlspecialchars($request['description'] ?? 'N/A') . '</td>
                </tr>
                <tr>
                    <td><strong>Submitted</strong><br>' . date('M d, Y g:i A', strtotime($request['date_submitted'])) . '</td>
                    <td><strong>Final Status</strong><br>' . htmlspecialchars($request['final_status'] ?? $request['notification_status']) . '</td>
                </tr>
            </table>';

    if (!empty($schedule)) {
        $html .= '<h2>Schedule & Logistics</h2><table class="schedule-table"><thead><tr><th>Date</th><th>Time</th><th>Venue</th></tr></thead><tbody>';
        foreach ($schedule as $slot) {
            $date = date('M d, Y', strtotime($slot['activity_date']));
            $time = date('g:i A', strtotime($slot['start_time'])) . ' - ' . date('g:i A', strtotime($slot['end_time']));
            $html .= '<tr>
                        <td>' . $date . '</td>
                        <td>' . $time . '</td>
                        <td>' . htmlspecialchars($slot['venue_name']) . '</td>
                      </tr>';
        }
        $html .= '</tbody></table>';
    }

    try {
        $mpdf = new Mpdf(['format' => 'A4', 'tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML($styles . $html);
        $tempBase = tempnam(sys_get_temp_dir(), 'auf_pdf_');
        if ($tempBase === false) {
            return null;
        }
        $filePath = $tempBase . '.pdf';
        rename($tempBase, $filePath);
        $mpdf->Output($filePath, Destination::FILE);

        return [
            'path' => $filePath,
            'name' => "AUF_Venue_Request_{$requestId}.pdf"
        ];
    } catch (\Throwable $e) {
        if (isset($filePath) && file_exists($filePath)) {
            unlink($filePath);
        }
        return null;
    }
}

/**
 * Utility helper to delete generated PDFs safely.
 *
 * @param array|null $attachment
 * @return void
 */
function cleanupGeneratedPdf(?array $attachment): void
{
    if ($attachment && isset($attachment['path']) && file_exists($attachment['path'])) {
        @unlink($attachment['path']);
    }
}
