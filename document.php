<?php
/**
 * document.php - Authorised download of an uploaded supporting document.
 * The uploads folder sits outside the web-served tree logic of the app and is
 * never linked directly; access is checked against the session user.
 */
require_once __DIR__ . '/includes/functions.php';

$me = require_login();
$id = get_int('id');

$doc = db_one($conn, 'SELECT d.*, b.student_id AS booking_owner FROM documents d JOIN bookings b ON b.id = d.booking_id WHERE d.id = ?', 'i', [$id]);

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

// Owner (student), uploader, or any administrator may download.
$allowed = is_admin() || (int)$doc['uploaded_by'] === (int)$me['id'] || (int)$doc['booking_owner'] === (int)$me['id'];
if (!$allowed) {
    http_response_code(403);
    exit('You are not allowed to download this document.');
}

$path = UPLOAD_DIR . '/' . basename($doc['stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('The stored file is missing from the server.');
}

$mime = $doc['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
exit;
