<?php
session_start();
require_once "db_config.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch officer request details
$sql = "SELECT r.*, u.full_name AS officer_name, o.org_name
        FROM venue_requests r
        INNER JOIN users u ON r.user_id = u.user_id
        INNER JOIN organizations o ON r.org_id = o.org_id
        WHERE r.venue_request_id = {$request_id} AND r.user_id = {$user_id}";

$result = mysqli_query($link, $sql);
$request = mysqli_fetch_assoc($result);

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
            return 'bg-gray-100 text-gray-800 border-gray-500';
    }
}

require_once "layout_template.php";
start_page("Request Status", "Officer", $_SESSION['full_name']);
?>

<div class="max-w-4xl mx-auto bg-white p-8 rounded-xl shadow-2xl mt-10 mb-10">
    <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">
        Venue Request Status #<?php echo htmlspecialchars($request['venue_request_id']); ?>
    </h2>
    <p class="text-gray-600 mb-8">
        Viewing your submitted request (read-only)
    </p>

    <!-- Request Info -->
    <div class="space-y-4 mb-8 p-6 bg-gray-50 rounded-xl border">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Request Information</h3>
        <p><strong>Organization:</strong> <?php echo htmlspecialchars($request['org_name']); ?></p>
        <p><strong>Activity Title:</strong> <?php echo htmlspecialchars($request['title']); ?></p>
        <p><strong>Venue Requested:</strong> <span class="font-bold text-blue-700"><?php echo htmlspecialchars($request['venue_name']); ?></span></p>
        <p><strong>Date & Time:</strong> 
            <?php echo date('F j, Y', strtotime($request['activity_date'])) . 
                 ' (' . date('h:i A', strtotime($request['start_time'])) . 
                 ' - ' . date('h:i A', strtotime($request['end_time'])) . ')'; ?>
        </p>
        <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($request['description'])); ?></p>
        <p><strong>Submitted By:</strong> <?php echo htmlspecialchars($request['officer_name']); ?></p>
    </div>

    <!-- Approval Chain -->
    <div class="space-y-4 mb-8 p-6 bg-indigo-50/50 rounded-xl border border-indigo-200">
        <h3 class="text-xl font-semibold text-gray-800 border-b pb-2">Approval Chain Status</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <?php
            $cols = [
                'dean_status' => 'Dean',
                'admin_services_status' => 'Admin Services',
                'osafa_status' => 'OSAFA',
                'cfdo_status' => 'CFDO',
                'afo_status' => 'AFO',
                'vp_acad_status' => 'VP Acad',
                'vp_admin_status' => 'VP Admin (Final)',
            ];
            foreach ($cols as $col => $label) {
                echo "<p><strong>{$label}:</strong> <span class='status-pill " .
                     get_status_class($request[$col]) . "'>" .
                     htmlspecialchars($request[$col]) . "</span></p>";
            }
            ?>
            <p><strong>OVERALL:</strong> 
                <span class="status-pill <?php echo get_status_class($request['final_status']); ?> font-bold">
                    <?php echo htmlspecialchars($request['final_status']); ?>
                </span>
            </p>
        </div>
    </div>

    <!-- Remarks Section -->
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
        foreach ($remarks as $role => $remark) {
            if (!empty($remark)) {
                $hasRemarks = true;
                echo "<div class='p-3 bg-white border rounded-lg'>
                        <p class='text-sm text-gray-500 mb-1'><strong>{$role}</strong></p>
                        <p class='text-gray-700 italic'>" . htmlspecialchars($remark) . "</p>
                      </div>";
            }
        }

        if (!$hasRemarks) {
            echo "<p class='text-gray-500 italic'>No remarks have been added yet.</p>";
        }
        ?>
    </div>

    <!-- Back Button -->
    <div class="text-right">
        <a href="request_list.php" 
           class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg transition">
            ← Back to My Requests
        </a>
    </div>
</div>

<?php end_page(); ?>
