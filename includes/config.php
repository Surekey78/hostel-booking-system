<?php





declare(strict_types=1);


define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hostel_booking');


define('APP_NAME', 'HostelEase');
define('APP_TAGLINE', 'Hostel Room Booking System');
define('BASE_URL', '');                        
define('CURRENCY', "\u{20B1}");              
define('MAX_UPLOAD_BYTES', 3 * 1024 * 1024);   
define('UPLOAD_DIR', __DIR__ . '/../uploads/documents');
define('INTAKE_PERIODS', [
    'Semester 1, 2026/2027',
    'Semester 2, 2026/2027',
    'Summer Term 2027',
]);


if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('HOSTELBOOK_SESSID');
    session_start();
}



mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    exit(
        '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:32px;'
        . 'background:#F3EDF7;border-radius:24px;color:#1C1B1F">'
        . '<h1 style="font-size:24px;margin:0 0 8px">Database connection failed</h1>'
        . '<p style="margin:0 0 16px;color:#49454F">The application could not reach MySQL. '
        . 'Import <code>database/schema.sql</code> + <code>database/seed_data.sql</code> and check '
        . 'the credentials in <code>includes/config.php</code>.</p>'
        . '<p style="margin:0;color:#B3261E;font-size:13px">' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>'
        . '</div>'
    );
}

date_default_timezone_set('Asia/Manila');
