<?php
// check_venue_availability.php

require_once "db_config.php";

header('Content-Type: application/json');

if (!isset($_GET['venue_name']) || empty($_GET['venue_name'])) {
    echo json_encode(['error' => 'Venue name is required.']);
    exit;
}

$venue_name = trim($_GET['venue_name']);
$booked_dates = [];

// 1. Fetch dates from the new venue_schedule table
// We only check for dates where there is at least one entry.
$sql = "SELECT DISTINCT activity_date FROM venue_schedule WHERE venue_name = ? AND activity_date >= CURDATE()";

if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "s", $venue_name);
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            // Collects all unique booked dates (YYYY-MM-DD)
            $booked_dates[] = $row['activity_date'];
        }
        mysqli_free_result($result);
    }
    mysqli_stmt_close($stmt);
}

mysqli_close($link);

// 2. Return the list of booked dates to the JavaScript
echo json_encode(['booked_dates' => $booked_dates]);
?>