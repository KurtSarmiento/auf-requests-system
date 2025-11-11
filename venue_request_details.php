<?php
session_start();
require_once "db_config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Get session variables
$user_id = $_SESSION["user_id"];
$role = $_SESSION["role"]; // Get the user's role
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($request_id === 0) {
    die("Invalid request ID.");
}

// --- START: MODIFIED & SECURE QUERY LOGIC ---

// 1. Base SQL Query (Secure)
// r.* automatically fetches all columns, including equipment and other_venue
$sql = "SELECT r.*, u.full_name AS officer_name, o.org_name
        FROM venue_requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON r.org_id = o.org_id
        WHERE r.venue_request_id = ?";

$params = [$request_id];
$types = "i";

// 2. Add permission check:
if ($role === 'Officer') {
    $sql .= " AND r.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

// 3. Execute the secure query
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// --- END: MODIFIED LOGIC ---

if (!$request) {
    die("Request not found or you don't have permission to view it.");
}

// Helper for status badge colors
function get_status_class($status) {
    switch ($status) {
        case 'Approved':
        case 'Venue Approved':
            return 'bg-green-100 text-green-800 border-green-500';
        case 'Rejected':
            return 'bg-red-100 text-red-800 border-red-500';
        case 'Pending':
            return 'bg-yellow-100 text-yellow-800 border-yellow-500';
        default:
            // This class will be used for the '-' status
            return 'bg-gray-100 text-gray-800 border-gray-500';
    }
}

require_once "layout_template.php";
// Pass the correct role from the session to the template
start_page("Request Status", $_SESSION['role'], $_SESSION['full_name']);
?>

<div class="max-w-4xl mx-auto bg-white p-8 rounded-xl shadow-2xl mt-10 mb-10">
    <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">
        Venue Request Status #<?php echo htmlspecialchars($request['venue_request_id']); ?>
    </h2>
    <p class="text-gray-600 mb-8">
        Viewing submitted request (read-only)
    </p>

    <a href="generate_venue_pdf.php?id=<?php echo $request_id; ?>" 
       target="_blank" 
       class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 mb-6 transition duration-150">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M5 4v3H3a2 2 0 00-2 2v6a2 2 0 002 2h14a2 2 0 002-2v-6a2 2 0 00-2-2h-2V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm4 9V7h2v6h-2zm-4-4h10v2H5V9z" clip-rule="evenodd" />
        </svg>
        Print Request PDF
    </a>

    <div class="space-y-4 mb-8 p-6 bg-gray-50 rounded-xl border">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Request Information</h3>
        <p><strong>Organization:</strong> <?php echo htmlspecialchars($request['org_name']); ?></p>
        <p><strong>Activity Title:</strong> <?php echo htmlspecialchars($request['title']); ?></p>

        <?php
        $venue_display = '';
        // Get the value from the 'other' text box
        $other_venue_name = $request['venue_other_name'] ?? null; 
        // Get the value from the main dropdown
        $main_venue_name = $request['venue_name'] ?? 'N/A';

        // NEW LOGIC: Prioritize the 'other_venue_name' field.
        if (!empty(trim($other_venue_name))) {
            // If the 'other' text box has content, display it.
            $venue_display = htmlspecialchars(trim($other_venue_name)) . " <span class='text-xs text-gray-500'>(Specified)</span>";
        } else {
            // The 'other' box was empty, so just show the main dropdown value.
            $venue_display = htmlspecialchars($main_venue_name);

            // Add a warning if they selected "Other" but left the box empty.
            if (str_starts_with(strtolower($main_venue_name), 'other')) {
                 $venue_display .= " <span class='text-xs text-red-500'>(No 'other' venue was specified)</span>";
            }
        }
        ?>
        <p><strong>Venue Requested:</strong> <span class="font-bold text-blue-700"><?php echo $venue_display; ?></span></p>
        <p><strong>Date & Time:</strong>
            <?php echo date('F j, Y', strtotime($request['activity_date'])) .
                       ' (' . date('h:i A', strtotime($request['start_time'])) .
                       ' - ' . date('h:i A', strtotime($request['end_time'])) . ')'; ?>
        </p>
        <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($request['description'])); ?></p>

        <div class="pt-4 mt-4 border-t">
            <p class="font-semibold text-gray-700">Equipment/Logistics Requested:</p>
            <?php 
            $equipment_data = $request['equipment_details'] ?? null;
            $decoded_equipment = json_decode($equipment_data, true);
            $other_text_from_json = $decoded_equipment['other'] ?? null;

            if (is_array($decoded_equipment) && !empty($decoded_equipment)) {
                echo '<div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-y-3 gap-x-6 text-sm p-4 bg-gray-100 rounded-md border">';
                $has_equipment = false;
                foreach ($decoded_equipment as $item => $quantity) {
                    if ($item === 'other') { continue; } // Skip 'other' key in this grid
                    if (is_numeric($quantity) && $quantity > 0) {
                        $has_equipment = true;
                        echo "<div class='flex justify-between items-baseline border-b border-gray-300 py-1'>";
                        echo "  <strong class='text-gray-800'>" . htmlspecialchars(ucfirst($item)) . ":</strong>";
                        echo "  <span class='font-bold text-lg text-blue-700 ml-2'>" . htmlspecialchars($quantity) . "</span>";
                        echo "</div>";
                    }
                }
                if (!$has_equipment) {
                    echo "<i class='text-gray-500 col-span-full'>No specific equipment quantities were listed.</i>";
                }
                echo '</div>'; // end grid
            } else {
                echo "<div class='mt-2 text-gray-800 bg-gray-100 p-4 rounded-md border text-sm'>";
                echo "<i class='text-gray-500'>No equipment listed.</i>";
                echo "</div>";
            }
            ?>
        </div>
        <div class="pt-4 mt-4 border-t">
            <p class="font-semibold text-gray-700">Other Materials/Notes:</p>
            <div class="mt-2 text-gray-800 bg-gray-100 p-4 rounded-md border text-sm">
                <?php 
                echo !empty($other_text_from_json) 
                    ? nl2br(htmlspecialchars($other_text_from_json)) 
                    : "<i class='text-gray-500'>No other materials listed.</i>"; 
                ?>
            </div>
        </div>
        <p class="pt-4 border-t mt-4"><strong>Submitted By:</strong> <?php echo htmlspecialchars($request['officer_name']); ?></p>
    </div>
    <div class="space-y-4 mb-8 p-6 bg-indigo-50/50 rounded-xl border border-indigo-200">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Approval Chain Status</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <?php
            // This array defines the approval chain order
            $cols = [
                'dean_status' => 'Dean',
                'admin_services_status' => 'Admin Services',
                'osafa_status' => 'OSAFA',
                'cfdo_status' => 'CFDO',
                'afo_status' => 'AFO',
                'vp_acad_status' => 'VP Acad',
                'vp_admin_status' => 'VP Admin (Final)',
            ];

            // This flag tracks if a rejection has occurred in the chain
            $rejection_found = false;
            
            foreach ($cols as $col => $label) {
                if (isset($request[$col])) {
                    $status_to_display = '';
                    $status_class = '';

                    // Check if a rejection has already happened
                    if ($rejection_found) {
                        $status_to_display = '-';
                        $status_class = get_status_class('default'); // Use the gray-colored default class
                    } else {
                        // No rejection yet, check the current status
                        $current_status = $request[$col];
                        
                        if ($current_status === 'Rejected') {
                            // This is the first rejection we've found
                            $rejection_found = true;
                            $status_to_display = 'Rejected';
                            $status_class = get_status_class('Rejected');
                        } else {
                            // Status is 'Approved' or 'Pending'
                            $status_to_display = $current_status;
                            $status_class = get_status_class($current_status);
                        }
                    }

                    // Print the final HTML for this signatory
                    echo "<p><strong>{$label}:</strong> <span class='status-pill " .
                         $status_class . "'>" .
                         htmlspecialchars($status_to_display) . "</span></p>";
                }
            }
            ?>
            <p><strong>Final:</strong>
                <span class="status-pill <?php echo get_status_class($request['final_status']); ?> font-bold">
                    <?php echo htmlspecialchars($request['final_status']); ?>
                </span>
            </p>
        </div>
    </div>
    <div class="space-y-3 mb-8 p-6 bg-gray-50 rounded-xl border">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Remarks from Approvers</h3>
        <?php
        $remarks = [
            'Dean' => $request['dean_remark'] ?? '',
            'Admin Services' => $request['admin_services_remark'] ?? '',
            'OSAFA' => $request['osafa_remark'] ?? '',
            'CFDO' => $request['cfdo_remark'] ?? '',
            'AFO' => $request['afo_remark'] ?? '',
            'VP Academic Affairs' => $request['vp_acad_remark'] ?? '',
            'VP Admin' => $request['vp_admin_remark'] ?? '',
        ];

        $hasRemarks = false;
        foreach ($remarks as $remark_role => $remark) {
            if (!empty($remark)) {
                $hasRemarks = true;
                echo "<div class='p-3 bg-white border rounded-lg'>
                          <p class='text-sm text-gray-500 mb-1'><strong>{$remark_role}</strong></p>
                          <p class='text-gray-700 italic'>" . htmlspecialchars($remark) . "</p>
                        </div>";
            }
        }

        if (!$hasRemarks) {
            echo "<p class='text-gray-500 italic'>No remarks have been added yet.</p>";
        }
        ?>
    </div>
    <div class="text-right">
        <?php
        // Determine the correct "back" link based on role
        if ($role === 'Officer') {
            $back_link = 'request_list.php';
            $back_text = '← Back to My Requests';
        } else {
            // Send signatories back to their dashboard or history list
            $back_link = 'admin_history_list.php'; 
            $back_text = '← Back to Request List';
        }
        ?>
        <a href="<?php echo $back_link; ?>" 
           class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg transition">
           <?php echo $back_text; ?>
        </a>
    </div>
    </div>

<?php end_page(); ?>