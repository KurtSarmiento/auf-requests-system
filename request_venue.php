<?php
// Initialize the session
session_start();
 
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'Officer') {
    header("location: login.php");
    exit;
}

require_once "db_config.php";
require_once "layout_template.php";

// Define variables and initialize with empty values
$title = $venue_name = $activity_date = $start_time = $end_time = $description = "";
$venue_other_name = ""; // <<< NEW: For custom venue input
// <<< NEW: Equipment variables
$tables_count = $chairs_count = $flags_count = $rostrum_count = $housekeepers_count = $other_equip_spec = "";

$title_err = $venue_name_err = $activity_date_err = $time_err = $description_err = $general_err = "";
$user_id = $_SESSION["user_id"];
$org_id = $_SESSION["org_id"];

// Define a list of available venues 
$available_venues = [
    'The Struggle Square', 
    'St. Cecilia\'s Auditorium', 
    'Silungan',
    'AUF Quadrangle',
    'AUF Boardroom',
    'Cabalen Hall',
    'PS 108',
    'St. John Paull II - A (PS-307)',
    'St. John Paull II - B (PS-308)',
    'PS - 517',
    'Other (Specify Below)' // <<< ADDED: Other Option
];

// --- Initialize success message (for redirects) ---
$success_message = "";
if (isset($_GET['success']) && $_GET['success'] == 'venue_created') {
    $success_message = "Venue request submitted successfully! It is now Awaiting Dean Approval.";
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Validate inputs
    $title = trim($_POST["title"]);
    if (empty($title)) { $title_err = "Please enter a request title."; }

    $venue_name = trim($_POST["venue_name"]);
    if (empty($venue_name)) { $venue_name_err = "Please select a venue."; }

    $activity_date = trim($_POST["activity_date"]);
    $start_time = trim($_POST["start_time"]);
    $end_time = trim($_POST["end_time"]);

    if (empty($activity_date)) { $activity_date_err = "Please select a date."; }
    if (empty($start_time) || empty($end_time)) { $time_err = "Please specify start and end times."; }

    $description = trim($_POST["description"]);
    if (empty($description)) { $description_err = "Please provide an activity description."; }

    // Time validation (simple check: end time must be after start time)
    if (!empty($start_time) && !empty($end_time) && strtotime($start_time) >= strtotime($end_time)) {
        $time_err = "End time must be after start time.";
    }

    // --- NEW: Capture Equipment Quantities ---
    // (This logic is unchanged)
    $tables_count = max(0, (int)($_POST["tables_count"] ?? 0));
    $chairs_count = max(0, (int)($_POST["chairs_count"] ?? 0));
    $flags_count = max(0, (int)($_POST["flags_count"] ?? 0));
    $rostrum_count = max(0, (int)($_POST["rostrum_count"] ?? 0));
    $housekeepers_count = max(0, (int)($_POST["housekeepers_count"] ?? 0));
    $other_equip_spec = trim($_POST["other_equip_spec"] ?? '');
    $equipment_details = json_encode([
        'tables' => $tables_count, 'chairs' => $chairs_count, 'flags' => $flags_count,
        'rostrum' => $rostrum_count, 'housekeepers' => $housekeepers_count, 'other' => htmlspecialchars($other_equip_spec) 
    ]);

    // --- NEW: Capture Other Venue Name if selected ---
    // (This logic is unchanged)
    $venue_other_name = "";
    $final_venue_name_for_schedule = $venue_name; 
    if ($venue_name === 'Other (Specify Below)') {
        $venue_other_name = trim($_POST["venue_other_name"] ?? '');
        if (empty($venue_other_name)) {
            $venue_name_err = "Please specify the venue name when selecting 'Other'.";
        } else {
            $final_venue_name_for_schedule = $venue_other_name; 
        }
    }

    // ====================================================
    // === START: ADVANCED CONFLICT CHECK (WITH TIMES)  ===
    // ====================================================
    if (empty($title_err) && empty($venue_name_err) && empty($activity_date_err) && empty($time_err) && empty($description_err)) {
        
        // This query now fetches the conflicting start/end times
        $sql_conflict_check = "
            SELECT vs.start_time, vs.end_time
            FROM venue_schedule vs
            JOIN venue_requests vr ON vs.venue_request_id = vr.venue_request_id
            WHERE
                (vs.venue_name LIKE ? OR ? LIKE CONCAT('%', vs.venue_name, '%'))
            AND 
                vs.activity_date = ?
            AND 
                vr.final_status != 'Rejected'
            AND 
                (? < vs.end_time AND ? > vs.start_time)
            LIMIT 1
        ";

        if ($stmt_check = mysqli_prepare($link, $sql_conflict_check)) {
            $param_venue_name_like = '%' . $final_venue_name_for_schedule . '%';

            mysqli_stmt_bind_param($stmt_check, "sssss", 
                $param_venue_name_like,
                $final_venue_name_for_schedule,
                $activity_date,
                $start_time,
                $end_time
            );

            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_store_result($stmt_check); // Store result

            if (mysqli_stmt_num_rows($stmt_check) > 0) {
                // A conflict was found! Bind the results to variables.
                $conflicting_start = null;
                $conflicting_end = null;
                mysqli_stmt_bind_result($stmt_check, $conflicting_start, $conflicting_end);
                mysqli_stmt_fetch($stmt_check); // Fetch the first conflicting row

                // Format the times to be user-friendly (e.g., "9:00 AM")
                $friendly_start = date('g:i A', strtotime($conflicting_start));
                $friendly_end = date('g:i A', strtotime($conflicting_end));

                // Create the new, helpful error message
                $time_err = "This venue is already booked for an overlapping time slot. " .
                            "The existing booking on that day is from <strong>" . $friendly_start . " to " . $friendly_end . "</strong>. " .
                            "Please choose a different time (e.g., " . $friendly_end . " or later).";
            }
            mysqli_stmt_close($stmt_check);
        } else {
            $general_err = "Error checking venue availability. Please try again.";
        }
    }
    // ====================================================
    // === END: ADVANCED CONFLICT CHECK                 ===
    // ====================================================


    // 2. Insert into the database if no errors (all logic below is unchanged)
    if (empty($title_err) && empty($venue_name_err) && empty($activity_date_err) && empty($time_err) && empty($description_err) && empty($general_err)) {
        
        mysqli_begin_transaction($link);

        // SQL 1: Insert into main venue_requests table
        $sql = "INSERT INTO venue_requests (
                    user_id, org_id, title, venue_name, venue_other_name, 
                    activity_date, start_time, end_time, description, 
                    final_status, notification_status, equipment_details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Awaiting Dean Approval', ?)";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "iissssssss",
                $user_id, $org_id, $title, $venue_name, $venue_other_name, 
                $activity_date, $start_time, $end_time, $description, $equipment_details
            );

            if (mysqli_stmt_execute($stmt)) {
                $new_request_id = mysqli_insert_id($link);
                mysqli_stmt_close($stmt); 

                // SQL 2: Insert into the schedule table
                $sql_schedule = "INSERT INTO venue_schedule (venue_request_id, venue_name, activity_date, start_time, end_time) 
                                 VALUES (?, ?, ?, ?, ?)";
                
                if ($stmt_schedule = mysqli_prepare($link, $sql_schedule)) {
                    mysqli_stmt_bind_param($stmt_schedule, "issss",
                        $new_request_id,
                        $final_venue_name_for_schedule, 
                        $activity_date,
                        $start_time,
                        $end_time
                    );

                    if (mysqli_stmt_execute($stmt_schedule)) {
                        mysqli_commit($link);
                        mysqli_stmt_close($stmt_schedule);
                        header("location: officer_dashboard.php?success=venue_created");
                        exit(); 
                        
                    } else {
                        mysqli_rollback($link);
                        $general_err = "ERROR: Could not save schedule details. " . mysqli_error($link);
                        mysqli_stmt_close($stmt_schedule);
                    }
                } else {
                    mysqli_rollback($link);
                    $general_err = "ERROR: Could not prepare schedule statement.";
                }
                
            } else {
                mysqli_rollback($link);
                $general_err = "ERROR: Could not execute query: " . mysqli_error($link);
                mysqli_stmt_close($stmt);
            }
        } else {
            $general_err = "ERROR: Could not prepare statement.";
        }
    }
} // --- END OF POST REQUEST HANDLING ---

// Start the page using the template function
start_page("New Venue Request", $_SESSION['role'], $_SESSION['full_name']);

// Helper function for sticky form values in equipment section
$tables_val = $_POST['tables_count'] ?? $tables_count;
$chairs_val = $_POST['chairs_count'] ?? $chairs_count;
$flags_val = $_POST['flags_count'] ?? $flags_count;
$rostrum_val = $_POST['rostrum_count'] ?? $rostrum_count;
$housekeepers_val = $_POST['housekeepers_count'] ?? $housekeepers_count;
$other_equip_spec_val = $_POST['other_equip_spec'] ?? $other_equip_spec;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    /* This style is not really used now, but is harmless to keep */
    .flatpickr-day.booked { 
        background: #f87171; color: white !important; border-color: #f87171;
        opacity: 0.7; cursor: not-allowed;
    }
    .flatpickr-day.booked:hover { background: #ef4444; }
</style>

<div class="max-w-4xl mx-auto bg-white p-8 rounded-xl shadow-2xl">
    <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">Venue Reservation Request</h2>
    <p class="text-gray-600 mb-8">Please fill out the form carefully. The system will check for time conflicts upon submission.</p>

    <?php if (!empty($general_err)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $general_err; ?></span>
        </div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $success_message; ?></span>
        </div>
    <?php endif; ?>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        
        <div class="mb-5">
            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Activity Title / Purpose</label>
            <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($title); ?>"
                   class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 <?php echo (!empty($title_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                   required>
            <?php if (!empty($title_err)): ?><p class="text-red-500 text-xs mt-1"><?php echo $title_err; ?></p><?php endif; ?>
        </div>
        
        <div class="mb-5">
            <label for="venue_name" class="block text-sm font-medium text-gray-700 mb-2">Select Venue</label>
            <select name="venue_name" id="venue_name"
                    class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 bg-white <?php echo (!empty($venue_name_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                    required onchange="toggleOtherVenueInput(this.value)">
                <option value="">-- Choose a Venue --</option>
                <?php foreach ($available_venues as $venue): ?>
                    <option value="<?php echo htmlspecialchars($venue); ?>" 
                            <?php echo ($venue_name === $venue) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($venue); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($venue_name_err)): ?><p class="text-red-500 text-xs mt-1"><?php echo $venue_name_err; ?></p><?php endif; ?>
        </div>
        
        <div id="other_venue_container" class="mb-6 <?php echo ($venue_name !== 'Other (Specify Below)') ? 'hidden' : ''; ?>">
            <label for="venue_other_name" class="block text-sm font-medium text-gray-700 mb-1">Specify Venue Name</label>
            <input type="text" id="venue_other_name" name="venue_other_name"
                   class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 border-gray-300"
                   placeholder="e.g., Covered Court"
                   value="<?php echo htmlspecialchars($venue_other_name); ?>">
            <p class="text-xs text-gray-500 mt-1">Input the venue name you require.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-5 p-5 border border-indigo-200 rounded-lg bg-indigo-50/50">
            <div>
                <label for="activity_date" class="block text-sm font-medium text-gray-700 mb-2">Date of Activity</label>
                <input type="text" name="activity_date" id="activity_date" value="<?php echo htmlspecialchars($activity_date); ?>"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 bg-white <?php echo (!empty($activity_date_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                       placeholder="Select a date" required>
                <?php if (!empty($activity_date_err)): ?><p class="text-red-500 text-xs mt-1"><?php echo $activity_date_err; ?></p><?php endif; ?>
            </div>
            <div>
                <label for="start_time" class="block text-sm font-medium text-gray-700 mb-2">Start Time</label>
                <input type="time" name="start_time" id="start_time" value="<?php echo htmlspecialchars($start_time); ?>"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 <?php echo (!empty($time_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required>
            </div>
            <div>
                <label for="end_time" class="block text-sm font-medium text-gray-700 mb-2">End Time</label>
                <input type="time" name="end_time" id="end_time" value="<?php echo htmlspecialchars($end_time); ?>"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 <?php echo (!empty($time_err)) ? 'border-red-500' : 'border-gray-300'; ?>" required>
            </div>
             <?php if (!empty($time_err)): ?>
                <!-- This is where the new, helpful error message will appear -->
                <div class="md:col-span-3">
                    <p class="text-red-500 text-sm mt-1"><?php echo $time_err; // Note: No htmlspecialchars, we added <strong> ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="p-6 border border-indigo-200 rounded-xl bg-indigo-50 mb-6">
            <h3 class="text-xl font-semibold text-indigo-700 mb-4 flex items-center">
                Equipment / Logistics Requirements
            </h3>
            <p class="text-sm text-gray-600 mb-4">Input the quantity of each item needed. Leave blank or zero if not needed.</p>
            
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                
                <?php 
                // Helper function for equipment input fields
                function equipment_input($name, $label, $value) {
                    $val = htmlspecialchars($value);
                    echo "<div class='flex items-center space-x-2 bg-white p-3 rounded-lg border border-indigo-100'>";
                    echo "<input type='number' name='{$name}_count' id='{$name}_count' min='0' placeholder='0' value='{$val}' ";
                    echo "class='w-16 px-2 py-1 text-sm border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 text-center'>";
                    echo "<label for='{$name}_count' class='text-sm font-medium text-gray-700'>{$label}</label>";
                    echo "</div>";
                }
                ?>

                <?php equipment_input('tables', 'Tables', $tables_val); ?>
                <?php equipment_input('chairs', 'Chairs', $chairs_val); ?>
                <?php equipment_input('flags', 'Flags', $flags_val); ?>
                <?php equipment_input('rostrum', 'Rostrum', $rostrum_val); ?>
                <?php equipment_input('housekeepers', 'Housekeepers', $housekeepers_val); ?>
                <div class='flex items-center space-x-2'> 
                    <p class='text-sm font-bold text-gray-700'></p>
                </div>
            </div>

            <div class="mt-4">
                <label for="other_equip_spec" class="block text-sm font-medium text-gray-700 mb-2">Other Equipment/Specifications</label>
                <textarea id="other_equip_spec" name="other_equip_spec" rows="2" 
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 transition duration-150"
                          placeholder="Specify any other items or logistics needed..."><?php echo htmlspecialchars($other_equip_spec_val); ?></textarea>
            </div>
        </div>
        <div class="mb-5">
            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Detailed Activity Description</label>
            <textarea name="description" id="description" rows="4"
                      class="w-full px-4 py-2 border rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 <?php echo (!empty($description_err)) ? 'border-red-500' : 'border-gray-300'; ?>" 
                      required><?php echo htmlspecialchars($description); ?></textarea>
            <?php if (!empty($description_err)): ?><p class="text-red-500 text-xs mt-1"><?php echo $description_err; ?></p><?php endif; ?>
        </div>

        <div class="pt-4">
            <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-lg text-lg font-semibold hover:bg-indigo-700 transition duration-150 shadow-md transform hover:scale-[1.01] active:scale-95">
                Submit Venue Request
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// NEW: Function to toggle the Other Venue text input
function toggleOtherVenueInput(selectedValue) {
    const container = document.getElementById('other_venue_container');
    const input = document.getElementById('venue_other_name');
    if (selectedValue === 'Other (Specify Below)') {
        container.classList.remove('hidden');
        input.setAttribute('required', 'required');
    } else {
        container.classList.add('hidden');
        input.removeAttribute('required');
        input.value = ''; // Clear value when hidden
    }
}

$(document).ready(function() {
    let fp = null; // Flatpickr instance

    // Function to initialize or update Flatpickr
    function initializeFlatpickr(disabledDates = []) {
        if (fp) {
            fp.destroy();
        }
        
        // Initialize Flatpickr
        fp = flatpickr("#activity_date", {
            dateFormat: "Y-m-d",
            minDate: "today",
            // We pass an empty array to `disable` so no days are blocked.
            disable: disabledDates, 
        });
    }

    // Function to fetch booked dates
    function fetchBookedDates(venueName) {
        
        let venue_to_check = venueName;
        if (venueName === 'Other (Specify Below)') {
            venue_to_check = $('#venue_other_name').val();
        }

        if (!venue_to_check) { 
            initializeFlatpickr([]);
            return;
        }

        $.ajax({
            url: 'check_venue_availability.php', // This file now returns []
            method: 'GET',
            data: { 
                venue_name: venue_to_check 
            },
            dataType: 'json',
            success: function(response) {
                // response.booked_dates will be an empty array []
                // This will make the calendar NOT block any days.
                initializeFlatpickr(response.booked_dates);
            },
            error: function() {
                alert('Error checking venue availability. Try refreshing.');
                initializeFlatpickr([]);
            }
        });
    }

    // Event listener for venue change
    $('#venue_name').on('change', function() {
        const selectedVenue = $(this).val();
        fetchBookedDates(selectedVenue); 
        $('#activity_date').val(''); 
        toggleOtherVenueInput(selectedVenue);
    });
    
    $('#venue_other_name').on('blur', function() { 
        const selectedVenueInDropdown = $('#venue_name').val();
        if (selectedVenueInDropdown === 'Other (Specify Below)') {
            fetchBookedDates(selectedVenueInDropdown); 
            $('#activity_date').val(''); 
        }
    });

    const initialVenue = $('#venue_name').val();
    fetchBookedDates(initialVenue);

    if (!initialVenue) {
        initializeFlatpickr([]);
    }
    
    toggleOtherVenueInput(initialVenue);
});
</script>

<?php
mysqli_close($link);
end_page();
?>