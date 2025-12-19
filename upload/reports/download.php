<?php
// Simple download proxy for reports/output with safe headers
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

$baseDir = __DIR__ . '/output/';
$file = isset($_GET['file']) ? $_GET['file'] : '';

// Basic sanitization: allow only basename and disallow path traversal
$file = basename($file);
$path = $baseDir . $file;

if (!$file || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Archivo no encontrado']);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf' => 'application/pdf',
    'html' => 'text/html; charset=utf-8',
    'csv' => 'text/csv; charset=utf-8',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel'
];
$contentType = isset($contentTypes[$ext]) ? $contentTypes[$ext] : 'application/octet-stream';

// Force download for non-HTML; for HTML, could inline, but keeping attachment for consistency
$inline = ($ext === 'html');
$disposition = $inline ? 'inline' : 'attachment';

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $contentType);
header('Content-Disposition: ' . $disposition . '; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));

// Stream file
$fp = fopen($path, 'rb');
if ($fp) {
    fpassthru($fp);
    fclose($fp);
    exit;
}
readfile($path);
exit;
