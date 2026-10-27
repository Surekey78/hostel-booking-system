<?php

require_once __DIR__ . '/includes/functions.php';

logout_user();


session_start();
flash_set('info', 'You have been signed out successfully.');

redirect('login.php');
