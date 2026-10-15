<?php
require __DIR__ . '/_common.php';
require_admin();

$file = $_GET['file'] ?? '';
$file = str_replace(['..', '\\'], ['', '/'], $file);
$file = ltrim($file, '/');

if (empty($file)) {
    http_response_code(400);
    exit('File parameter missing');
}

$fullPath = realpath($CONFIG['upload_dir'] . '/' . $file);
$baseUpload = realpath($CONFIG['upload_dir']);

if (!$fullPath || strpos($fullPath, $baseUpload) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('Photo file not found on server');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) ? "image/{$ext}" : 'application/octet-stream';
$dlName = basename($fullPath);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=86400');

readfile($fullPath);
exit;
