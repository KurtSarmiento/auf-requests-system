<?php
// check_venue_availability.php
// This file is for the *visual calendar* only.

require_once "db_config.php";
header('Content-Type: application/json');

// We will always return an empty list of booked dates [].
// This is the correct logic for a time-slot system.
//
// WHY IS THIS EMPTY?
// Because this file only controls which *days* are grayed out on the calendar.
// If we grayed out the whole day (like we did before), a user could
// not book a 1:00 PM slot just because someone else booked an 8:00 AM slot.
//
// By letting the user pick *any* day, they can then submit their
// desired time (e.g., "1:00 PM - 5:00 PM").
//
// The *other* file, `request_venue.php`, is the "smart guard" that
// will check their specific time and (if needed) give them the helpful
// error: "This venue is booked from 8:00 AM to 12:00 PM."

echo json_encode(['booked_dates' => []]);
exit;
?>
