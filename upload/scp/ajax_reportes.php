<?php

$root_path = dirname(__FILE__) . '/../';
// ajax_reportes.php - Handler simple para reportes Jasper
// Endpoints:
//   GET  ?test=ping|java|jar|session
//   POST action=generate con datos del reporte

require_once('staff.inc.php');

if (!$thisstaff || !$thisstaff->isAdmin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acceso denegado';
    exit;
}

$reports_dir = realpath(ROOT_PATH . 'reports') . DIRECTORY_SEPARATOR;
$jar_path    = $reports_dir . 'java' . DIRECTORY_SEPARATOR . 'osticket-reporter.jar';
$temp_dir    = $reports_dir . 'temp' . DIRECTORY_SEPARATOR;
$output_dir  = $reports_dir . 'output' . DIRECTORY_SEPARATOR;

if (!is_dir($temp_dir)) @mkdir($temp_dir, 0775, true);
if (!is_dir($output_dir)) @mkdir($output_dir, 0775, true);

function respond_text($message, $status = 200) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $message;
    exit;
}

function respond_json($payload, $status = 200) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    exit;
}

// ---------- Tests ----------
if (isset($_GET['test'])) {
    switch ($_GET['test']) {
        case 'ping':
            respond_text('PONG ' . date('Y-m-d H:i:s'));
        case 'java':
            $out = [];
            $rc = 0;
            exec('java -version 2>&1', $out, $rc);
            $msg = implode("\n", $out);
            respond_text($rc === 0 ? "OK\n{$msg}" : "ERROR (code {$rc})\n{$msg}", $rc === 0 ? 200 : 500);
        case 'jar':
            if (file_exists($jar_path)) {
                $size = round(filesize($jar_path) / 1024 / 1024, 2);
                respond_text("OK jar encontrado ({$size} MB)");
            }
            respond_text('ERROR jar no encontrado en ' . $jar_path, 500);
        case 'session':
            $active = isset($_SESSION['_staff']['id']);
            respond_text($active ? 'OK session ' . $_SESSION['_staff']['id'] : 'ERROR session', $active ? 200 : 500);
        default:
            respond_text('ERROR test desconocido', 400);
    }
}

// ---------- Generación de reporte ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond_json(['error' => 'Método no soportado'], 405);
}

db_autocommit(false);

$tipo         = trim($_POST['tipo'] ?? 'tickets');
$desde        = trim($_POST['fecha_desde'] ?? '');
$hasta        = trim($_POST['fecha_hasta'] ?? '');
$departamento = trim($_POST['departamento'] ?? '');
$estado       = trim($_POST['estado'] ?? '');
$formato      = strtolower(trim($_POST['formato'] ?? 'pdf'));

if (!$desde || !$hasta || !$formato) {
    respond_json(['error' => 'Campos requeridos: fecha_desde, fecha_hasta, formato'], 400);
}

if (!strtotime($desde) || !strtotime($hasta)) {
    respond_json(['error' => 'Formato de fecha inválido'], 400);
}

$sql = "SELECT 
    t.ticket_id   AS id,
    t.number      AS numero,
    t.subject     AS asunto,
    ts.name       AS estado,
    DATE_FORMAT(t.created, '%Y-%m-%d %H:%i:%s') AS fecha_creacion,
    u.name        AS cliente,
    d.dept_name   AS departamento
FROM ost_ticket t
LEFT JOIN ost_ticket_status ts ON t.status_id = ts.id
LEFT JOIN ost_user u ON t.user_id = u.id
LEFT JOIN ost_department d ON t.dept_id = d.dept_id
WHERE t.created BETWEEN ? AND ?";

$params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

if ($departamento !== '') {
    $sql .= ' AND t.dept_id = ?';
    $params[] = $departamento;
}
if ($estado !== '') {
    $sql .= ' AND t.status_id = ?';
    $params[] = $estado;
}

$sql .= ' ORDER BY t.created DESC LIMIT 2000';

$stmt = db_query($sql, false, true, $params);
if ($stmt === false) {
    db_rollback();
    respond_json(['error' => 'Error al consultar base de datos'], 500);
}

$rows = [];
while ($row = db_fetch_array($stmt)) {
    $rows[] = $row;
}

// Generar XML de entrada
$xml_file = $temp_dir . uniqid('report_', true) . '.xml';
$xml = new DOMDocument('1.0', 'UTF-8');
$xml->formatOutput = true;
$root = $xml->createElement('tickets');
foreach ($rows as $r) {
    $ticket = $xml->createElement('ticket');
    foreach ($r as $k => $v) {
        $ticket->appendChild($xml->createElement($k, htmlspecialchars($v ?? '', ENT_XML1 | ENT_COMPAT, 'UTF-8')));
    }
    $root->appendChild($ticket);
}
$xml->appendChild($root);
$xml->save($xml_file);

// Ejecutar JAR
$filename = 'reporte_' . $tipo . '_' . date('Ymd_His') . '.' . $formato;
$output_file = $output_dir . $filename;
$cmd = 'java -jar ' . escapeshellarg($jar_path) . ' ' . escapeshellarg($xml_file) . ' ' . escapeshellarg($tipo) . ' ' . escapeshellarg($formato) . ' ' . escapeshellarg($output_file);
exec($cmd . ' 2>&1', $cmd_out, $rc);

db_autocommit(true);

if ($rc !== 0 || !file_exists($output_file) || filesize($output_file) === 0) {
    @unlink($xml_file);
    respond_json([
        'error'  => 'Fallo ejecutando JAR',
        'code'   => $rc,
        'output' => $cmd_out,
        'cmd'    => $cmd,
    ], 500);
}

@unlink($xml_file);
$download = ROOT_PATH . 'reports/output/' . $filename;
respond_json([
    'ok'       => true,
    'download' => $download,
    'file'     => $filename,
]);