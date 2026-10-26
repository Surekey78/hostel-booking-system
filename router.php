<?php
/**
 * router.php - Request router for PHP's built-in development server.
 *
 *   php -S 0.0.0.0:8080 router.php
 *
 * Serves real files directly and blocks direct HTTP access to /includes and
 * /uploads (uploaded documents are streamed through document.php instead).
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// Deny direct access to sensitive directories.
foreach (['/includes/', '/uploads/', '/database/'] as $blocked) {
    if (str_starts_with($path . '/', $blocked) || str_contains($path, $blocked)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden — this path is not served directly.\n";
        return true;
    }
}

// Serve existing static files (css, js, images) as-is.
if ($path !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'ico' => 'image/x-icon', 'json' => 'application/json', 'woff2' => 'font/woff2',
    ][$ext] ?? null;
    if ($mime) {
        header('Content-Type: ' . $mime);
        readfile($file);
        return true;
    }
    return false; // let PHP handle .php and anything else
}

// Directory requests fall back to index.php.
if ($path === '/' || is_dir($file)) {
    require __DIR__ . ($path === '/' ? '/index.php' : rtrim($path, '/') . '/index.php');
    return true;
}

http_response_code(404);
require __DIR__ . '/index.php';
return true;
