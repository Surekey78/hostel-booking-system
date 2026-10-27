<?php









$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;


foreach (['/includes/', '/uploads/', '/database/'] as $blocked) {
    if (str_starts_with($path . '/', $blocked) || str_contains($path, $blocked)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden — this path is not served directly.\n";
        return true;
    }
}


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
    return false; 
}


if ($path === '/' || is_dir($file)) {
    require __DIR__ . ($path === '/' ? '/index.php' : rtrim($path, '/') . '/index.php');
    return true;
}

http_response_code(404);
require __DIR__ . '/index.php';
return true;
