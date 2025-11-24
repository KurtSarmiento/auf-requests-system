<?php
// Initialize the session and includes
session_start();
require_once "db_config.php";
require_once "layout_template.php"; // Include the layout functions

// Check if the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Get session variables
$user_id = $_SESSION["user_id"];
$role = $_SESSION["role"]; // Get the user's role
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_officer = $role === 'Officer';
$error_message = "";

if ($request_id === 0) {
    $error_message = "Invalid request ID.";
}

// --- START: MODIFIED & SECURE QUERY LOGIC ---

$request = null;
if (empty($error_message)) {
    // 1. Base SQL Query (Secure)
    $sql = "SELECT r.*, u.full_name AS officer_name, o.org_name
            FROM venue_requests r
            INNER JOIN users u ON r.user_id = u.user_id
            INNER JOIN organizations o ON r.org_id = o.org_id
            WHERE r.venue_request_id = ?";

    $params = [$request_id];
    $types = "i";

    // 2. Add permission check:
    if ($is_officer) {
        $sql .= " AND r.user_id = ?";
        $params[] = $user_id;
        $types .= "i";
    }

    // 3. Execute the secure query
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $request = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    }

    if (!$request) {
        $error_message = "Request not found or you don't have permission to view it.";
    }
}

// Close DB link if it was opened
if (isset($link) && $link instanceof mysqli) {
   mysqli_close($link);
}

// Helper for status badge colors (copied from original request_details.php)
if (!function_exists('get_status_class')) {
    function get_status_class($status) {
        $status_lower = strtolower($status);
        if ($status_lower == 'approved' || $status_lower == 'venue approved') {
            return 'bg-green-100 text-green-800 border-green-500';
        } elseif ($status_lower == 'rejected') {
            return 'bg-red-100 text-red-800 border-red-500';
        } elseif (strpos($status_lower, 'awaiting') !== false || $status_lower == 'pending') {
            return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        } elseif ($status_lower == '-') {
            return 'bg-gray-100 text-gray-800 border-gray-500';
        } else {
            return 'bg-blue-100 text-blue-800 border-blue-500';
        }
    }
}

// Helper for info boxes (copied from original request_details.php)
function info_box($title, $value, $extra_class = '') {
    echo "<div class='info-box {$extra_class}'>";
    echo "<h4 class='text-sm font-semibold text-gray-500 uppercase tracking-wider'>{$title}</h4>";
    echo "<p class='text-lg font-bold text-gray-800'>" . $value . "</p>";
    echo "</div>";
}


// --- Define approval chain for display ---
// NOTE: These columns must exist in the venue_requests table
$approval_chain = [
    'Dean' => 'dean_status',
    'Admin Services' => 'admin_services_status',
    'OSAFA' => 'osafa_status',
    'CFDO' => 'cfdo_status',
    'AFO' => 'afo_status',
    'VP Acad' => 'vp_acad_status',
    'VP Admin' => 'vp_admin_status',
];

// Start the page
start_page("Venue Request Details", $_SESSION["role"], $_SESSION["full_name"]);

?>

<style>
    /* Re-include base styles for consistency */
    .info-box {
        background-color: #f9fafb; /* bg-gray-50 */
        padding: 16px;
        border-radius: 8px; /* rounded-lg */
        border: 1px solid #e5e7eb; /* border-gray-200 */
    }
    .status-pill {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 9999px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid;
    }
</style>

<div class="container mx-auto px-4 py-8">

    <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-6 rounded-lg shadow-md">
            <p class="font-bold text-xl">Access Denied</p>
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <div class="mt-4">
                <?php // Determine correct back link
                    $back_link = $is_officer ? 'request_list.php' : 'admin_dashboard.php';
                    $back_text = $is_officer ? '← Back to My Requests' : '← Back to Dashboard';
                ?>
                <a href="<?php echo $back_link; ?>" class="inline-block bg-red-600 text-white px-4 py-2 rounded-lg mt-4 hover:bg-red-700"><?php echo $back_text; ?></a>
            </div>
        </div>
    <?php elseif ($request): ?>
        <?php
            // Prepare venue display
            $venue_display = '';
            $other_venue_name = $request['venue_other_name'] ?? null;
            $main_venue_name = $request['venue_name'] ?? 'N/A';

            if (!empty(trim($other_venue_name))) {
                $venue_display = htmlspecialchars(trim($other_venue_name));
            } else {
                $venue_display = htmlspecialchars($main_venue_name);
                if (str_starts_with(strtolower($main_venue_name), 'other')) {
                    $venue_display .= " <span class='text-xs text-red-500'>(No 'other' venue specified)</span>";
                }
            }
        ?>

        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <a href="<?php echo $is_officer ? 'request_list.php' : 'admin_history_list.php'; ?>"
               class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 bg-gray-100 px-4 py-2 rounded-full hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                <?php echo $is_officer ? 'Back to My Requests' : 'Back to History'; ?>
            </a>

            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500">Request ID: <span class="font-semibold text-gray-700">#<?php echo (int)$request['venue_request_id']; ?></span></span>
                <a href="generate_venue_pdf.php?id=<?php echo (int)$request['venue_request_id']; ?>"
                    target="_blank"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-full shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M5 4v3H3a2 2 0 00-2 2v6a2 2 0 002 2h14a2 2 0 002-2v-6a2 2 0 00-2-2h-2V4a2 2 0 00-2-2H7a2 2 0 00-2 2zM9 13V7h2v6H9zm-4-4h10v2H5V9z" />
                    </svg>
                    Export Venue Request (PDF)
                </a>
            </div>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 mb-6">
            <div class="flex flex-wrap justify-between items-center gap-4">
                <div>
                    <span class="text-sm font-semibold text-pink-600 bg-pink-50 px-3 py-1 rounded-full">Venue/Equipment Request</span>
                    <h1 class="text-4xl font-extrabold text-gray-900 mt-2"><?php echo htmlspecialchars($request['title']); ?></h1>
                    <p class="text-lg text-gray-600">
                        For Organization: <span class="font-semibold"><?php echo htmlspecialchars($request['org_name']); ?></span>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-500">Venue</p>
                    <p class="text-2xl md:text-3xl font-bold text-indigo-700">
                        <?php echo $venue_display; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Request Progress</h3>
                    <ol class="relative border-l border-gray-200">
                        <?php
                        $rejection_has_occurred = false;
                        $chain_icons = [
                            'Dean' => '<svg class="w-4 h-4 text-blue-800" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a8 8 0 100 16 8 8 0 000-16zM6 9a1 1 0 000 2h8a1 1 0 100-2H6z" /></svg>',
                            'Admin Services' => '<svg class="w-4 h-4 text-yellow-800" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" /><path fill-rule="evenodd" d="M4 5a2 2 0 012-2h8a2 2 0 012 2v10a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 1h6v4H7V6z" clip-rule="evenodd" /></svg>',
                            'OSAFA' => '<svg class="w-4 h-4 text-purple-800" fill="currentColor" viewBox="0 0 20 20"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM14.7 15.3a.5.5 0 01.7-.7l1.5 1.5a.5.5 0 01-.7.7l-1.5-1.5z" /></svg>',
                            'CFDO' => '<svg class="w-4 h-4 text-indigo-800" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a.5.5 0 00-.5.5v2a.5.5 0 001 0v-2A.5.5 0 0010 2zM4.7 5.3a.5.5 0 00-.7.7l1.5 1.5a.5.5 0 00.7-.7L4.7 5.3zm10.6 0l-1.5 1.5a.5.5 0 10.7.7l1.5-1.5a.5.5 0 00-.7-.7zM3 10a.5.5 0 00.5.5h2a.5.5 0 000-1h-2A.5.5 0 003 10zm13 0a.5.5 0 00-.5-.5h-2a.5.5 0 000 1h2a.5.5 0 00.5-.5zM6.5 14.5a.5.5 0 00-.7-.7l-1.5 1.5a.5.5 0 00.7.7l1.5-1.5zm7 0l1.5 1.5a.5.5 0 00.7-.7l-1.5-1.5a.5.5 0 00-.7.7zM10 17a.5.5 0 00.5-.5v-2a.5.5 0 00-1 0v2a.5.5 0 00.5.5z" /></svg>',
                            'AFO' => '<svg class="w-4 h-4 text-emerald-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>', // Checkmark icon
                            'VP Acad' => '<svg class="w-4 h-4 text-orange-800" fill="currentColor" viewBox="0 0 20 20"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z" /><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" /></svg>',
                            'VP Admin' => '<svg class="w-4 h-4 text-red-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd" /></svg>',
                        ];

                        foreach ($approval_chain as $role_name => $status_key):
                            $status = $request[$status_key] ?? 'N/A';
                            $date_key = str_replace('_status', '_decision_date', $status_key);
                            $status_date = $request[$date_key] ?? null;

                            // Custom icon for rejection marker
                            $icon_svg = $chain_icons[$role_name] ?? '<svg class="w-4 h-4 text-gray-800" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16z" /></svg>';
                            $icon_bg_class = 'bg-gray-100 ring-8 ring-white';

                            if ($rejection_has_occurred) {
                                $status_to_display = '-'; // Show '-' for steps after rejection
                                $status_class = get_status_class('-');
                                $status_date = null;
                            } else {
                                $status_to_display = $status;
                                $status_class = get_status_class($status);

                                if ($status === 'Rejected') {
                                    $rejection_has_occurred = true;
                                    $icon_svg = '<svg class="w-4 h-4 text-red-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>';
                                    $icon_bg_class = 'bg-red-100 ring-8 ring-white';
                                } elseif ($status === 'Approved' || $status === 'Venue Approved') {
                                    $icon_svg = '<svg class="w-4 h-4 text-green-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>';
                                    $icon_bg_class = 'bg-green-100 ring-8 ring-white';
                                } elseif ($status === 'Pending') {
                                    $icon_svg = '<svg class="w-4 h-4 text-yellow-800" fill="currentColor" viewBox="0 0 20 20"><path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v10a2 2 0 01-2 2H7a2 2 0 01-2-2V4zm2 5a1 1 0 000 2h6a1 1 0 100-2H7z" /></svg>';
                                    $icon_bg_class = 'bg-yellow-100 ring-8 ring-white';
                                }
                            }
                        ?>
                            <li class="mb-6 ml-6">
                                <span class="absolute flex items-center justify-center w-6 h-6 rounded-full -left-3 <?php echo $icon_bg_class; ?>">
                                    <?php echo $icon_svg; ?>
                                </span>
                                <h4 class="flex items-center mb-1 text-md font-semibold text-gray-900">
                                    <?php echo htmlspecialchars($role_name); ?>
                                </h4>
                                <span class="status-pill text-xs <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars($status_to_display); ?>
                                </span>
                                <?php if ($status_date && $status_to_display !== '-'): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo date('M d, Y g:i A', strtotime($status_date)); ?>
                                </p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>

                        <?php
                            $final_status = $request['final_status'] ?? 'Pending';
                            $final_status_class = get_status_class($final_status);
                            $final_icon_svg = '<svg class="w-4 h-4 text-indigo-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>';
                            $final_icon_bg = 'bg-indigo-100 ring-8 ring-white';

                            if ($final_status === 'Rejected') {
                                $final_icon_svg = '<svg class="w-4 h-4 text-red-800" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>';
                                $final_icon_bg = 'bg-red-100 ring-8 ring-white';
                            }
                        ?>
                        <li class="ml-6">
                            <span class="absolute flex items-center justify-center w-6 h-6 rounded-full -left-3 <?php echo $final_icon_bg; ?>">
                                <?php echo $final_icon_svg; ?>
                            </span>
                            <h4 class="mb-1 text-md font-semibold text-gray-900">Final Decision</h4>
                            <span class="status-pill text-xs <?php echo $final_status_class; ?>">
                                <?php echo htmlspecialchars($final_status); ?>
                            </span>
                        </li>
                    </ol>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Signatory Remarks</h3>
                    <div class="space-y-4">
                        <?php
                        $remarks_map = [
                            'Dean' => $request['dean_remark'] ?? null,
                            'Admin Services' => $request['admin_services_remark'] ?? null,
                            'OSAFA' => $request['osafa_remark'] ?? null,
                            'CFDO' => $request['cfdo_remark'] ?? null,
                            'AFO' => $request['afo_remark'] ?? null,
                            'VP Academic Affairs' => $request['vp_acad_remark'] ?? null,
                            'VP Admin' => $request['vp_admin_remark'] ?? null,
                        ];
                        $has_remarks = false;
                        foreach ($remarks_map as $role_name => $remark_text):
                            if (!empty($remark_text)):
                                $has_remarks = true;
                        ?>
                        <div class="border-l-4 border-gray-300 pl-4">
                            <p class="text-sm font-semibold text-gray-700"><?php echo htmlspecialchars($role_name); ?> wrote:</p>
                            <p class="text-gray-600 italic">"<?php echo nl2br(htmlspecialchars($remark_text)); ?>"</p>
                        </div>
                        <?php
                            endif;
                        endforeach;

                        if (!$has_remarks):
                        ?>
                        <p class="text-sm text-gray-500">No remarks have been left on this request yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <?php
                    $start_time = date('h:i A', strtotime($request['start_time']));
                    $end_time = date('h:i A', strtotime($request['end_time']));
                    ?>
                    <?php info_box('Date Submitted', date('M d, Y h:i A', strtotime($request['date_submitted']))); ?>
                    <?php info_box('Activity Date', date('M d, Y', strtotime($request['activity_date']))); ?>
                    <?php info_box('Time', "{$start_time} - {$end_time}", 'col-span-1'); ?>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Activity Description / Purpose</h3>
                    <div class="prose max-w-none text-gray-700">
                        <?php echo !empty($request['description']) ? nl2br(htmlspecialchars($request['description'])) : '<p><i>No description provided.</i></p>'; ?>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Equipment & Logistics Requested</h3>

                    <?php
                    $equipment_data = $request['equipment_details'] ?? null;
                    $decoded_equipment = json_decode($equipment_data, true);
                    $other_text_from_json = $decoded_equipment['other'] ?? '';

                    $has_specific_equipment = false;
                    $equipment_list = [];

                    if (is_array($decoded_equipment)) {
                        foreach ($decoded_equipment as $item => $quantity) {
                            if ($item === 'other') { continue; }
                            if (is_numeric($quantity) && $quantity > 0) {
                                $has_specific_equipment = true;
                                $equipment_list[] = ['item' => ucfirst($item), 'qty' => (int)$quantity];
                            }
                        }
                    }
                    ?>

                    <?php if ($has_specific_equipment): ?>
                        <div class="overflow-x-auto mt-4">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-indigo-50/80 text-indigo-900 uppercase tracking-wide text-xs">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-semibold">Equipment Item</th>
                                        <th class="px-4 py-2 text-right font-semibold">Quantity</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php foreach ($equipment_list as $line): ?>
                                    <tr class="hover:bg-indigo-50/40 transition-colors">
                                        <td class="px-4 py-2 font-medium text-gray-800">
                                            <?php echo htmlspecialchars($line['item']); ?>
                                        </td>
                                        <td class="px-4 py-2 text-right font-semibold text-gray-900">
                                            <?php echo (int)$line['qty']; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="mt-4 text-sm text-gray-500 bg-gray-50 p-4 rounded-lg border border-dashed border-gray-300">
                             No specific equipment items were listed with a quantity.
                        </div>
                    <?php endif; ?>

                    <div class="pt-6 mt-6 border-t border-gray-100">
                        <p class="text-lg font-semibold text-gray-700 mb-2">Other Materials / Special Instructions:</p>
                        <div class="text-gray-700 bg-gray-50 p-4 rounded-lg border border-gray-200 text-sm">
                            <?php
                            echo !empty($other_text_from_json)
                                ? nl2br(htmlspecialchars($other_text_from_json))
                                : "<i class='text-gray-500'>No additional notes or materials provided.</i>";
                            ?>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Request Origin</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <?php info_box('Submitted By', $request['officer_name'], 'col-span-1'); ?>
                        <?php info_box('Organization', $request['org_name'], 'col-span-1'); ?>
                    </div>
                </div>

            </div>

        </div>
    <?php endif; ?>
</div>

<?php
end_page();
?>