<?php
/** logout.php - End the session and return to the sign-in page. */
require_once __DIR__ . '/includes/functions.php';

logout_user();

// logout_user() destroys the session, so start a fresh one for the goodbye message.
session_start();
flash_set('info', 'You have been signed out successfully.');

redirect('login.php');
