<?php
/**
 * functions.php - Shared helpers: escaping, auth, CSRF, flash, notifications,
 *                 vacancy maths, uploads, pagination, report utilities.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* =====================================================================
   1. BASIC UTILITIES
   ===================================================================== */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirect helper.
 *
 * The path is resolved against the current script's directory, and a redundant
 * directory prefix is stripped so that `redirect('student/apply.php')` works
 * whether it is called from the web root or from inside /student.
 */
function redirect(string $path): never
{
    // Pages that live in the document root — always resolved from the root,
    // never relative to /student or /admin.
    $rootPages = ['index.php', 'login.php', 'logout.php', 'register.php', 'profile.php', 'notifications.php', 'document.php'];
    if (in_array(explode('?', $path)[0], $rootPages, true)) {
        header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
        exit;
    }

    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $prefix    = $scriptDir === '' ? '' : trim($scriptDir, '/') . '/';

    if ($prefix !== '' && str_starts_with($path, $prefix)) {
        $path = substr($path, strlen($prefix));
    }

    header('Location: ' . BASE_URL . $scriptDir . '/' . ltrim($path, '/'));
    exit;
}

function money(float|string|null $amount): string
{
    return CURRENCY . number_format((float)$amount, 2);
}

function fmt_date(?string $date, string $format = 'd M Y'): string
{
    if (!$date || $date === '0000-00-00' || str_starts_with($date, '0000')) {
        return '&mdash;';
    }
    return date($format, strtotime($date));
}

function fmt_datetime(?string $dt): string
{
    if (!$dt) {
        return '&mdash;';
    }
    return date('d M Y, g:i A', strtotime($dt));
}

/** "3 hours ago" style relative time. */
function time_ago(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return date('d M Y', strtotime($dt));
}

/**
 * URL prefix for the application root, relative to the *current* script.
 * Keeps asset and navigation links working at a domain root, inside a
 * subfolder (e.g. htdocs/hostel/ on XAMPP) and from /student or /admin.
 */
function app_url(string $path = ''): string
{
    static $prefix = null;
    if ($prefix === null) {
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($scriptDir === '' || $scriptDir === '.') {
            $prefix = rtrim(BASE_URL, '/') . '/';
        } else {
            // $scriptDir is still rooted here (e.g. "/student" or "/hostel/admin"),
            // so its slash count equals the number of directories to climb.
            $depth   = substr_count($scriptDir, '/');
            $baseDir = rtrim(BASE_URL, '/');
            $prefix  = ($baseDir !== '' ? $baseDir . '/' : '') . str_repeat('../', $depth);
        }
    }
    return $prefix . ltrim($path, '/');
}

/* =====================================================================
   2. FLASH MESSAGES
   ===================================================================== */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $out = '<div class="flash-stack">';
    foreach ($_SESSION['flash'] as $f) {
        $out .= '<div class="alert alert-' . e($f['type']) . '" role="status">'
              . '<span class="alert-icon">' . icon_for($f['type']) . '</span>'
              . '<span>' . e($f['message']) . '</span>'
              . '<button type="button" class="alert-close" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button>'
              . '</div>';
    }
    $out .= '</div>';
    unset($_SESSION['flash']);
    return $out;
}

function icon_for(string $type): string
{
    return match ($type) {
        'success' => icon('check_circle'),
        'danger', 'error' => icon('error'),
        'warning' => icon('warning'),
        default   => icon('info'),
    };
}

/* =====================================================================
   3. CSRF PROTECTION
   ===================================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        flash_set('danger', 'Your session expired or the form token was invalid. Please try again.');
        // Send the user back to the page they posted from (same-host paths only).
        $back = 'index.php';
        $ref  = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref !== '') {
            $parts   = parse_url($ref);
            $refHost = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if ($refHost === ($_SERVER['HTTP_HOST'] ?? '') && !empty($parts['path'])) {
                $back = ltrim($parts['path'], '/');
            }
        }
        redirect($back);
    }
}

/* =====================================================================
   4. AUTHENTICATION & AUTHORISATION
   ===================================================================== */

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    global $conn;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM users WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $res  = mysqli_stmt_get_result($stmt);
    $cache = mysqli_fetch_assoc($res) ?: null;
    mysqli_stmt_close($stmt);
    if ($cache && $cache['status'] !== 'active') {
        $cache = null;
        logout_user();
    }
    return $cache;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function is_student(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'student';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash_set('warning', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $u;
}

function require_role(string $role): array
{
    $u = require_login();
    if ($u['role'] !== $role) {
        http_response_code(403);
        flash_set('danger', 'You do not have permission to view that page.');
        redirect($role === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
    }
    return $u;
}

function require_admin(): array
{
    return require_role('admin');
}

function require_student(): array
{
    return require_role('student');
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['full_name']  = $user['full_name'];
    $_SESSION['login_time'] = time();
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Attempt sign-in; returns the user row or null. */
function attempt_login(string $email, string $password): ?array
{
    global $conn;
    $stmt = mysqli_prepare($conn, 'SELECT * FROM users WHERE email = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['status'] !== 'active') {
            return null;
        }
        // Transparent rehash if the cost factor changed.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $new = password_hash($password, PASSWORD_DEFAULT);
            $upd = mysqli_prepare($conn, 'UPDATE users SET password_hash = ? WHERE id = ?');
            mysqli_stmt_bind_param($upd, 'si', $new, $user['id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
        return $user;
    }
    return null;
}

/* =====================================================================
   5. NOTIFICATIONS
   ===================================================================== */

function notify(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void
{
    global $conn;
    if ($userId <= 0) {
        return;                       // nothing to notify
    }
    $exists = db_one($conn, 'SELECT id FROM users WHERE id = ?', 'i', [$userId]);
    if (!$exists) {
        error_log("notify(): user $userId does not exist — notification '$title' skipped.");
        return;                       // avoid a foreign-key failure breaking the request
    }
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO notifications (user_id, title, message, link, type) VALUES (?,?,?,?,?)'
    );
    mysqli_stmt_bind_param($stmt, 'issss', $userId, $title, $message, $link, $type);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function unread_notification_count(int $userId): int
{
    global $conn;
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int)($row['c'] ?? 0);
}

function mark_notifications_read(int $userId): void
{
    global $conn;
    $stmt = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/* =====================================================================
   6. HOSTEL / ROOM / VACANCY LOGIC
   ===================================================================== */

function hostel_summary(int $hostelId): ?array
{
    global $conn;
    $stmt = mysqli_prepare($conn, 'SELECT * FROM v_hostel_occupancy WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $hostelId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function room_type_options(): array
{
    global $conn;
    $res = mysqli_query($conn, 'SELECT * FROM room_types ORDER BY capacity, price');
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

function hostel_options(): array
{
    global $conn;
    $res = mysqli_query($conn, 'SELECT id, name, block_code, gender_policy FROM hostels WHERE is_active = 1 ORDER BY block_code');
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

/** Beds still free for a room type inside one hostel. */
function vacancy_for(int $hostelId, int $roomTypeId): int
{
    global $conn;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(rt.capacity - r.occupied),0) v
           FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
          WHERE r.hostel_id = ? AND r.room_type_id = ? AND r.status = 'available'"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $hostelId, $roomTypeId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int)($row['v'] ?? 0);
}

/** Total free beds across the whole system. */
function total_vacancy(): int
{
    global $conn;
    $res = mysqli_query(
        $conn,
        "SELECT COALESCE(SUM(rt.capacity - r.occupied),0) v
           FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
          WHERE r.status = 'available'"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return (int)($row['v'] ?? 0);
}

/** Pick the best (lowest number, has free bed) room for an assignment. */
function find_free_room(int $hostelId, int $roomTypeId): ?array
{
    global $conn;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT r.*, rt.capacity
           FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
          WHERE r.hostel_id = ? AND r.room_type_id = ? AND r.status = 'available'
            AND r.occupied < rt.capacity
          ORDER BY r.floor ASC, LENGTH(r.room_number) ASC, r.room_number ASC
          LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $hostelId, $roomTypeId);
    mysqli_stmt_execute($stmt);
    $room = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $room ?: null;
}

/**
 * Convert an approved booking into a room assignment (transactional).
 * Returns ['ok'=>bool,'message'=>string,'assignment'=>?array]
 */
function assign_room(int $bookingId, ?int $roomId = null): array
{
    global $conn;
    $admin = current_user();

    mysqli_begin_transaction($conn);
    try {
        // Lock the booking row for the duration of the transaction so two
        // administrators can never assign the same bed at the same time.
        $lock = mysqli_prepare($conn, 'SELECT id, student_id, hostel_id, room_type_id, checkin_date, status, reference_no FROM bookings WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($lock, 'i', $bookingId);
        mysqli_stmt_execute($lock);
        $booking = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
        mysqli_stmt_close($lock);

        if (!$booking) {
            throw new RuntimeException('Booking not found.');
        }
        if (!in_array($booking['status'], ['pending', 'approved'], true)) {
            throw new RuntimeException('Only pending or approved applications can be assigned a room.');
        }

        // Resolve room
        if ($roomId === null) {
            $room = find_free_room((int)$booking['hostel_id'], (int)$booking['room_type_id']);
        } else {
            $rs = mysqli_prepare($conn, "SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id = ? FOR UPDATE");
            mysqli_stmt_bind_param($rs, 'i', $roomId);
            mysqli_stmt_execute($rs);
            $room = mysqli_fetch_assoc(mysqli_stmt_get_result($rs));
            mysqli_stmt_close($rs);
            if (!$room) {
                throw new RuntimeException('Selected room does not exist.');
            }
            if ((int)$room['hostel_id'] !== (int)$booking['hostel_id'] || (int)$room['room_type_id'] !== (int)$booking['room_type_id']) {
                throw new RuntimeException('That room does not match the applied hostel / room type.');
            }
        }
        if (!$room) {
            throw new RuntimeException('No vacant room of the requested type is available in this hostel.');
        }
        if ($room['status'] !== 'available' || (int)$room['occupied'] >= (int)$room['capacity']) {
            throw new RuntimeException('Room ' . $room['room_number'] . ' is not vacant.');
        }

        $bedNumber = (int)$room['occupied'] + 1;
        $newOccupied = $bedNumber;
        $newStatus = $newOccupied >= (int)$room['capacity'] ? 'full' : 'available';
        $adminId = $admin['id'] ?? null;

        // 1. Occupy the bed
        $up = mysqli_prepare($conn, 'UPDATE rooms SET occupied = ?, status = ? WHERE id = ?');
        mysqli_stmt_bind_param($up, 'isi', $newOccupied, $newStatus, $room['id']);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);

        // 2. Create assignment record
        $ins = mysqli_prepare(
            $conn,
            "INSERT INTO assignments (booking_id, room_id, bed_number, student_id, assigned_by, status, checkin_date)
             VALUES (?,?,?,?,?, 'active', ?)"
        );
        $ci = !empty($booking['checkin_date']) ? $booking['checkin_date'] : date('Y-m-d');
        mysqli_stmt_bind_param($ins, 'iiiiis', $bookingId, $room['id'], $bedNumber, $booking['student_id'], $adminId, $ci);
        mysqli_stmt_execute($ins);
        $assignmentId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);

        // 3. Move booking to assigned
        $now = date('Y-m-d H:i:s');
        $st = 'assigned';
        $ub = mysqli_prepare($conn, 'UPDATE bookings SET status = ?, processed_by = ?, processed_at = ? WHERE id = ?');
        mysqli_stmt_bind_param($ub, 'sisi', $st, $adminId, $now, $bookingId);
        mysqli_stmt_execute($ub);
        mysqli_stmt_close($ub);

        mysqli_commit($conn);

        // 4. Notify the student (outside the transaction, on a fresh statement)
        $info = db_one($conn, 'SELECT reference_no FROM bookings WHERE id = ?', 'i', [$bookingId]);
        if ($info) {
            notify(
                (int)$booking['student_id'],
                'Room assigned — ' . $info['reference_no'],
                'You have been assigned Room ' . $room['room_number'] . ' (Bed ' . $bedNumber
                . '). Please report to the hostel office for key collection.',
                'success',
                'student/my-assignment.php'
            );
        }

        return [
            'ok'      => true,
            'message' => 'Room ' . $room['room_number'] . ' (Bed ' . $bedNumber . ') assigned successfully.',
            'room'    => $room,
            'bed'     => $bedNumber,
            'assignment_id' => $assignmentId,
        ];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return ['ok' => false, 'message' => $ex->getMessage()];
    }
}

/** Release a bed (check-out / cancellation of an assigned booking). */
function release_assignment(int $bookingId): array
{
    global $conn;
    mysqli_begin_transaction($conn);
    try {
        $as = mysqli_prepare($conn, "SELECT * FROM assignments WHERE booking_id = ? AND status = 'active' FOR UPDATE");
        mysqli_stmt_bind_param($as, 'i', $bookingId);
        mysqli_stmt_execute($as);
        $assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($as));
        mysqli_stmt_close($as);

        if (!$assignment) {
            throw new RuntimeException('No active assignment found for this booking.');
        }

        $rs = mysqli_prepare($conn, 'SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id WHERE r.id = ? FOR UPDATE');
        mysqli_stmt_bind_param($rs, 'i', $assignment['room_id']);
        mysqli_stmt_execute($rs);
        $room = mysqli_fetch_assoc(mysqli_stmt_get_result($rs));
        mysqli_stmt_close($rs);

        $occupied = max(0, (int)$room['occupied'] - 1);
        $status   = $room['status'] === 'maintenance' ? 'maintenance' : ($occupied >= (int)$room['capacity'] ? 'full' : 'available');

        $up = mysqli_prepare($conn, 'UPDATE rooms SET occupied = ?, status = ? WHERE id = ?');
        mysqli_stmt_bind_param($up, 'isi', $occupied, $status, $room['id']);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);

        $ua = mysqli_prepare($conn, "UPDATE assignments SET status = 'checked_out', checkout_date = CURDATE() WHERE id = ?");
        mysqli_stmt_bind_param($ua, 'i', $assignment['id']);
        mysqli_stmt_execute($ua);
        mysqli_stmt_close($ua);

        $st = 'checked_out';
        $ub = mysqli_prepare($conn, 'UPDATE bookings SET status = ? WHERE id = ?');
        mysqli_stmt_bind_param($ub, 'si', $st, $bookingId);
        mysqli_stmt_execute($ub);
        mysqli_stmt_close($ub);

        mysqli_commit($conn);
        return ['ok' => true, 'message' => 'Bed released and application marked as checked out.'];
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        return ['ok' => false, 'message' => $ex->getMessage()];
    }
}

/** Next booking reference, e.g. HB-2026-00042 */
function next_reference(): string
{
    global $conn;
    $year = date('Y');
    $res  = mysqli_query($conn, "SELECT COUNT(*) c FROM bookings WHERE reference_no LIKE 'HB-$year-%'");
    $row  = $res ? mysqli_fetch_assoc($res) : ['c' => 0];
    return sprintf('HB-%s-%05d', $year, ((int)$row['c']) + 1);
}

/* =====================================================================
   7. FILE UPLOADS
   ===================================================================== */

function store_document(array $file, int $bookingId, string $docType, int $userId): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => upload_error_message((int)$file['error'])];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'message' => 'File is too large. Maximum allowed size is 3 MB.'];
    }

    $allowed = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'message' => 'Only PDF, JPG, PNG, DOC or DOCX files are allowed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed[$ext], true)) {
        return ['ok' => false, 'message' => 'The file content does not match its extension.'];
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $stored = 'doc_' . $bookingId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
        return ['ok' => false, 'message' => 'Could not save the uploaded file on the server.'];
    }
    @chmod(UPLOAD_DIR . '/' . $stored, 0640);

    global $conn;
    $orig = basename($file['name']);
    $size = (int)$file['size'];
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO documents (booking_id, uploaded_by, doc_type, original_name, stored_name, mime_type, file_size)
         VALUES (?,?,?,?,?,?,?)'
    );
    mysqli_stmt_bind_param($stmt, 'iissssi', $bookingId, $userId, $docType, $orig, $stored, $mime, $size);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return ['ok' => true, 'message' => 'Document "' . $orig . '" uploaded successfully.', 'id' => mysqli_insert_id($conn)];
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the server size limit.',
        UPLOAD_ERR_PARTIAL   => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE   => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the file to disk.',
        default => 'Unknown upload error.',
    };
}

function human_filesize(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

/* =====================================================================
   8. UI HELPERS
   ===================================================================== */

/** Material Symbols-style inline SVG icon set (no external fonts needed). */
function icon(string $name, string $class = 'icon'): string
{
    $paths = [
        'check_circle' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.2 14.2-3.9-3.9 1.4-1.4 2.5 2.5 5.3-5.3 1.4 1.4-6.7 6.7Z"/>',
        'error'        => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/>',
        'warning'      => '<path d="M1 21h22L12 2 1 21Zm12-3h-2v-2h2v2Zm0-4h-2v-4h2v4Z"/>',
        'info'         => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-6h2v6Zm0-8h-2V7h2v2Z"/>',
        'home'         => '<path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8h5Z"/>',
        'bed'          => '<path d="M21 10H7V7a2 2 0 0 0-2-2H3v14h2v-3h16a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2Zm-9 4H7v-2h5v2Zm8 0h-6v-2h6v2Z"/>',
        'search'       => '<path d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5Zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14Z"/>',
        'person'       => '<path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5Z"/>',
        'people'       => '<path d="M16 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05A4.22 4.22 0 0 1 17 16.5V19h6v-2.5c0-2.33-4.67-3.5-7-3.5Z"/>',
        'logout'       => '<path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5ZM4 5h8V3H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H4V5Z"/>',
        'notifications' => '<path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2Zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 0 0-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2Z"/>',
        'assignment'   => '<path d="M19 3h-4.18A3 3 0 0 0 12 1a3 3 0 0 0-2.82 2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm-7 0a1 1 0 1 1-1 1 1 1 0 0 1 1-1Zm-2 14-4-4 1.41-1.41L10 14.17l6.59-6.58L18 9l-8 8Z"/>',
        'apartment'    => '<path d="M17 11V3H7v4H3v14h8v-4h2v4h8V11h-4ZM7 19H5v-2h2v2Zm0-4H5v-2h2v2Zm0-4H5V9h2v2Zm4 4H9v-2h2v2Zm0-4H9V9h2v2Zm0-4H9V5h2v2Zm4 8h-2v-2h2v2Zm0-4h-2V9h2v2Zm0-4h-2V5h2v2Zm4 12h-2v-2h2v2Zm0-4h-2v-2h2v2Z"/>',
        'dashboard'    => '<path d="M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z"/>',
        'add'          => '<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2Z"/>',
        'close'        => '<path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41Z"/>',
        'upload'       => '<path d="M9 16h6v-6h4l-7-7-7 7h4v6Zm-4 2h14v2H5v-2Z"/>',
        'download'     => '<path d="M19 9h-4V3H9v6H5l7 7 7-7ZM5 18v2h14v-2H5Z"/>',
        'print'        => '<path d="M19 8H5a3 3 0 0 0-3 3v6h4v4h12v-4h4v-6a3 3 0 0 0-3-3Zm-3 11H8v-5h8v5Zm3-7a1 1 0 1 1 1-1 1 1 0 0 1-1 1Zm-1-9H6v4h12V3Z"/>',
        'filter'       => '<path d="M10 18h4v-2h-4v2ZM3 6v2h18V6H3Zm3 7h12v-2H6v2Z"/>',
        'menu'         => '<path d="M3 18h18v-2H3v2Zm0-5h18v-2H3v2ZM3 6v2h18V6H3Z"/>',
        'arrow_back'   => '<path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2Z"/>',
        'visibility'   => '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5Zm0 12.5a5 5 0 1 1 5-5 5 5 0 0 1-5 5Zm0-8a3 3 0 1 0 3 3 3 3 0 0 0-3-3Z"/>',
        'lock'         => '<path d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2ZM9 6a3 3 0 0 1 6 0v2H9V6Z"/>',
        'mail'         => '<path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/>',
        'calendar'     => '<path d="M19 3h-1V1h-2v2H8V1H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm0 16H5V8h14v11Z"/>',
        'attach'       => '<path d="M16.5 6v11.5a4 4 0 1 1-8 0V5a2.5 2.5 0 0 1 5 0v10.5a1 1 0 1 1-2 0V6H10v9.5a2.5 2.5 0 0 0 5 0V5a4 4 0 1 0-8 0v12.5a5.5 5.5 0 0 0 11 0V6h-1.5Z"/>',
        'chart'        => '<path d="M5 9.2h3V19H5V9.2ZM10.6 5h2.8v14h-2.8V5Zm5.6 8H19v6h-2.8v-6Z"/>',
        'settings'     => '<path d="M19.14 12.94a7.07 7.07 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.61-.22l-2.39.96a7.03 7.03 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.5.5 0 0 0-.61.22L2.65 8.84a.5.5 0 0 0 .12.64l2.03 1.58a7.07 7.07 0 0 0 0 1.88l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.23.39.33.61.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54a7.03 7.03 0 0 0 1.63-.94l2.39.96c.22.11.48 0 .61-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58ZM12 15.5A3.5 3.5 0 1 1 15.5 12 3.5 3.5 0 0 1 12 15.5Z"/>',
        'key'          => '<path d="M12.65 10A6 6 0 1 0 6 16a5.94 5.94 0 0 0 4.35-1.9l1.4 1.4L10.5 17H13v2.5h2.5V22H19l1-1v-4.35l-5.65-5.65A5.97 5.97 0 0 0 12.65 10ZM6 14a2 2 0 1 1 2-2 2 2 0 0 1-2 2Z"/>',
        'shield'       => '<path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4Zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8Z"/>',
        'layers'       => '<path d="m11.99 18.54-7.37-5.73L3 14.07l9 7 9-7-1.63-1.27-7.38 5.74ZM12 16l7.36-5.73L21 9l-9-7-9 7 1.63 1.27L12 16Z"/>',
        'money'        => '<path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4Z"/>',
        'check'        => '<path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17Z"/>',
        'delete'       => '<path d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12ZM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4Z"/>',
        'edit'         => '<path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25ZM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83Z"/>',
        'wifi'         => '<path d="M1 9l2 2a12.7 12.7 0 0 1 18 0l2-2A15.6 15.6 0 0 0 1 9Zm8 8 3 3 3-3a4.2 4.2 0 0 0-6 0Zm-4-4 2 2a7.1 7.1 0 0 1 10 0l2-2A9.9 9.9 0 0 0 5 13Z"/>',
        'bolt'         => '<path d="M11 21h-1l1-7H7.5a.86.86 0 0 1-.79-1.11C7.3 11.13 8.6 7.83 11 2h1l-1 7h3.5a.86.86 0 0 1 .79 1.11c-.59 1.76-1.9 5.06-4.29 10.89Z"/>',
        'local_laundry' => '<path d="M18 2.01 6 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V4a2 2 0 0 0-2-1.99ZM18 20H6v-9.02h12V20Zm0-11H6V4h12v5ZM8 5h2v3H8V5Zm2 9a3 3 0 1 0 3 3 3 3 0 0 0-3-3Z"/>',
        'security'     => '<path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4Z"/>',
        'ac_unit'      => '<path d="M22 11h-4.17l3.24-3.24-1.41-1.42L15 11h-2V9l4.66-4.66-1.42-1.41L13 6.17V2h-2v4.17L7.76 2.93 6.34 4.34 11 9v2H9L4.34 6.34 2.93 7.76 6.17 11H2v2h4.17l-3.24 3.24 1.41 1.42L9 13h2v2l-4.66 4.66 1.42 1.41L11 17.83V22h2v-4.17l3.24 3.24 1.42-1.41L13 15v-2h2l4.66 4.66 1.41-1.42L17.83 13H22v-2Z"/>',
        'restaurant'   => '<path d="M11 9H9V2H7v7H5V2H3v7a4 4 0 0 0 4 4v9h2v-9a4 4 0 0 0 4-4V2h-2v7Zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4Z"/>',
        'directions_car' => '<path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1h12v1a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-8l-2.08-5.99ZM6.5 16a1.5 1.5 0 1 1 1.5-1.5A1.5 1.5 0 0 1 6.5 16Zm11 0a1.5 1.5 0 1 1 1.5-1.5 1.5 1.5 0 0 1-1.5 1.5ZM5 11l1.5-4.5h11L19 11H5Z"/>',
        'fitness'      => '<path d="M20.57 14.86 22 13.43 20.57 12 17 15.57 8.43 7 12 3.43 10.57 2 9.14 3.43 7.71 2 5.57 4.14 4.14 2.71 2.71 4.14l1.43 1.43L2 7.71l1.43 1.43L2 10.57 3.43 12 7 8.43 15.57 17 12 20.57 13.43 22l1.43-1.43L16.29 22l2.14-2.14 1.43 1.43 1.43-1.43-1.43-1.43L22 16.29 20.57 14.86Z"/>',
        'hot_tub'      => '<path d="M7 6a2 2 0 1 0-2-2 2 2 0 0 0 2 2Zm12.65 8.65A4.99 4.99 0 0 0 17 12.07V7a1 1 0 0 0-2 0v5.07a3 3 0 0 1-6 0V7a1 1 0 0 0-2 0v5.07a4.99 4.99 0 0 0-2.65 2.58L2 22h20l-2.35-7.35Z"/>',
        'description'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm2 16H8v-2h8v2Zm0-4H8v-2h8v2Zm-3-5V3.5L18.5 9H13Z"/>',
        'history'      => '<path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6a7 7 0 1 1 2.05 4.95l-1.42 1.42A9 9 0 1 0 13 3Zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12Z"/>',
    ];

    $d = $paths[$name] ?? $paths['info'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Status chip for booking states. */
function status_chip(string $status): string
{
    $map = [
        'pending'     => ['Pending review', 'chip-warning'],
        'approved'    => ['Approved', 'chip-info'],
        'rejected'    => ['Rejected', 'chip-danger'],
        'assigned'    => ['Room assigned', 'chip-success'],
        'cancelled'   => ['Cancelled', 'chip-neutral'],
        'checked_out' => ['Checked out', 'chip-neutral'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'chip-neutral'];
    return '<span class="chip ' . $cls . '"><span class="chip-dot"></span>' . e($label) . '</span>';
}

/** Split a comma separated facility list into chips. */
function facility_chips(?string $facilities): string
{
    if (!$facilities) return '';
    $out = '';
    foreach (array_filter(array_map('trim', explode(',', $facilities))) as $f) {
        $out .= '<span class="chip chip-outline">' . icon_for_facility($f) . e($f) . '</span>';
    }
    return $out;
}

function icon_for_facility(string $facility): string
{
    $f = strtolower($facility);
    return match (true) {
        str_contains($f, 'wi-fi'), str_contains($f, 'wifi'), str_contains($f, 'internet') => icon('wifi', 'icon icon-xs'),
        str_contains($f, 'air'), str_contains($f, 'ac'), str_contains($f, 'cool')          => icon('ac_unit', 'icon icon-xs'),
        str_contains($f, 'laundry'), str_contains($f, 'wash')                              => icon('local_laundry', 'icon icon-xs'),
        str_contains($f, 'security'), str_contains($f, 'cctv'), str_contains($f, 'guard')  => icon('security', 'icon icon-xs'),
        str_contains($f, 'gym'), str_contains($f, 'fitness')                               => icon('fitness', 'icon icon-xs'),
        str_contains($f, 'canteen'), str_contains($f, 'kitchen'), str_contains($f, 'dining') => icon('restaurant', 'icon icon-xs'),
        str_contains($f, 'parking')                                                        => icon('directions_car', 'icon icon-xs'),
        str_contains($f, 'power'), str_contains($f, 'generator'), str_contains($f, 'electric') => icon('bolt', 'icon icon-xs'),
        default => icon('check', 'icon icon-xs'),
    };
}

/** Render a vacancy progress bar. */
function occupancy_bar(int $occupied, int $capacity): string
{
    $capacity = max(1, $capacity);
    $pct = (int)round(($occupied / $capacity) * 100);
    $tone = $pct >= 100 ? 'bar-full' : ($pct >= 70 ? 'bar-high' : 'bar-low');
    return '<div class="bar" role="img" aria-label="' . $occupied . ' of ' . $capacity . ' beds occupied">'
         . '<span class="bar-fill ' . $tone . '" style="width:' . $pct . '%"></span></div>';
}

/* =====================================================================
   9. QUERY / TABLE HELPERS
   ===================================================================== */

/** Run a prepared SELECT and return all rows as assoc arrays. */
function db_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '' && $params !== []) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** Run a prepared SELECT and return the first row (or null). */
function db_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_all($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

/** Run a prepared write statement; returns affected rows. */
function db_exec(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '' && $params !== []) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

/** Whitelist helper so user input can never inject an ORDER BY / column. */
function safe_choice(string $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function get_str(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
}

/** Export an associative-array result set as a CSV download. */
function csv_export(string $filename, array $rows, array $headers): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));   // UTF-8 BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($headers) as $i => $h) {
            $line[] = $row[$h] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}
