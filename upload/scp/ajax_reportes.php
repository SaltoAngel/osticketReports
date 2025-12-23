<?php
// ajax_reportes.php - Versión simplificada usando exclusivamente JasperStarter (jasper-test)
// Mantiene la UI original del staff pero elimina motores alternos (JAR propio, PHP, PHPJasperXML, tests).

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ob_start();

// Capturar fatales y devolver JSON legible en UI
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Error fatal en el servidor',
            'debug' => ['type' => $error['type'], 'message' => $error['message'], 'file' => $error['file'], 'line' => $error['line']]
        ]);
    }
});

// Cargar entorno osTicket
define('DISABLE_CSRF', true);
define('NO_CSRF', true);
require_once('staff.inc.php');
ob_end_clean();

// Permisos
if (!$thisstaff || !$thisstaff->isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

// Utilidades de respuesta
function utf8ize($mixed) {
    if (is_array($mixed)) { foreach ($mixed as $k=>$v) { $mixed[$k] = utf8ize($v); } return $mixed; }
    if (is_string($mixed)) { return mb_convert_encoding($mixed, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII'); }
    return $mixed;
}
function json_response($data, $status = 200) {
    http_response_code($status);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    $payload = utf8ize($data);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    echo ($json === false) ? json_encode(['error' => 'Error codificando JSON', 'debug' => $payload]) : $json;
    exit;
}

// Acción auxiliar: obtener parámetros del JRXML para construir la UI dinámica
if (isset($_POST['action']) && $_POST['action'] === 'jrxml_params') {
    try {
        $tpl = isset($_POST['template']) && $_POST['template'] !== '' ? $_POST['template'] : ($_POST['tipo'] ?? '');
        $tpl = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$tpl);
        if ($tpl === '') json_response(['error' => 'Falta el nombre de la plantilla (template|tipo)'], 400);

        $jrxml_paths = [
            dirname(__FILE__) . '/../reports/java/templates/' . $tpl . '.jrxml',
            dirname(__FILE__) . '/../reports/templates/' . $tpl . '.jrxml',
            dirname(__FILE__) . '/../jasper-test/' . $tpl . '.jrxml'
        ];
        $jrxml_path = '';
        foreach ($jrxml_paths as $p) { if (is_file($p)) { $jrxml_path = $p; break; } }
        if ($jrxml_path === '') json_response(['error' => 'Plantilla JRXML no encontrada', 'template' => $tpl], 404);

        $dom = new DOMDocument();
        $dom->load($jrxml_path);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('jr', 'http://jasperreports.sourceforge.net/jasperreports');
        $nodes = $xp->query('//jr:parameter');
        $params = [];
        if ($nodes && $nodes->length) {
            foreach ($nodes as $param) {
                if (!$param instanceof DOMElement) continue;
                $name = $param->getAttribute('name');
                $class = $param->getAttribute('class') ?: 'java.lang.String';
                $promptAttr = $param->getAttribute('isForPrompting');
                $prompt = ($promptAttr === '' ? true : (strtolower($promptAttr) !== 'false'));
                if ($name === '' || preg_match('/^(REPORT_|JASPER_|SUBREPORT_DIR)/', $name)) continue;
                $default = null; $desc = '';
                foreach ($param->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'defaultValueExpression') {
                        $expr = trim($child->textContent);
                        if (strlen($expr) >= 2 && $expr[0] === '"' && substr($expr, -1) === '"') { $default = stripcslashes(substr($expr, 1, -1)); }
                        elseif (preg_match('/^(true|false)$/i', $expr)) { $default = (strtolower($expr) === 'true'); }
                        elseif (preg_match('/^-?\d+(\.\d+)?$/', $expr)) { $default = (strpos($expr, '.') !== false) ? (float)$expr : (int)$expr; }
                        else { $default = $expr; }
                    } elseif ($child instanceof DOMElement && $child->localName === 'parameterDescription') {
                        $desc = trim($child->textContent);
                    }
                }
                $type = 'string'; $lc = strtolower($class);
                if (strpos($lc, 'integer') !== false || strpos($lc, 'long') !== false || strpos($lc, 'short') !== false) $type = 'integer';
                elseif (strpos($lc, 'double') !== false || strpos($lc, 'float') !== false || strpos($lc, 'bigdecimal') !== false) $type = 'number';
                elseif (strpos($lc, 'boolean') !== false) $type = 'boolean';
                elseif (strpos($lc, 'date') !== false || strpos($lc, 'timestamp') !== false) $type = 'date';
                $params[] = ['name'=>$name,'class'=>$class,'type'=>$type,'prompt'=>$prompt,'default'=>$default,'description'=>$desc];
            }
        }
        json_response(['ok' => true, 'template' => $tpl, 'params' => $params]);
    } catch (Throwable $te) {
        json_response(['error' => 'Error analizando JRXML', 'details' => $te->getMessage()], 500);
    }
}

// SOLO POST para generar reporte con JasperStarter
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no soportado. Use POST.'], 405);
}

// Parámetros básicos
$tipo    = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['tipo'] ?? ''));
$desde   = $_POST['fecha_desde'] ?? '';
$hasta   = $_POST['fecha_hasta'] ?? '';
$formato = strtolower((string)($_POST['formato'] ?? 'pdf'));
$departamento = trim((string)($_POST['departamento'] ?? ''));
$estado = trim((string)($_POST['estado'] ?? ''));

if ($tipo === '' || $desde === '' || $hasta === '' || $formato === '') {
    json_response(['error' => 'Tipo, fechas y formato son requeridos'], 400);
}
if ($desde > $hasta) json_response(['error' => 'El rango de fechas es inválido (desde mayor que hasta)'], 422);

// Normalizar fechas a rangos día completo
$desde_dt = $desde . ' 00:00:00';
$hasta_dt = $hasta . ' 23:59:59';

// Ubicar JRXML
$root_path = dirname(__FILE__) . '/../';
$jrxml_candidates = [
    $root_path . 'reports/java/templates/' . $tipo . '.jrxml',
    $root_path . 'reports/templates/' . $tipo . '.jrxml',
    $root_path . 'jasper-test/' . $tipo . '.jrxml'
];
$jrxml_input = '';
foreach ($jrxml_candidates as $p) { if (is_file($p)) { $jrxml_input = $p; break; } }
if ($jrxml_input === '') json_response(['error' => 'Plantilla JRXML no encontrada para tipo: ' . $tipo], 404);

// JasperStarter paths (desde jasper-test)
$js_jar   = $root_path . 'jasper-test/jasperstarter/bin/jasperstarter.jar';
$js_mysql = $root_path . 'jasper-test/mysql-connector-java.jar';
if (!is_file($js_jar)) json_response(['error' => 'jasperstarter.jar no encontrado en jasper-test', 'path' => $js_jar], 500);

// Credenciales BD desde ost-config.php
$cfg_file = $root_path . 'include/ost-config.php';
$dbhost = 'localhost'; $dbname = ''; $dbuser = ''; $dbpass = ''; $dbport = '3306';
if (is_file($cfg_file)) {
    try {
        $content = @file_get_contents($cfg_file) ?: '';
        $get = function($name) use ($content) { $re = '/define\s*\(\s*\'' . $name . '\'\s*,\s*\'(.*?)\'\s*\)\s*;/i'; return preg_match($re, $content, $m) ? $m[1] : null; };
        $dbhost = $get('DBHOST') ?: $dbhost; $dbname = $get('DBNAME') ?: $dbname;
        $dbuser = $get('DBUSER') ?: $dbuser; $dbpass = $get('DBPASS') ?: $dbpass; $dbport = $get('DBPORT') ?: $dbport;
    } catch (Throwable $te) { /* usar defaults */ }
}

// Salida
$output_dir = $root_path . 'reports/output/';
if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);
$ts = date('Ymd_His');
$out_base = $output_dir . 'reporte_' . $tipo . '_' . $ts;

// Mapear formato
$fmt_map = ['pdf'=>'pdf','html'=>'html','xlsx'=>'xlsx','xls'=>'xlsx','docx'=>'docx','csv'=>'csv'];
$fmt = $fmt_map[$formato] ?? 'pdf';

// Construir comando JasperStarter
$cmd = 'java -jar "' . $js_jar . '"';
$cmd .= ' --locale es_ES';
$cmd .= ' pr "' . $jrxml_input . '"';
$cmd .= ' -o "' . $out_base . '"';
$cmd .= ' -f ' . $fmt;
$cmd .= ' -t mysql';
$cmd .= ' -u ' . $dbuser;
$cmd .= ' -p ' . $dbpass;
$cmd .= ' -H ' . $dbhost;
$cmd .= ' -n ' . $dbname;
$cmd .= ' --db-port ' . $dbport;
if (is_file($js_mysql)) $cmd .= ' --jdbc-dir "' . dirname($js_mysql) . '"';

// Parámetros JRXML
$params_to_pass = [
    'fecha_desde' => $desde_dt,
    'fecha_hasta' => $hasta_dt,
    'FECHA_DESDE' => $desde_dt,
    'FECHA_HASTA' => $hasta_dt,
];
if ($departamento !== '') $params_to_pass['departamento'] = (int)$departamento;
if ($estado !== '') $params_to_pass['estado'] = (int)$estado;
if (isset($_POST['param']) && is_array($_POST['param'])) {
    foreach ($_POST['param'] as $k=>$v) { if ($v !== '') $params_to_pass[$k] = $v; }
}
foreach ($params_to_pass as $k=>$v) { $cmd .= ' -P ' . $k . '="' . str_replace('"','', (string)$v) . '"'; }
$cmd .= ' 2>&1';

$debug = [
    'jrxml' => str_replace($root_path, '', $jrxml_input),
    'cmd' => $cmd,
];

exec($cmd, $js_out, $js_rc);
$debug['out'] = implode("\n", $js_out);

// Detectar archivo generado
$generated = null;
$cand = [$out_base . '.' . $fmt, $out_base . '.' . strtoupper($fmt), $out_base];
foreach ($cand as $c) { if (file_exists($c)) { $generated = $c; break; } }
if (!$generated) { $g = glob($out_base . '.*') ?: []; if (!empty($g)) $generated = $g[0]; }

if ($js_rc !== 0 || !$generated || filesize($generated) === 0) {
    json_response(['error' => 'Error JasperStarter', 'code' => $js_rc, 'debug' => $debug], 500);
}

$filename = basename($generated);
$is_html = (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'html');
$download_url = $is_html ? ('../reports/output/' . $filename) : ('../reports/download.php?file=' . rawurlencode($filename));

json_response([
    'ok' => true,
    'engine' => 'jasperstarter',
    'download' => $download_url,
    'file' => $filename,
    'debug' => $debug
]);

?><?php
// =================================================================
// CONFIGURACIÓN
// =================================================================
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ob_start();

// Capturar fatales y devolver JSON legible en UI
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Error fatal en el servidor',
            'debug' => ['type' => $error['type'], 'message' => $error['message'], 'file' => $error['file'], 'line' => $error['line']]
        ]);
    }
});

// =================================================================
// CARGAR OS TICKET
// =================================================================
$root_path = dirname(__FILE__) . '/../';

// IMPORTANTE: Definir esto ANTES de incluir staff.inc.php
define('DISABLE_CSRF', true);
define('NO_CSRF', true);

require_once('staff.inc.php');
ob_end_clean();

// =================================================================
// VERIFICAR PERMISOS
// =================================================================
if (!$thisstaff || !$thisstaff->isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

// =================================================================
// FUNCIONES DE RESPUESTA
// =================================================================
function utf8ize($mixed) {
    if (is_array($mixed)) {
        foreach ($mixed as $key => $value) {
            $mixed[$key] = utf8ize($value);
        }
        return $mixed;
    }
    if (is_string($mixed)) {
        // Convert to UTF-8 safely to avoid json_encode failures
        return mb_convert_encoding($mixed, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
    }
    return $mixed;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = utf8ize($data);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['error' => 'Error codificando JSON', 'debug' => $payload]);
    }
    echo $json;
    exit;
}

function text_response($message, $status = 200) {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

// =================================================================
// TESTS COMPLETOS
// =================================================================
if (isset($_GET['test'])) {
    switch ($_GET['test']) {
        case 'ping':
            text_response('PONG ' . date('Y-m-d H:i:s'));
            
        case 'java':
            $out = [];
            $rc = 0;
            exec('java -version 2>&1', $out, $rc);
            $msg = implode("\n", $out);
            text_response($rc === 0 ? "OK\n{$msg}" : "ERROR (code {$rc})\n{$msg}", $rc === 0 ? 200 : 500);
            
        case 'jar':
            $jar_path = dirname(__FILE__) . '/../reports/java/osticket-reporter.jar';
            if (file_exists($jar_path)) {
                $size = round(filesize($jar_path) / 1024 / 1024, 2);
                text_response("OK jar encontrado ({$size} MB)");
            }
            text_response('ERROR jar no encontrado en ' . $jar_path, 500);
            
        case 'check_driver':
            $jar_dir = dirname(__FILE__) . '/../reports/java/';
            $lib_dir = $jar_dir . 'lib' . DIRECTORY_SEPARATOR;
            $driver_present = false;
            $driver_name = null;
            if (is_dir($lib_dir)) {
                $patterns = [
                    'mysql-connector-j-*.jar',
                    'mysql-connector-java-*.jar',
                    'mysql-connector-*.jar',
                    'mariadb-java-client-*.jar'
                ];
                foreach ($patterns as $pat) {
                    foreach (glob($lib_dir . $pat) ?: [] as $f) {
                        if (is_file($f)) { $driver_present = true; $driver_name = basename($f); break 2; }
                    }
                }
            }
            json_response([
                'ok' => true,
                'jdbc_driver_present' => $driver_present,
                'jdbc_driver' => $driver_name,
                'hint' => 'Coloca mysql-connector-j-<versión>.jar en upload/reports/java/lib/'
            ]);
            
        case 'session':
            $active = isset($_SESSION['_staff']['id']);
            text_response($active ? 'OK session ' . $_SESSION['_staff']['id'] : 'ERROR session', $active ? 200 : 500);
            
        case 'db_simple':
            $result = db_query("SELECT COUNT(*) as total FROM ost_ticket");
            $row = db_fetch_array($result);
            text_response("Tickets totales: " . ($row['total'] ?? 0));
            
        case 'db_real':
            $sql = "SELECT ticket_id, number, subject, created 
                    FROM ost_ticket 
                    ORDER BY created DESC 
                    LIMIT 5";
            $result = db_query($sql);
            
            $output = "ÚLTIMOS 5 TICKETS:\n";
            while ($row = db_fetch_array($result)) {
                $output .= "#{$row['number']}: {$row['subject']} - {$row['created']}\n";
            }
            text_response($output);
            
        case 'db_test_dates':
            $desde = '2024-01-01 00:00:00';
            $hasta = '2025-12-31 23:59:59';
            
            $sql = "SELECT COUNT(*) as count FROM ost_ticket 
                    WHERE created BETWEEN '{$desde}' AND '{$hasta}'";
            $result = db_query($sql);
            
            if ($result === false) {
                text_response("❌ Error en consulta con BETWEEN");
            } else {
                $row = db_fetch_array($result);
                $count = $row['count'] ?? 0;
                text_response("✅ Tickets entre {$desde} y {$hasta}: {$count}");
            }
            break;

        case 'excel':
            $root_path = dirname(__FILE__) . '/../';
            $autoload = $root_path . 'vendor/autoload.php';
            $hasComposer = file_exists($autoload);
            $psClass = class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet');
            if (!$psClass) {
                $psBase = $root_path . 'lib/PhpSpreadsheet/src/';
                $psClass = is_dir($psBase);
            }
            $zipEnabled = extension_loaded('zip');
            $sxClass = class_exists('Shuchkin\\SimpleXLSXGen');
            if (!$sxClass) {
                $sx1 = $root_path . 'lib/SimpleXLSXGen.php';
                $sx2 = $root_path . 'lib/simplexlsxgen/SimpleXLSXGen.php';
                $sxClass = file_exists($sx1) || file_exists($sx2);
            }
            $msg = [];
            $msg[] = 'Composer: ' . ($hasComposer ? 'sí' : 'no');
            $msg[] = 'PhpSpreadsheet: ' . ($psClass ? 'disponible' : 'no');
            $msg[] = 'ZipArchive (xlsx): ' . ($zipEnabled ? 'sí' : 'no');
            $msg[] = 'SimpleXLSXGen: ' . ($sxClass ? 'disponible' : 'no');
            $ok = $psClass || $sxClass;
            text_response(($ok ? 'OK' : 'ERROR') . ' | ' . implode(' | ', $msg), $ok ? 200 : 500);
            break;
        case 'db_structure':
            header('Content-Type: text/plain; charset=utf-8');
            
            echo "=== ESTRUCTURA DE OST_TICKET ===\n\n";
            
            // 1. Verificar estructura
            $sql = "DESCRIBE ost_ticket";
            $result = db_query($sql);
            
            echo "1. CAMPOS EN ost_ticket:\n";
            while ($row = db_fetch_array($result)) {
                echo "   {$row['Field']} ({$row['Type']})\n";
            }
            
            echo "\n2. TEST CONSULTA SIMPLE:\n";
            $test_sql = "SELECT * FROM ost_ticket ORDER BY created DESC LIMIT 1";
            $test_result = db_query($test_sql);
            $test_row = db_fetch_array($test_result);
            
            echo "   Campos en primer ticket:\n";
            foreach ($test_row as $key => $value) {
                echo "   $key: " . (is_string($value) ? substr($value, 0, 50) : $value) . "\n";
            }
            
            echo "\n3. TEST CONSULTA CON FECHAS:\n";
            $date_sql = "SELECT ticket_id, number, subject, created FROM ost_ticket 
                        WHERE created >= '2024-01-01 00:00:00' 
                        AND created <= '2025-12-31 23:59:59' 
                        LIMIT 3";
            $date_result = db_query($date_sql);
            
            $count = 0;
            while ($date_row = db_fetch_array($date_result)) {
                $count++;
                echo "   Ticket {$count}: #{$date_row['number']} - {$date_row['subject']} - {$date_row['created']}\n";
            }
            echo "   Total encontrados: $count\n";
            
            exit;
            
        default:
            text_response('Test no reconocido: ' . $_GET['test'], 400);
    }
    exit;
}

// =================================================================
// GENERACIÓN DE REPORTE
// =================================================================

// DEBUG: Verificar método
error_log("=== INICIANDO AJAX_REPORTES.PHP ===");
error_log("Método HTTP: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));
error_log("GET data: " . print_r($_GET, true));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método no soportado. Use POST.', 
                   'method_received' => $_SERVER['REQUEST_METHOD']], 405);
}

// Manejo de acciones auxiliares antes de validaciones (p.ej. parámetros JRXML)
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
if ($action === 'jrxml_params') {
    try {
        $tpl = isset($_POST['template']) && $_POST['template'] !== '' ? $_POST['template'] : ($_POST['tipo'] ?? '');
        $tpl = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$tpl);
        if ($tpl === '') {
            json_response(['error' => 'Falta el nombre de la plantilla (template|tipo)'], 400);
        }

        $jrxml_paths = [
            dirname(__FILE__) . '/../reports/java/templates/' . $tpl . '.jrxml',
            dirname(__FILE__) . '/../reports/templates/' . $tpl . '.jrxml'
        ];
        $jrxml_path = '';
        foreach ($jrxml_paths as $p) { if (is_file($p)) { $jrxml_path = $p; break; } }
        if ($jrxml_path === '') {
            json_response(['error' => 'Plantilla JRXML no encontrada', 'template' => $tpl], 404);
        }

        $dom = new DOMDocument();
        $dom->load($jrxml_path);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('jr', 'http://jasperreports.sourceforge.net/jasperreports');
        $nodes = $xp->query('//jr:parameter');
        $params = [];
        if ($nodes && $nodes->length) {
            foreach ($nodes as $param) {
                if (!$param instanceof DOMElement) continue;
                $name = $param->getAttribute('name');
                $class = $param->getAttribute('class') ?: 'java.lang.String';
                $promptAttr = $param->getAttribute('isForPrompting');
                $prompt = ($promptAttr === '' ? true : (strtolower($promptAttr) !== 'false'));

                // Excluir parámetros internos/comunes
                if ($name === '' ||
                    preg_match('/^(REPORT_|JASPER_|SUBREPORT_DIR)/', $name) ||
                    in_array($name, ['logo','REPORT_TITLE','GENERATED_DATE','COMPANY_NAME'], true)) {
                    continue;
                }

                // defaultValueExpression
                $default = null; $desc = '';
                foreach ($param->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'defaultValueExpression') {
                        $expr = trim($child->textContent);
                        // "literal"
                        if (strlen($expr) >= 2 && $expr[0] === '"' && substr($expr, -1) === '"') {
                            $default = stripcslashes(substr($expr, 1, -1));
                        } elseif (preg_match('/^(true|false)$/i', $expr)) {
                            $default = (strtolower($expr) === 'true');
                        } elseif (preg_match('/^-?\d+(\.\d+)?$/', $expr)) {
                            $default = (strpos($expr, '.') !== false) ? (float)$expr : (int)$expr;
                        } else {
                            $default = $expr; // expresión compleja: devolver cruda
                        }
                    } elseif ($child instanceof DOMElement && $child->localName === 'parameterDescription') {
                        $desc = trim($child->textContent);
                    }
                }

                // Mapear clase a tipo simple
                $type = 'string';
                $lc = strtolower($class);
                if (strpos($lc, 'integer') !== false || strpos($lc, 'long') !== false || strpos($lc, 'short') !== false) { $type = 'integer'; }
                elseif (strpos($lc, 'double') !== false || strpos($lc, 'float') !== false || strpos($lc, 'bigdecimal') !== false) { $type = 'number'; }
                elseif (strpos($lc, 'boolean') !== false) { $type = 'boolean'; }
                elseif (strpos($lc, 'date') !== false || strpos($lc, 'timestamp') !== false) { $type = 'date'; }

                $params[] = [
                    'name' => $name,
                    'class' => $class,
                    'type' => $type,
                    'prompt' => $prompt,
                    'default' => $default,
                    'description' => $desc
                ];
            }
        }

        json_response(['ok' => true, 'template' => $tpl, 'params' => $params]);
    } catch (Throwable $te) {
        json_response(['error' => 'Error analizando JRXML', 'details' => $te->getMessage()], 500);
    }
}

// Obtener parámetros
$tipo    = $_POST['tipo'] ?? 'tickets';
$tipo    = preg_replace('/[^a-zA-Z0-9_-]/', '', $tipo);
$desde   = $_POST['fecha_desde'] ?? '';
$hasta   = $_POST['fecha_hasta'] ?? '';
$formato = strtolower($_POST['formato'] ?? 'pdf');
// Motor opcional para renderear directamente en PHP (sin JAR)
$engine  = strtolower($_POST['engine'] ?? '');
// Soporte opcional para JasperReports Server
$use_jrs = isset($_POST['use_jrs']) && (string)$_POST['use_jrs'] === '1';
// Forzar siempre datasource SQL (JDBC)
$datasource = 'sql';
$departamento = trim($_POST['departamento'] ?? '');
$estado = trim($_POST['estado'] ?? '');

$tickets_table = defined('TICKET_TABLE') ? TICKET_TABLE : TABLE_PREFIX . 'ticket';
// Tablas relacionadas para nombres legibles
$status_table = TABLE_PREFIX . 'ticket_status';
$dept_table = TABLE_PREFIX . 'department';
$user_table = TABLE_PREFIX . 'user';
$user_cdata_table = TABLE_PREFIX . 'user__cdata';
$user_email_table = TABLE_PREFIX . 'user_email';
$staff_table = TABLE_PREFIX . 'staff';
$team_table = TABLE_PREFIX . 'team';

// Validaciones
if (empty($desde) || empty($hasta) || empty($formato)) {
    json_response(['error' => 'Fechas y formato son requeridos'], 400);
}

// Validar rango coherente
if ($desde > $hasta) {
    json_response(['error' => 'El rango de fechas es inválido (desde mayor que hasta)'], 422);
}

// AGREGAR ESTO: Ajustar fechas si están fuera del rango real
// Primero obtener el rango REAL de la base de datos
$range_sql = "SELECT DATE(MIN(created)) as min_date, DATE(MAX(created)) as max_date FROM {$tickets_table}";
$range_result = db_query($range_sql);
$range_row = db_fetch_array($range_result);

$real_min = $range_row['min_date'] ?? '2024-04-23';
$real_max = $range_row['max_date'] ?? date('Y-m-d');

error_log("Rango real en BD: {$real_min} a {$real_max}");
error_log("Rango solicitado: {$desde} a {$hasta}");

// Si las fechas solicitadas están fuera del rango real, ajustarlas
if ($desde < $real_min) {
    error_log("Ajustando fecha desde {$desde} a {$real_min}");
    $desde = $real_min;
}

if ($hasta > $real_max) {
    error_log("Ajustando fecha hasta {$hasta} a {$real_max}");
    $hasta = $real_max;
}

// También puedes mostrar un warning al usuario
if ($desde != $_POST['fecha_desde'] || $hasta != $_POST['fecha_hasta']) {
    error_log("Fechas ajustadas automáticamente");
}

try {
    $debug_info = [];
    $debug_info['datasource'] = $datasource;
    $debug_info['datasource_forced'] = true;

    // =============================================================
    // RUTA ALTERNATIVA: JasperReports Server (si use_jrs=1)
    // =============================================================
    if ($engine === 'jrs' || $use_jrs) {
        $debug_info['engine'] = 'jasperserver';
        // Cargar configuración externa si existe
        $JRS = [
            'enabled' => true,
            'base_url' => 'http://localhost:8080/jasperserver', // ajustar
            'username' => 'jasperadmin', // ajustar
            'password' => 'jasperadmin', // ajustar
            'verify_ssl' => false,
            // Mapeo de tipos a URIs de report unit en el repo JRS
            'report_uri_map' => [
                // 'Tickets_Cerrados_por' => '/reports/osTicket/Tickets_Cerrados_por',
            ]
        ];
        $cfg_path = dirname(__FILE__) . '/../reports/jrs_config.php';
        if (is_file($cfg_path)) {
            try { $loaded = include($cfg_path); if (is_array($loaded)) { $JRS = array_replace_recursive($JRS, $loaded); } } catch (Throwable $te) {}
        }

        if (empty($JRS['base_url']) || empty($JRS['username']) || empty($JRS['password'])) {
            throw new Exception('Configuración de JasperReports Server incompleta. Defina base_url/username/password.');
        }

        // Determinar URI del reporte
        $uri = $JRS['report_uri_map'][$tipo] ?? ('/reports/' . $tipo);
        // Respetar formato solicitado (xlsx/xls/pdf/csv/html) y fallback a pdf
        $fmt_map = [
            'pdf' => 'pdf',
            'xlsx' => 'xlsx',
            'xls' => 'xls',
            'csv' => 'csv',
            'html' => 'html'
        ];
        $fmt = $fmt_map[$formato] ?? 'pdf';
        $accept_map = [
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'html' => 'text/html'
        ];
        $accept = $accept_map[$fmt] ?? 'application/octet-stream';
        $params = [
            // Parám. JRXML: ajuste los nombres aquí a los del Report Unit
            'fecha_desde' => $desde . ' 00:00:00',
            'fecha_hasta' => $hasta . ' 23:59:59',
        ];
        if ($departamento !== '') $params['departamento'] = (int)$departamento;
        if ($estado !== '') $params['estado'] = (int)$estado;

        // Construir URL REST v2 /reports
        $base = rtrim($JRS['base_url'], '/');
        $url = $base . '/rest_v2/reports' . $uri . '.' . $fmt;
        $debug_info['jrs_url'] = $url;
        $debug_info['jrs_format'] = $fmt;
        $debug_info['jrs_accept'] = $accept;
        // Realizar petición (GET con querystring de parámetros)
        $qs = http_build_query($params);
        $ch = curl_init($url . '?' . $qs);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $JRS['username'] . ':' . $JRS['password'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => [
                'Accept: ' . $accept
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($JRS['verify_ssl'] === false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $body = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $debug_info['jrs_http_code'] = $http_code;
        if ($err) $debug_info['jrs_error'] = $err;
        if ($body === false || $http_code >= 400) {
            throw new Exception('Error JasperReports Server (' . $http_code . '): ' . ($err ?: 'respuesta inválida'));
        }

        // Guardar salida en output/
        $output_dir = $root_path . 'reports/output/';
        if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);
        $out_ext = $fmt;
        $filename = 'reporte_' . $tipo . '_' . date('Ymd_His') . '.' . $out_ext;
        $output_file = $output_dir . $filename;
        file_put_contents($output_file, $body);

        $is_html = (strtolower($out_ext) === 'html');
        $download_url = $is_html
            ? ('../reports/output/' . $filename)
            : ('../reports/download.php?file=' . rawurlencode($filename));

        json_response([
            'ok' => true,
            'engine' => 'jasperserver',
            'download' => $download_url,
            'file' => $filename,
            'debug' => $debug_info
        ]);
    }
    // Intentar extraer la consulta del JRXML seleccionado
    try {
        $tpl_path = dirname(__FILE__) . '/../reports/java/templates/' . $tipo . '.jrxml';
        if (is_file($tpl_path)) {
            $jrxml_txt = @file_get_contents($tpl_path);
            $query_raw = null;
            if ($jrxml_txt !== false) {
                // Buscar contenido de <queryString><![CDATA[...]]></queryString>
                if (preg_match('/<queryString[^>]*><!\[CDATA\[(.*?)\]\]><\/queryString>/is', $jrxml_txt, $m)) {
                    $query_raw = $m[1];
                } elseif (preg_match('/<queryString[^>]*>(.*?)<\/queryString>/is', $jrxml_txt, $m)) {
                    $query_raw = html_entity_decode(trim($m[1]));
                }
                // Extraer posibles nombres de campos definidos en JRXML
                $fields = [];
                if (preg_match_all('/<field\s+name\s*=\s*"([^"]+)"/i', $jrxml_txt, $mf)) {
                    foreach ($mf[1] as $fn) { $fields[] = $fn; }
                }
                // También detectar desde expresiones $F{campo}
                if (preg_match_all('/\$F\{([^}]+)\}/', $jrxml_txt, $me)) {
                    foreach ($me[1] as $fn) { if (!in_array($fn, $fields)) $fields[] = $fn; }
                }
                if (!empty($fields)) { $debug_info['jrxml_fields'] = $fields; }
            }
            if ($query_raw) {
                $debug_info['jrxml_query_raw'] = $query_raw;
            } else {
                $debug_info['jrxml_query_raw_warning'] = 'No se pudo extraer <queryString> del JRXML';
            }
        } else {
            $debug_info['jrxml_query_raw_warning'] = 'JRXML no encontrado en templates/' . $tipo . '.jrxml';
        }
    } catch (Throwable $teq) {
        $debug_info['jrxml_query_raw_error'] = $teq->getMessage();
    }
    // Preparar fechas
    $desde_dt = $desde . ' 00:00:00';
    $hasta_dt = $hasta . ' 23:59:59';
    $desde_sql = db_input($desde_dt);
    $hasta_sql = db_input($hasta_dt);

    $where = "created >= {$desde_sql} AND created <= {$hasta_sql}";
    if ($departamento !== '') {
        $where .= ' AND dept_id=' . (int)$departamento;
    }
    if ($estado !== '') {
        $where .= ' AND status_id=' . (int)$estado;
    }
    
    error_log("Buscando tickets desde {$desde_dt} hasta {$hasta_dt}");

    // Resolver consulta del JRXML con parámetros conocidos (solo para mostrar en debug)
    if (!empty($debug_info['jrxml_query_raw'])) {
        $resolved = $debug_info['jrxml_query_raw'];
        $vals = [
            'fecha_desde' => $desde_dt,
            'fecha_hasta' => $hasta_dt,
            'departamento' => $departamento,
            'estado' => $estado,
            // Posibles nombres en mayúsculas usados por plantillas
            'FECHA_DESDE' => $desde_dt,
            'FECHA_HASTA' => $hasta_dt,
            'DEPARTAMENTO' => $departamento,
            'ESTADO' => $estado,
        ];
        foreach ($vals as $k => $v) {
            $v = (string)$v;
            // $P!{param} sin comillas
            $resolved = str_replace('$P!{' . $k . '}', $v, $resolved);
            // $P{param} con comillas y escape de comillas simples
            $vsql = "'" . str_replace("'", "''", $v) . "'";
            $resolved = str_replace('$P{' . $k . '}', $vsql, $resolved);
        }
        $debug_info['jrxml_query_resolved'] = $resolved;
        // Probar la consulta resuelta directamente para capturar campos/valores (solo debug)
        try {
            $probe_sql = rtrim($resolved);
            // Evitar duplicar LIMIT si ya existe
            if (!preg_match('/\blimit\b/i', $probe_sql)) {
                $probe_sql .= "\n LIMIT 5";
            }
            $debug_info['jrxml_probe_sql'] = $probe_sql;
            $probe_rs = db_query($probe_sql);
            if ($probe_rs !== false) {
                $probe_rows = [];
                $first = null;
                $i = 0;
                while ($r = db_fetch_array($probe_rs)) {
                    if ($first === null) $first = $r;
                    $probe_rows[] = $r;
                    if (++$i >= 5) break;
                }
                if ($first !== null) {
                    $debug_info['jrxml_probe_first'] = $first;
                    $debug_info['jrxml_probe_fields'] = array_keys($first);
                    // Comparar campos del JRXML con los detectados en la consulta
                    if (!empty($debug_info['jrxml_fields'])) {
                        $jrxml_fields = $debug_info['jrxml_fields'];
                        $probe_fields = $debug_info['jrxml_probe_fields'];
                        $missing = [];
                        foreach ($jrxml_fields as $f) {
                            if (!in_array($f, $probe_fields)) $missing[] = $f;
                        }
                        if (!empty($missing)) $debug_info['jrxml_field_mismatches'] = $missing;
                    }
                }
                $debug_info['jrxml_probe_count'] = count($probe_rows);
            } else {
                $debug_info['jrxml_probe_error'] = db_error();
            }
        } catch (Throwable $ter) {
            $debug_info['jrxml_probe_error'] = $ter->getMessage();
        }
    }
    
    // CONSULTA 1: Contar tickets (usando COUNT directamente)
    $count_sql = "SELECT COUNT(*) as total FROM {$tickets_table} 
                  WHERE {$where}";
    $debug_info['count_sql'] = $count_sql;
    $debug_info['where'] = $where;
    $debug_info['rango'] = [$desde_dt, $hasta_dt];
    
    error_log("Count SQL: " . $count_sql);
    
    $count_result = db_query($count_sql);
    
    if ($count_result === false) {
        throw new Exception("Error en consulta COUNT");
    }
    
    $count_row = db_fetch_array($count_result);
    $total = $count_row['total'] ?? 0;
    
    error_log("Tickets encontrados (COUNT): " . $total);
    
    if ($total == 0) {
        throw new Exception("No se encontraron tickets en el rango {$desde_dt} a {$hasta_dt}");
    }
    
    // Detectar PK de department (id vs dept_id) para evitar nulls por join incorrecto
    $dept_pk = 'dept_id';
    try {
        $chk_id = db_query("SHOW COLUMNS FROM {$dept_table} LIKE 'id'");
        if ($chk_id && db_fetch_array($chk_id)) { $dept_pk = 'id'; }
    } catch (Throwable $t) { /* ignore */ }

    // CONSULTA 2: Obtener datos con nombres (LEFT JOIN)
    $sql = "SELECT 
        t.ticket_id,
        t.number,
        t.subject,
        DATE_FORMAT(t.created, '%Y-%m-%d %H:%i:%s') as fecha_creacion,
        DATE_FORMAT(t.closed, '%Y-%m-%d %H:%i:%s') as fecha_cierre,
        t.status_id,
        t.dept_id,
        t.user_id,
        s.name AS estado_nombre,
        d.name AS departamento_nombre,
        COALESCE(ucd.name, u.name) AS cliente_nombre,
        CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,'')) AS asignado_a,
        tm.name AS equipo_nombre
    FROM {$tickets_table} t
    LEFT JOIN {$status_table} s ON s.id = t.status_id
    LEFT JOIN {$dept_table} d ON d.{$dept_pk} = t.dept_id
    LEFT JOIN {$user_table} u ON u.id = t.user_id
    LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id
    LEFT JOIN {$staff_table} st ON st.staff_id = t.staff_id
    LEFT JOIN {$team_table} tm ON tm.team_id = t.team_id
    WHERE {$where}
    ORDER BY t.created DESC";
    
    error_log("Main SQL: " . $sql);
    
    $result = db_query($sql);
    if ($result === false) {
        $debug_info['main_error'] = db_error();
        error_log('Main SQL error: ' . $debug_info['main_error']);
    }

    // Consumir filas inmediatamente para evitar efectos colaterales
    $raw_rows = [];
    if ($result) {
        while ($tmp = db_fetch_array($result)) {
            $raw_rows[] = $tmp;
        }
    }
    $main_rows = count($raw_rows);
    $debug_info['main_rows'] = $main_rows;
    error_log("Main SQL rows (consumidas): " . $main_rows);
    
    if ($result === false) {
        // Probar consulta aún más simple
        $simple_sql = "SELECT ticket_id, number, subject, created FROM {$tickets_table} 
                       WHERE {$where}
                       LIMIT 10";
        
        error_log("Probando consulta simple: " . $simple_sql);
        $simple_result = db_query($simple_sql);
        if ($simple_result === false) {
            $debug_info['simple_error'] = db_error();
            error_log('Simple SQL error: ' . $debug_info['simple_error']);
        }
        
        if ($simple_result === false) {
            throw new Exception("Error en consulta principal y simple");
        }
        
        $simple_rows = [];
        while ($simple_row = db_fetch_array($simple_result)) {
            $simple_rows[] = $simple_row;
        }
        
        error_log("Consulta simple devolvió: " . count($simple_rows) . " filas");
        
        if (empty($simple_rows)) {
            throw new Exception("Ambas consultas devolvieron 0 filas (pero COUNT dice {$total})");
        }
        
        // Usar los datos simples
        $raw_rows = $simple_rows;
        $main_rows = count($raw_rows);
        $use_simple = true;
    }
    
    $rows = [];
    $count = 0;
    
    // Intentar procesamiento con la consulta principal
    $rows = [];
    $raw_result = null; // ya consumimos filas en $raw_rows

    if ($main_rows === 0 && $total > 0) {
        // Fallback: ignorar hora y filtrar solo por fecha en caso de discrepancias por timezone
        $date_where = "DATE(created) BETWEEN " . db_input($desde) . " AND " . db_input($hasta);
        if ($departamento !== '') {
            $date_where .= ' AND dept_id=' . (int)$departamento;
        }
        if ($estado !== '') {
            $date_where .= ' AND status_id=' . (int)$estado;
        }
        $fallback_sql = "SELECT 
            t.ticket_id, t.number, t.subject, 
            DATE_FORMAT(t.created, '%Y-%m-%d %H:%i:%s') as fecha_creacion,
            DATE_FORMAT(t.closed, '%Y-%m-%d %H:%i:%s') as fecha_cierre,
            t.status_id, t.dept_id, t.user_id,
            s.name AS estado_nombre,
            d.name AS departamento_nombre,
            COALESCE(ucd.name, u.name) AS cliente_nombre,
            CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,'')) AS asignado_a,
            tm.name AS equipo_nombre
        FROM {$tickets_table} t
        LEFT JOIN {$status_table} s ON s.id = t.status_id
        LEFT JOIN {$dept_table} d ON d.{$dept_pk} = t.dept_id
        LEFT JOIN {$user_table} u ON u.id = t.user_id
        LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id
        LEFT JOIN {$staff_table} st ON st.staff_id = t.staff_id
        LEFT JOIN {$team_table} tm ON tm.team_id = t.team_id
        WHERE {$date_where} 
        ORDER BY t.created DESC";
        error_log("Fallback SQL (DATE): " . $fallback_sql);
        $fallback_res = db_query($fallback_sql);
        if ($fallback_res === false) {
            $debug_info['fallback_error'] = db_error();
            error_log('Fallback SQL error: ' . $debug_info['fallback_error']);
        }
        // Consumir fallback
        $raw_rows = [];
        if ($fallback_res) {
            while ($tmp = db_fetch_array($fallback_res)) {
                $raw_rows[] = $tmp;
            }
        }
        $debug_info['fallback_rows'] = count($raw_rows);
        error_log("Fallback rows (consumidas): " . count($raw_rows));
        // Fallback simple sin JOIN si sigue vacío
        if (empty($raw_rows)) {
            $simple_fb_sql = "SELECT 
                ticket_id, number, subject,
                DATE_FORMAT(created, '%Y-%m-%d %H:%i:%s') as fecha_creacion,
                DATE_FORMAT(closed, '%Y-%m-%d %H:%i:%s') as fecha_cierre,
                status_id, dept_id, user_id
            FROM {$tickets_table}
            WHERE {$where}
            ORDER BY created DESC";
            error_log("Fallback simple sin JOIN: " . $simple_fb_sql);
            $simple_fb_res = db_query($simple_fb_sql);
            if ($simple_fb_res === false) {
                $debug_info['simple_fb_error'] = db_error();
                error_log('Simple FB SQL error: ' . $debug_info['simple_fb_error']);
            }
            if ($simple_fb_res) {
                while ($tmp = db_fetch_array($simple_fb_res)) {
                    $raw_rows[] = $tmp;
                }
            }
            $debug_info['simple_fb_rows'] = count($raw_rows);
            error_log("Simple FB rows: " . count($raw_rows));
        }
    }

    // raw_rows ya contiene las filas consumidas del main o del fallback

    // Normalizar nombres si vienen de fallbacks sin JOIN
    $status_map = $staff_map = $team_map = $client_map = [];
    $status_ids = $staff_ids = $team_ids = $client_ids = [];
    foreach ($raw_rows as $rr) {
        if (isset($rr['status_id']) && $rr['status_id'] !== '') $status_ids[] = (int)$rr['status_id'];
        if (isset($rr['staff_id']) && $rr['staff_id'] !== '') $staff_ids[] = (int)$rr['staff_id'];
        if (isset($rr['team_id']) && $rr['team_id'] !== '') $team_ids[] = (int)$rr['team_id'];
        if (isset($rr['user_id']) && $rr['user_id'] !== '') $client_ids[] = (int)$rr['user_id'];
    }
    $status_ids = array_values(array_unique(array_filter($status_ids)));
    $staff_ids = array_values(array_unique(array_filter($staff_ids)));
    $team_ids = array_values(array_unique(array_filter($team_ids)));
    $client_ids = array_values(array_unique(array_filter($client_ids)));

    if (!empty($status_ids)) {
        $sql_s = "SELECT id, name FROM {$status_table} WHERE id IN (" . implode(',', $status_ids) . ")";
        $rs_s = db_query($sql_s);
        if ($rs_s) while ($r = db_fetch_array($rs_s)) { $status_map[$r['id']] = $r['name']; }
    }
    if (!empty($staff_ids)) {
        $sql_st = "SELECT staff_id, CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,'')) AS full FROM {$staff_table} WHERE staff_id IN (" . implode(',', $staff_ids) . ")";
        $rs_st = db_query($sql_st);
        if ($rs_st) while ($r = db_fetch_array($rs_st)) { $staff_map[$r['staff_id']] = trim($r['full']); }
    }
    if (!empty($team_ids)) {
        $sql_tm = "SELECT team_id, name FROM {$team_table} WHERE team_id IN (" . implode(',', $team_ids) . ")";
        $rs_tm = db_query($sql_tm);
        if ($rs_tm) while ($r = db_fetch_array($rs_tm)) { $team_map[$r['team_id']] = $r['name']; }
    }
    if (!empty($client_ids)) {
        $sql_u = "SELECT u.id, COALESCE(ucd.name, u.name) AS uname, COALESCE(ucd.email, u.email) AS uemail
                  FROM {$user_table} u
                  LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id
                  WHERE u.id IN (" . implode(',', $client_ids) . ")";
        $rs_u = db_query($sql_u);
        if ($rs_u) while ($r = db_fetch_array($rs_u)) {
            $nm = trim($r['uname'] ?? '');
            $em = trim($r['uemail'] ?? '');
            // Preferir sólo nombre; si no hay nombre, usar email
            $client_map[$r['id']] = ($nm !== '') ? $nm : $em;
        }
    }

    // Determinar logo para el reporte (opcional)
    $logo_to_embed = '';
    $logo_url = trim($_POST['logo_url'] ?? '');
    $logo_path = trim($_POST['logo_path'] ?? '');
    $logo_signature = trim($_POST['logo_signature'] ?? '');
    $logo_name = trim($_POST['logo_name'] ?? '');
    $logo_key = trim($_POST['logo_key'] ?? '');
    $assets_dir = $root_path . 'reports/java/assets/';
    if (!is_dir($assets_dir)) @mkdir($assets_dir, 0775, true);
    $assets_logo = $assets_dir . 'logo.png';

    if ($logo_url !== '') {
        // Usar URL directa (Java puede cargar desde HTTP)
        $logo_to_embed = $logo_url;
        error_log('Usando logo por URL: ' . $logo_url);
    } elseif ($logo_path !== '' && file_exists($logo_path)) {
        // Copiar archivo local a assets
        try {
            if (@copy($logo_path, $assets_logo)) {
                // Ruta relativa para Jasper desde jar_dir
                $logo_to_embed = './assets/logo.png';
                error_log('Logo copiado a assets: ' . $assets_logo);
            } else {
                error_log('No se pudo copiar logo desde ' . $logo_path);
            }
        } catch (Throwable $te) {
            error_log('Error copiando logo: ' . $te->getMessage());
        }
    } else {
        // Intentar obtener logo desde BD (ost_file / ost_file_chunk)
        try {
            $file_table = defined('FILE_TABLE') ? FILE_TABLE : TABLE_PREFIX . 'file';
            $chunk_table = defined('FILE_CHUNK_TABLE') ? FILE_CHUNK_TABLE : TABLE_PREFIX . 'file_chunk';

            // Usar variable separada para evitar sobreescribir $where de tickets
            $logo_where = "ft='L' AND type LIKE 'image/%'";
            if ($logo_signature !== '') {
                $logo_where .= ' AND signature=' . db_input($logo_signature);
            } elseif ($logo_name !== '') {
                $logo_where .= ' AND name=' . db_input($logo_name);
            } elseif ($logo_key !== '') {
                $logo_where .= ' AND `key`=' . db_input($logo_key);
            }

            $sql_logo = "SELECT id, bk, type, name FROM {$file_table} WHERE {$logo_where} ORDER BY created DESC LIMIT 1";
            $res_logo = db_query($sql_logo);
            if ($res_logo && ($row_logo = db_fetch_array($res_logo)) && !empty($row_logo['id'])) {
                $file_id = (int)$row_logo['id'];
                $blob = '';
                $sql_chunks = "SELECT filedata FROM {$chunk_table} WHERE file_id={$file_id} ORDER BY chunk_id ASC";
                $res_chunks = db_query($sql_chunks);
                if ($res_chunks) {
                    while ($row_chunk = db_fetch_array($res_chunks)) {
                        $blob .= $row_chunk['filedata'];
                    }
                }
                if ($blob !== '') {
                    if (file_put_contents($assets_logo, $blob) !== false) {
                        $logo_to_embed = './assets/logo.png';
                        error_log('Logo reconstruido desde BD y guardado en assets.');
                    } else {
                        error_log('No se pudo escribir logo en assets.');
                    }
                } else {
                    error_log('No se encontraron chunks para el logo seleccionado.');
                }
            } else {
                error_log('No se encontró registro de logo en ost_file con los criterios proporcionados.');
            }
        } catch (Throwable $te) {
            error_log('Error obteniendo logo desde BD: ' . $te->getMessage());
        }
        // Si no se pudo determinar, dejar vacío y permitir fallback por parámetro en JRXML
        if ($logo_to_embed === '') {
            $logo_to_embed = '';
        }
    }

    // Deshabilitar normalizaciones pero mapear alias de campos esperados por JRXML (sin alterar valores)
    $rows = [];
    foreach ($raw_rows as $r) {
        $rows[] = [
            'id' => $r['ticket_id'] ?? null,
            'numero' => $r['number'] ?? null,
            'asunto' => $r['subject'] ?? null,
            'fecha_creacion' => $r['fecha_creacion'] ?? ($r['created'] ?? null),
            'fecha_cierre' => $r['fecha_cierre'] ?? ($r['closed'] ?? null),
            'estado' => $r['estado_nombre'] ?? null,
            'departamento' => $r['departamento_nombre'] ?? null,
            'cliente' => $r['cliente_nombre'] ?? null,
            'creado_por' => $r['cliente_nombre'] ?? null,
            'asignado_a' => $r['asignado_a'] ?? null,
            'equipo' => $r['equipo_nombre'] ?? null,
            'logo' => $logo_to_embed
        ];
    }
    $debug_info['normalization_disabled'] = true;
    $count = count($rows);
    error_log("Filas procesadas (sin normalización, con alias): " . $count);
    
    if (empty($rows)) {
        // Consulta directa con JOINs y alias esperados para evitar nulls
        $direct_sql = "SELECT 
                t.ticket_id, t.number, t.subject,
                DATE_FORMAT(t.created, '%Y-%m-%d %H:%i:%s') as fecha_creacion,
                DATE_FORMAT(t.closed, '%Y-%m-%d %H:%i:%s') as fecha_cierre,
                t.status_id, t.dept_id, t.user_id,
                s.name AS estado_nombre,
                d.name AS departamento_nombre,
                COALESCE(ucd.name, u.name) AS cliente_nombre,
                CONCAT(COALESCE(st.firstname,''),' ',COALESCE(st.lastname,'')) AS asignado_a,
            FROM {$tickets_table} t
            LEFT JOIN {$status_table} s ON s.id = t.status_id
            LEFT JOIN {$dept_table} d ON d.{$dept_pk} = t.dept_id
            LEFT JOIN {$user_table} u ON u.id = t.user_id
            LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id
            LEFT JOIN {$staff_table} st ON st.staff_id = t.staff_id
            WHERE {$where}
            ORDER BY t.created DESC";
        $debug_info['direct_sql'] = $direct_sql;
        $direct_result = db_query($direct_sql);
        if ($direct_result === false) {
            $debug_info['direct_error'] = db_error();
            error_log('Direct SQL error: ' . $debug_info['direct_error']);
        }
        
        if ($direct_result) {
            $direct_rows = [];
            while ($direct_row = db_fetch_array($direct_result)) {
                $direct_rows[] = $direct_row;
            }
            
            error_log("Consulta directa devolvió: " . count($direct_rows) . " filas");
            error_log("Primera fila directa: " . print_r($direct_rows[0] ?? [], true));
            $debug_info['direct_rows'] = count($direct_rows);
            $debug_info['direct_first'] = $direct_rows[0] ?? [];

            // Resolver nombres para estado, staff y team usando los IDs recolectados
            $st_ids = $sf_ids = $tm_ids = [];
            foreach ($direct_rows as $dr) {
                if (!empty($dr['status_id'])) $st_ids[] = (int)$dr['status_id'];
                if (!empty($dr['staff_id'])) $sf_ids[] = (int)$dr['staff_id'];
                if (!empty($dr['team_id'])) $tm_ids[] = (int)$dr['team_id'];
            }
            $st_ids = array_values(array_unique(array_filter($st_ids)));
            $sf_ids = array_values(array_unique(array_filter($sf_ids)));
            $tm_ids = array_values(array_unique(array_filter($tm_ids)));

            $st_map2 = $sf_map2 = $tm_map2 = $cli_map2 = [];
            if (!empty($st_ids)) {
                $rs = db_query("SELECT id, name FROM {$status_table} WHERE id IN (" . implode(',', $st_ids) . ")");
                if ($rs) while ($r = db_fetch_array($rs)) { $st_map2[$r['id']] = $r['name']; }
            }
            if (!empty($sf_ids)) {
                $rs = db_query("SELECT staff_id, CONCAT(COALESCE(firstname,''),' ',COALESCE(lastname,'')) AS full FROM {$staff_table} WHERE staff_id IN (" . implode(',', $sf_ids) . ")");
                if ($rs) while ($r = db_fetch_array($rs)) { $sf_map2[$r['staff_id']] = trim($r['full']); }
            }
            if (!empty($tm_ids)) {
                $rs = db_query("SELECT team_id, name FROM {$team_table} WHERE team_id IN (" . implode(',', $tm_ids) . ")");
                if ($rs) while ($r = db_fetch_array($rs)) { $tm_map2[$r['team_id']] = $r['name']; }
            }
            // Mapear clientes
            $usr_ids2 = [];
            foreach ($direct_rows as $dr) { if (!empty($dr['user_id'])) $usr_ids2[] = (int)$dr['user_id']; }
            $usr_ids2 = array_values(array_unique(array_filter($usr_ids2)));
            if (!empty($usr_ids2)) {
                $rs = db_query("SELECT u.id, COALESCE(ucd.name, u.name) AS uname, COALESCE(ucd.email, u.email) AS uemail FROM {$user_table} u LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id WHERE u.id IN (" . implode(',', $usr_ids2) . ")");
                if ($rs) while ($r = db_fetch_array($rs)) {
                    $nm = trim($r['uname'] ?? '');
                    $em = trim($r['uemail'] ?? '');
                    $cli_map2[$r['id']] = ($nm !== '') ? $nm : $em;
                }
            }

            // Usar filas directas sin normalización y con alias esperados
            if (!empty($direct_rows)) {
                $rows = [];
                foreach ($direct_rows as $r) {
                    $rows[] = [
                        'id' => $r['ticket_id'] ?? null,
                        'numero' => $r['number'] ?? null,
                        'asunto' => $r['subject'] ?? null,
                        'fecha_creacion' => $r['fecha_creacion'] ?? ($r['created'] ?? null),
                        'fecha_cierre' => $r['fecha_cierre'] ?? ($r['closed'] ?? null),
                        'estado' => $r['estado_nombre'] ?? null,
                        'departamento' => $r['departamento_nombre'] ?? null,
                        'cliente' => $r['cliente_nombre'] ?? null,
                        'creado_por' => $r['cliente_nombre'] ?? null,
                        'asignado_a' => $r['asignado_a'] ?? null,
                        'equipo' => $r['equipo_nombre'] ?? null
                    ];
                }
                $count = count($rows);
                $debug_info['normalization_disabled'] = true;
                error_log('Usando filas de consulta directa como fallback (sin normalización, con alias)');
            }
        }
        
        if (empty($rows) && $datasource !== 'sql') {
            error_log('Debug dump (sin filas): ' . json_encode($debug_info));
            throw new Exception("No se pudieron procesar los datos. COUNT: {$total}, Filas procesadas: 0");
        }
    }
    
    error_log("Filas procesadas: " . count($rows));

    // =============================================================
    // MODO PHP: ejecutar SQL del JRXML y generar HTML + PDF
    // =============================================================
    if ($engine === 'php') {
        $debug_info['engine'] = 'php_front';
        $output_dir = $root_path . 'reports/output/';
        if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);

        // Resolver consulta desde JRXML
        $resolved_sql = $debug_info['jrxml_query_resolved'] ?? '';
        if ($resolved_sql === '') {
            // Intentar extraer ahora si no estaba disponible
            try {
                $tpl_path = dirname(__FILE__) . '/../reports/java/templates/' . $tipo . '.jrxml';
                if (is_file($tpl_path)) {
                    $jrxml_txt = @file_get_contents($tpl_path);
                    if ($jrxml_txt !== false) {
                        if (preg_match('/<queryString[^>]*><!\[CDATA\[(.*?)\]\]><\/queryString>/is', $jrxml_txt, $m)) {
                            $resolved_sql = $m[1];
                        } elseif (preg_match('/<queryString[^>]*>(.*?)<\/queryString>/is', $jrxml_txt, $m)) {
                            $resolved_sql = html_entity_decode(trim($m[1]));
                        }
                        // Sustituir parámetros conocidos
                        if ($resolved_sql !== '') {
                            $vals = [
                                'fecha_desde' => $desde_dt,
                                'fecha_hasta' => $hasta_dt,
                                'departamento' => $departamento,
                                'estado' => $estado,
                                'FECHA_DESDE' => $desde_dt,
                                'FECHA_HASTA' => $hasta_dt,
                                'DEPARTAMENTO' => $departamento,
                                'ESTADO' => $estado,
                            ];
                            foreach ($vals as $k => $v) {
                                $v = (string)$v;
                                $resolved_sql = str_replace('$P!{' . $k . '}', $v, $resolved_sql);
                                $vsql = "'" . str_replace("'", "''", $v) . "'";
                                $resolved_sql = str_replace('$P{' . $k . '}', $vsql, $resolved_sql);
                            }
                        }
                    }
                }
            } catch (Throwable $te) {
                $debug_info['php_engine_jrxml_error'] = $te->getMessage();
            }
        }

        if ($resolved_sql === '') {
            throw new Exception('No se pudo obtener la consulta SQL desde el JRXML para el motor PHP.');
        }
        $debug_info['php_engine_sql'] = $resolved_sql;

        // Ejecutar consulta y recolectar datos
        $php_rows = [];
        $php_fields = [];
        $php_error = '';
        try {
            $rs = db_query($resolved_sql);
            if ($rs === false) {
                $php_error = db_error();
            } else {
                while ($r = db_fetch_array($rs)) {
                    if (empty($php_fields)) { $php_fields = array_keys($r); }
                    $php_rows[] = $r;
                }
            }
        } catch (Throwable $te) {
            $php_error = $te->getMessage();
        }
        $debug_info['php_engine_records'] = count($php_rows);
        if ($php_error !== '') { $debug_info['php_engine_error'] = $php_error; }

        // Derivar encabezados y orden desde JRXML por posiciones X (similar a Excel nativo)
        $jrxml_columns = [];
        $jrxml_title = '';
        try {
            $jrxml_path = $root_path . 'reports/java/templates/' . $tipo . '.jrxml';
            if (is_file($jrxml_path)) {
                $dom = new DOMDocument();
                $dom->load($jrxml_path);
                $xp = new DOMXPath($dom);
                $xp->registerNamespace('jr', 'http://jasperreports.sourceforge.net/jasperreports');
                // Título literal si existe
                $nodes = $xp->query('//jr:textFieldExpression');
                if ($nodes) {
                    foreach ($nodes as $n) {
                        $val = trim($n->textContent);
                        if (strpos($val, 'Listado de Tickets') !== false) { $jrxml_title = 'Listado de Tickets'; break; }
                    }
                }
                // Encabezados en title/columnHeader con posición X
                $headers_pos = [];
                foreach (["//jr:title/jr:band//jr:textField","//jr:columnHeader/jr:band//jr:textField"] as $q) {
                    $hNodes = $xp->query($q);
                    if ($hNodes && $hNodes->length) {
                        foreach ($hNodes as $tf) {
                            $exprNode = null; foreach ($tf->childNodes as $c) { if ($c->localName === 'textFieldExpression') { $exprNode = $c; break; } }
                            if (!$exprNode) continue;
                            $expr = trim($exprNode->textContent);
                            if (!(strlen($expr) >= 2 && $expr[0] === '"' && substr($expr, -1) === '"')) continue;
                            $label = trim($expr, '"');
                            $x = 0; foreach ($tf->childNodes as $c) { if ($c->localName === 'reportElement') { if ($c->hasAttribute('x')) $x = (int)$c->getAttribute('x'); break; } }
                            $headers_pos[] = ['x' => $x, 'label' => $label];
                        }
                    }
                }
                usort($headers_pos, function($a,$b){ return $a['x'] <=> $b['x']; });
                // Campos de detalle por posición X
                $detail_pos = [];
                $dNodes = $xp->query('//jr:detail/jr:band//jr:textField');
                if ($dNodes && $dNodes->length) {
                    foreach ($dNodes as $tf) {
                        $exprNode = null; foreach ($tf->childNodes as $c) { if ($c->localName === 'textFieldExpression') { $exprNode = $c; break; } }
                        $x = 0; foreach ($tf->childNodes as $c) { if ($c->localName === 'reportElement') { if ($c->hasAttribute('x')) $x = (int)$c->getAttribute('x'); break; } }
                        $field = null;
                        if ($exprNode) { $expr = trim($exprNode->textContent); if (preg_match('/\$F\{([^}]+)\}/', $expr, $m)) { $field = $m[1]; } }
                        $detail_pos[] = ['x' => $x, 'field' => $field];
                    }
                    usort($detail_pos, function($a,$b){ return $a['x'] <=> $b['x']; });
                }
                if (!empty($headers_pos)) {
                    foreach ($headers_pos as $hp) {
                        $closest = null; $closest_dx = PHP_INT_MAX;
                        foreach ($detail_pos as $dp) { $dx = abs($dp['x'] - $hp['x']); if ($dx < $closest_dx) { $closest = $dp; $closest_dx = $dx; } }
                        $jrxml_columns[] = ['header' => $hp['label'], 'field' => $closest && $closest_dx <= 20 ? ($closest['field'] ?: null) : null];
                    }
                }
            }
        } catch (Throwable $pe) {
            // Sin layout, se usará orden automático
            $debug_info['php_engine_layout_warn'] = $pe->getMessage();
        }

        // Construir HTML
        $ts = date('Ymd_His');
        $html_name = 'reporte_' . $tipo . '_' . $ts . '.html';
        $pdf_name  = 'reporte_' . $tipo . '_' . $ts . '.pdf';
        $html_file = $output_dir . $html_name;
        $pdf_file  = $output_dir . $pdf_name;

        $headers = [];
        $columns = [];
        if (!empty($jrxml_columns)) {
            foreach ($jrxml_columns as $col) { $headers[] = $col['header']; $columns[] = $col['field']; }
        } else {
            $headers = !empty($php_fields) ? $php_fields : [];
            $columns = $headers;
        }

        $err_html = '';
        if ($php_error !== '') {
            $err_html = '<div style="background:#ffecec;border:1px solid #e0b4b4;color:#9f3a38;padding:10px;margin-bottom:15px;">' .
                        '<strong>Error en SQL:</strong> ' . htmlspecialchars($php_error) . '</div>';
        }

        $title_html = htmlspecialchars($jrxml_title !== '' ? $jrxml_title : ('Reporte ' . $tipo));
        $params_html = '<div style="font-size:12px;color:#555;margin-bottom:8px;">' .
            '<strong>Desde:</strong> ' . htmlspecialchars($desde_dt) . ' &nbsp; ' .
            '<strong>Hasta:</strong> ' . htmlspecialchars($hasta_dt) . ' &nbsp; ' .
            ($departamento !== '' ? ('<strong>Depto:</strong> ' . htmlspecialchars($departamento) . ' &nbsp; ') : '') .
            ($estado !== '' ? ('<strong>Estado:</strong> ' . htmlspecialchars($estado)) : '') .
            '</div>';
        
        $table_head = '';
        foreach ($headers as $h) { $table_head .= '<th style="border:1px solid #ddd;padding:6px;background:#f7f7f7">' . htmlspecialchars((string)$h) . '</th>'; }
        $table_rows = '';
        foreach ($php_rows as $row) {
            $table_rows .= '<tr>'; 
            foreach ($columns as $fieldKey) {
                $val = $fieldKey === null ? '' : (isset($row[$fieldKey]) ? (string)$row[$fieldKey] : '');
                $table_rows .= '<td style="border:1px solid #ddd;padding:6px;">' . htmlspecialchars($val) . '</td>';
            }
            $table_rows .= '</tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title_html . '</title>' .
            '<style>body{font-family:Arial,sans-serif;font-size:13px;margin:20px;} h2{margin:0 0 10px;} table{border-collapse:collapse;width:100%;} .muted{color:#777} .footer{margin-top:10px;color:#777;font-size:11px}</style>' .
            '</head><body>' .
            '<h2>' . $title_html . '</h2>' . $params_html . $err_html .
            '<table><thead><tr>' . $table_head . '</tr></thead><tbody>' . $table_rows . '</tbody></table>' .
            '<div class="footer">Generado ' . date('Y-m-d H:i:s') . ' • Motor PHP</div>' .
            '</body></html>';

        file_put_contents($html_file, $html);
        $debug_info['php_engine_html_size'] = filesize($html_file);

        // Generar PDF desde el mismo HTML (si Dompdf disponible)
        $pdf_generated = false;
        $dompdf_autoload = $root_path . 'vendor/autoload.php';
        if (file_exists($dompdf_autoload)) { require_once $dompdf_autoload; }
        if (class_exists('Dompdf\\Dompdf')) {
            try {
                $dompdf = new Dompdf\Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                file_put_contents($pdf_file, $dompdf->output());
                $pdf_generated = true;
            } catch (Throwable $te) {
                $debug_info['php_engine_pdf_error'] = $te->getMessage();
            }
        } else {
            $debug_info['php_engine_pdf_warn'] = 'Dompdf no disponible';
        }

        $download_html = '../reports/output/' . $html_name;
        $download_pdf  = $pdf_generated ? ('../reports/download.php?file=' . rawurlencode($pdf_name)) : null;

        json_response([
            'ok' => true,
            'engine' => 'php',
            'download' => $download_pdf ?: $download_html,
            'download_html' => $download_html,
            'download_pdf' => $download_pdf,
            'file' => $pdf_generated ? $pdf_name : $html_name,
            'records' => count($php_rows),
            'debug' => $debug_info
        ]);
        return;
    }

    // =============================================================
    // MODO PHPJASPERXML: render fiel al JRXML con TCPDF (PDF) + HTML resumen
    // =============================================================
    if ($engine === 'phpjasper' || $engine === 'phpjrxml') {
        $debug_info['engine'] = 'phpjasperxml';
        $debug_info['datasource'] = 'phpjasperxml';
        $output_dir = $root_path . 'reports/output/';
        if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);

        // Localizar JRXML
        $jrxml_path = $root_path . 'reports/java/templates/' . $tipo . '.jrxml';
        if (!is_file($jrxml_path)) {
            throw new Exception('Plantilla JRXML no encontrada para PHPJasperXML: ' . $jrxml_path);
        }
        $debug_info['phpjasperxml_jrxml'] = $jrxml_path;

        // DB creds desde ost-config.php
        $cfg_file = $root_path . 'include/ost-config.php';
        $dbhost = 'localhost'; $dbname = ''; $dbuser = ''; $dbpass = ''; $dbport = '3306';
        if (is_file($cfg_file)) {
            try {
                $content = @file_get_contents($cfg_file) ?: '';
                $get = function($name) use ($content) { $re = '/define\s*\(\s*\'' . $name . '\'\s*,\s*\'(.*?)\'\s*\)\s*;/i'; return preg_match($re, $content, $m) ? $m[1] : null; };
                $dbhost = $get('DBHOST') ?: $dbhost;
                $dbname = $get('DBNAME') ?: $dbname;
                $dbuser = $get('DBUSER') ?: $dbuser;
                $dbpass = $get('DBPASS') ?: $dbpass;
                $dbport = $get('DBPORT') ?: $dbport;
            } catch (Throwable $te) { /* ignore */ }
        }
        $debug_info['phpjasperxml_db'] = ['host'=>$dbhost,'name'=>$dbname,'user'=>$dbuser,'port'=>$dbport];
        // Verificar conectividad con las credenciales (útil para saber si JDBC/DB está accesible)
        if (function_exists('mysqli_connect')) {
            $mysqli = @mysqli_connect($dbhost, $dbuser, $dbpass, $dbname, (int)$dbport);
            if ($mysqli) {
                $debug_info['phpjasperxml_db_connect'] = true;
                mysqli_close($mysqli);
            } else {
                $debug_info['phpjasperxml_db_connect'] = false;
                $debug_info['phpjasperxml_db_error'] = mysqli_connect_error();
            }
        } else {
            $debug_info['phpjasperxml_db_connect'] = 'mysqli_not_available';
        }

        // Incluir librería PHPJasperXML y TCPDF desde ubicaciones comunes
        $lib_candidates = [
            $root_path . 'lib/PHPJasperXML/PHPJasperXML.inc.php',
            $root_path . 'lib/PHPJasperXML.inc.php',
            $root_path . 'lib/PHPJasperXML/PHPJasperXML.php',
            $root_path . 'lib/phpjasperxml/PHPJasperXML.inc.php',
        ];
        foreach ($lib_candidates as $lc) { if (is_file($lc)) { @require_once $lc; $debug_info['phpjasperxml_lib_checked'] = str_replace($root_path, '', $lc); } }
        // Buscar también en version/* si existe
        $version_glob = glob($root_path . 'lib/PHPJasperXML/version/*/PHPJasperXML.inc.php') ?: [];
        if (!empty($version_glob)) {
            $debug_info['phpjasperxml_version_file'] = str_replace($root_path, '', $version_glob[0]);
            @require_once $version_glob[0];
        } else {
            $debug_info['phpjasperxml_version_file'] = null;
            $debug_info['phpjasperxml_version_dir'] = is_dir($root_path . 'lib/PHPJasperXML/version') ? 'present' : 'missing';
        }
        // TCPDF vía Composer si está disponible
        $autoload = $root_path . 'vendor/autoload.php';
        if (file_exists($autoload)) { require_once $autoload; }
        // O rutas comunes
        $tcpdf_candidates = [
            $root_path . 'lib/tcpdf/tcpdf.php',
            $root_path . 'lib/TCPDF/tcpdf.php'
        ];
        foreach ($tcpdf_candidates as $tc) { if (is_file($tc)) { require_once $tc; $debug_info['tcpdf_lib'] = str_replace($root_path, '', $tc); break; } }

        if (!class_exists('PHPJasperXML')) {
            $hint = 'Instala la librería completa en upload/lib/PHPJasperXML/version/1.1/ o ajusta el loader.';
            if (($debug_info['phpjasperxml_version_dir'] ?? '') === 'missing') {
                $hint = 'Falta la carpeta upload/lib/PHPJasperXML/version/<ver>/ con PHPJasperXML.inc.php';
            }
            throw new Exception('PHPJasperXML no está disponible. ' . $hint);
        }

        // Parámetros JRXML
        $params = [
            'fecha_desde' => $desde_dt,
            'fecha_hasta' => $hasta_dt,
        ];
        if ($departamento !== '') $params['departamento'] = (int)$departamento;
        if ($estado !== '') $params['estado'] = (int)$estado;
        if (!empty($logo_to_embed)) $params['logo'] = $logo_to_embed;
        // Mayúsculas posibles
        $params['FECHA_DESDE'] = $desde_dt; $params['FECHA_HASTA'] = $hasta_dt;

        // Render PDF
        $ts = date('Ymd_His');
        $pdf_name = 'reporte_' . $tipo . '_' . $ts . '.pdf';
        $pdf_file = $output_dir . $pdf_name;
        try {
            $jasper = new PHPJasperXML();
            // Algunas versiones requieren setParam antes de load
            if (property_exists($jasper, 'arrayParameter')) { $jasper->arrayParameter = $params; }
            if (method_exists($jasper, 'load_xml_file')) { $jasper->load_xml_file($jrxml_path); }
            // Conectar a DB
            if (method_exists($jasper, 'transferDBtoArray')) {
                $jasper->transferDBtoArray($dbhost, $dbuser, $dbpass, $dbname, 'mysql');
            }
            // Generar PDF a archivo: preferir modo "S" (string) y luego guardar
            $pdf_bytes = '';
            if (method_exists($jasper, 'outpage')) {
                try { $pdf_bytes = $jasper->outpage('S'); } catch (Throwable $te) { $debug_info['phpjasperxml_outpageS_error'] = $te->getMessage(); }
                if (is_string($pdf_bytes) && strlen($pdf_bytes) > 0) {
                    file_put_contents($pdf_file, $pdf_bytes);
                } else {
                    // Intentar modo "F" (file)
                    try { $jasper->outpage('F', $pdf_file); } catch (Throwable $te) { $debug_info['phpjasperxml_outpageF_error'] = $te->getMessage(); }
                }
            } else {
                throw new Exception('Método outpage no disponible en PHPJasperXML.');
            }
        } catch (Throwable $te) {
            throw new Exception('Error generando PDF con PHPJasperXML: ' . $te->getMessage());
        }

        if (!file_exists($pdf_file) || filesize($pdf_file) === 0) {
            throw new Exception('No se pudo generar el PDF (PHPJasperXML)');
        }

        // HTML Resumen con datos y errores (usando la misma tabla del motor PHP)
        $html_name = 'reporte_' . $tipo . '_' . $ts . '.html';
        $html_file = $output_dir . $html_name;

        // Reusar sondas previas si existen; si no, ejecutar consulta JRXML resuelta para datos
        $php_rows = [];
        $php_fields = [];
        $php_error = '';
        $resolved_sql = $debug_info['jrxml_query_resolved'] ?? '';
        if ($resolved_sql !== '') {
            try { $rs = db_query($resolved_sql); if ($rs !== false) { while ($r = db_fetch_array($rs)) { if (empty($php_fields)) { $php_fields = array_keys($r); } $php_rows[] = $r; } } else { $php_error = db_error(); } } catch (Throwable $te) { $php_error = $te->getMessage(); }
        }

        $headers = !empty($php_fields) ? $php_fields : array_keys($php_rows[0] ?? []);
        $columns = $headers;
        $err_html = ($php_error !== '') ? ('<div style="background:#ffecec;border:1px solid #e0b4b4;color:#9f3a38;padding:10px;margin-bottom:15px;"><strong>Error en SQL:</strong> ' . htmlspecialchars($php_error) . '</div>') : '';
        $title_html = htmlspecialchars('Reporte ' . $tipo . ' (PHPJasperXML)');
        $params_html = '<div style="font-size:12px;color:#555;margin-bottom:8px;"><strong>Desde:</strong> ' . htmlspecialchars($desde_dt) . ' &nbsp; <strong>Hasta:</strong> ' . htmlspecialchars($hasta_dt) . '</div>';
        $table_head = '';
        foreach ($headers as $h) { $table_head .= '<th style="border:1px solid #ddd;padding:6px;background:#f7f7f7">' . htmlspecialchars((string)$h) . '</th>'; }
        $table_rows = '';
        foreach ($php_rows as $row) { $table_rows .= '<tr>'; foreach ($columns as $fieldKey) { $val = isset($row[$fieldKey]) ? (string)$row[$fieldKey] : ''; $table_rows .= '<td style="border:1px solid #ddd;padding:6px;">' . htmlspecialchars($val) . '</td>'; } $table_rows .= '</tr>'; }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title_html . '</title><style>body{font-family:Arial,sans-serif;font-size:13px;margin:20px;} h2{margin:0 0 10px;} table{border-collapse:collapse;width:100%;} .footer{margin-top:10px;color:#777;font-size:11px}</style></head><body><h2>' . $title_html . '</h2>' . $params_html . $err_html . '<table><thead><tr>' . $table_head . '</tr></thead><tbody>' . $table_rows . '</tbody></table><div class="footer">Generado ' . date('Y-m-d H:i:s') . ' • Motor PHPJasperXML</div></body></html>';
        file_put_contents($html_file, $html);

        $download_html = '../reports/output/' . $html_name;
        $download_pdf  = '../reports/download.php?file=' . rawurlencode($pdf_name);

        json_response([
            'ok' => true,
            'engine' => 'phpjasperxml',
            'download' => $download_pdf,
            'download_html' => $download_html,
            'download_pdf' => $download_pdf,
            'file' => $pdf_name,
            'records' => count($php_rows),
            'debug' => $debug_info
        ]);
        return;
    }
    
    // =================================================================
    // EXCEL NATIVO (XLSX/XLS) SIN JAR
    // =================================================================
    if ($formato === 'xlsx' || $formato === 'xls') {
        $temp_dir = $root_path . 'reports/temp/';
        $output_dir = $root_path . 'reports/output/';
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0775, true);
        if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);

        $autoload = $root_path . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        // Fallback sin Composer: registrar autoloader PSR-4 para PhpSpreadsheet
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $ps_base = $root_path . 'lib/PhpSpreadsheet/src/';
            if (is_dir($ps_base)) {
                spl_autoload_register(function($class) use ($ps_base) {
                    $prefix = 'PhpOffice\\PhpSpreadsheet\\';
                    if (strpos($class, $prefix) === 0) {
                        $rel = substr($class, strlen($prefix));
                        $file = $ps_base . str_replace('\\', '/', $rel) . '.php';
                        if (file_exists($file)) {
                            require $file;
                        }
                    }
                });
                $debug_info['phpspreadsheet_autoloader'] = 'lib/PhpSpreadsheet/src';
            }
        }

        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            try {
                // Intentar derivar layout desde JRXML (título, orden de columnas y placeholders vacíos)
                $jrxml_columns = [];
                $jrxml_title = '';
                $jrxml_path = $root_path . 'reports/java/templates/' . $tipo . '.jrxml';
                if (is_file($jrxml_path)) {
                    try {
                        $dom = new DOMDocument();
                        $dom->load($jrxml_path);
                        $xp = new DOMXPath($dom);
                        $xp->registerNamespace('jr', 'http://jasperreports.sourceforge.net/jasperreports');
                        // Detectar título literal "Listado de Tickets"
                        $nodes = $xp->query("//jr:textFieldExpression");
                        if ($nodes) {
                            foreach ($nodes as $n) {
                                $val = trim($n->textContent);
                                if (strpos($val, 'Listado de Tickets') !== false) {
                                    $jrxml_title = 'Listado de Tickets';
                                    break;
                                }
                            }
                        }
                        // Obtener encabezados por posición X dentro de title o columnHeader
                        $headers_pos = [];
                        $headerParents = [
                            "//jr:title/jr:band//jr:textField",
                            "//jr:columnHeader/jr:band//jr:textField"
                        ];
                        foreach ($headerParents as $q) {
                            $hNodes = $xp->query($q);
                            if ($hNodes && $hNodes->length) {
                                foreach ($hNodes as $tf) {
                                    $exprNode = null;
                                    foreach ($tf->childNodes as $c) {
                                        if ($c->localName === 'textFieldExpression') { $exprNode = $c; break; }
                                    }
                                    if (!$exprNode) continue;
                                    $expr = trim($exprNode->textContent);
                                    // Sólo literales como "ID", "NUMERO", etc.
                                    if (!(strlen($expr) >= 2 && $expr[0] === '"' && substr($expr, -1) === '"')) {
                                        continue;
                                    }
                                    $label = trim($expr, '"');
                                    // Obtener X
                                    $x = 0;
                                    foreach ($tf->childNodes as $c) {
                                        if ($c->localName === 'reportElement') {
                                            if ($c->hasAttribute('x')) $x = (int)$c->getAttribute('x');
                                            break;
                                        }
                                    }
                                    $headers_pos[] = ['x' => $x, 'label' => $label];
                                }
                            }
                        }
                        usort($headers_pos, function($a,$b){ return $a['x'] <=> $b['x']; });

                        // Mapear detail fields por posición X
                        $detail_pos = [];
                        $dNodes = $xp->query("//jr:detail/jr:band//jr:textField");
                        if ($dNodes && $dNodes->length) {
                            foreach ($dNodes as $tf) {
                                $exprNode = null;
                                foreach ($tf->childNodes as $c) {
                                    if ($c->localName === 'textFieldExpression') { $exprNode = $c; break; }
                                }
                                // Obtener X
                                $x = 0;
                                foreach ($tf->childNodes as $c) {
                                    if ($c->localName === 'reportElement') {
                                        if ($c->hasAttribute('x')) $x = (int)$c->getAttribute('x');
                                        break;
                                    }
                                }
                                $field = null;
                                if ($exprNode) {
                                    $expr = trim($exprNode->textContent);
                                    // Buscar $F{campo}
                                    if (preg_match('/\$F\{([^}]+)\}/', $expr, $m)) {
                                        $field = $m[1];
                                    }
                                }
                                $detail_pos[] = ['x' => $x, 'field' => $field];
                            }
                            usort($detail_pos, function($a,$b){ return $a['x'] <=> $b['x']; });
                        }

                        // Construir columnas finales alineando headers con fields por posición
                        if (!empty($headers_pos)) {
                            foreach ($headers_pos as $hp) {
                                $closest = null; $closest_dx = PHP_INT_MAX;
                                foreach ($detail_pos as $dp) {
                                    $dx = abs($dp['x'] - $hp['x']);
                                    if ($dx < $closest_dx) { $closest = $dp; $closest_dx = $dx; }
                                }
                                $jrxml_columns[] = [
                                    'header' => $hp['label'],
                                    'field' => $closest && $closest_dx <= 20 ? ($closest['field'] ?: null) : null
                                ];
                            }
                        }
                    } catch (Throwable $pe) {
                        // Ignorar errores de parseo y continuar con fallback de columnas automáticas
                        error_log('Parseo JRXML para Excel: ' . $pe->getMessage());
                    }
                }

                // Preparar columnas/encabezados a usar
                $headers = [];
                $columns = [];
                if (!empty($jrxml_columns)) {
                    foreach ($jrxml_columns as $col) {
                        $headers[] = $col['header'];
                        $columns[] = $col['field']; // puede ser null para celdas vacías
                    }
                } else {
                    // Fallback a columnas desde los datos
                    if (!empty($rows)) {
                        foreach (array_keys($rows[0]) as $k) {
                            if (strtolower($k) === 'logo') continue;
                            $headers[] = $k;
                            $columns[] = $k;
                        }
                    }
                }
                if (empty($headers)) {
                    throw new Exception('No hay columnas para exportar a Excel');
                }

                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                // Título opcional
                $sheet->setTitle(substr((string)$tipo, 0, 31) ?: 'Reporte');

                $rowPtr = 1;
                if ($jrxml_title !== '') {
                    $sheet->setCellValue('A' . $rowPtr, $jrxml_title);
                    // Merge across all columns
                    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
                    $sheet->mergeCells('A' . $rowPtr . ':' . $lastCol . $rowPtr);
                    $sheet->getStyle('A' . $rowPtr)->getFont()->setBold(true)->setSize(16);
                    $rowPtr += 2; // línea en blanco
                }

                // Escribir encabezados
                $sheet->fromArray($headers, null, 'A' . $rowPtr);
                $sheet->getStyle('A' . $rowPtr . ':' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . $rowPtr)
                    ->getFont()->setBold(true);
                $rowPtr++;

                // Escribir datos respetando columnas (incluyendo vacías)
                $dataStartRow = $rowPtr;
                $r = $dataStartRow;
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $fieldKey) {
                        if ($fieldKey === null) { $line[] = ''; continue; }
                        $line[] = isset($row[$fieldKey]) ? $row[$fieldKey] : '';
                    }
                    $sheet->fromArray($line, null, 'A' . $r);
                    $r++;
                }
                // Auto tamaño de columnas
                for ($c = 1; $c <= count($headers); $c++) {
                    $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
                }

                // Nombre y writer según formato solicitado
                $ts = date('Ymd_His');
                $filename = 'reporte_' . $tipo . '_' . $ts . '.' . $formato;
                $output_file = $output_dir . $filename;
                if ($formato === 'xlsx') {
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                } else {
                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
                }
                try {
                    $writer->save($output_file);
                } catch (Throwable $we) {
                    // Fallback automático a XLS si falla ZIP/ZipArchive requerido por XLSX
                    if ($formato === 'xlsx') {
                        error_log('Fallo guardando XLSX, intentando XLS: ' . $we->getMessage());
                        $formato = 'xls';
                        $filename = 'reporte_' . $tipo . '_' . $ts . '.xls';
                        $output_file = $output_dir . $filename;
                        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
                        $writer->save($output_file);
                    } else {
                        throw $we;
                    }
                }

                if (!file_exists($output_file) || filesize($output_file) === 0) {
                    throw new Exception('No se pudo generar el archivo Excel');
                }

                $debug_info['excel_native'] = true;
                $debug_info['output_size'] = filesize($output_file);
                $download_url = '../reports/download.php?file=' . rawurlencode($filename);
                json_response([
                    'ok' => true,
                    'download' => $download_url,
                    'file' => $filename,
                    'records' => count($rows),
                    'debug' => $debug_info
                ]);
                return; // Importante: no continuar con JAR
            } catch (Throwable $te) {
                // Si falla PhpSpreadsheet, continúe con el flujo actual (HTML->XLS con JAR)
                error_log('Excel nativo falló: ' . $te->getMessage());
                $debug_info['excel_native_error'] = $te->getMessage();
                // continúa hacia la generación XML + JAR mapeado
            }
        } else {
            // Intentar cargar SimpleXLSXGen desde upload/lib si no hay Composer
            if ($formato === 'xlsx' && !class_exists('Shuchkin\\SimpleXLSXGen')) {
                $lib1 = $root_path . 'lib/SimpleXLSXGen.php';
                $lib2 = $root_path . 'lib/simplexlsxgen/SimpleXLSXGen.php';
                if (file_exists($lib1)) {
                    require_once $lib1;
                    $debug_info['simplexlsxgen_included'] = 'lib/SimpleXLSXGen.php';
                } elseif (file_exists($lib2)) {
                    require_once $lib2;
                    $debug_info['simplexlsxgen_included'] = 'lib/simplexlsxgen/SimpleXLSXGen.php';
                }
            }
            // Intentar con SimpleXLSXGen (ligero) solo para XLSX
            if ($formato === 'xlsx' && class_exists('Shuchkin\\SimpleXLSXGen')) {
                try {
                    // Intentar replicar layout básico desde JRXML (título y columnas)
                    $jrxml_columns = [];
                    $jrxml_title = '';
                    $jrxml_path = $root_path . 'reports/java/templates/' . $tipo . '.jrxml';
                    if (is_file($jrxml_path)) {
                        try {
                            $dom = new DOMDocument();
                            $dom->load($jrxml_path);
                            $xp = new DOMXPath($dom);
                            $xp->registerNamespace('jr', 'http://jasperreports.sourceforge.net/jasperreports');
                            $nodes = $xp->query("//jr:textFieldExpression");
                            if ($nodes) {
                                foreach ($nodes as $n) {
                                    $val = trim($n->textContent);
                                    if (strpos($val, 'Listado de Tickets') !== false) { $jrxml_title = 'Listado de Tickets'; break; }
                                }
                            }
                            $headers_pos = [];
                            foreach (["//jr:title/jr:band//jr:textField","//jr:columnHeader/jr:band//jr:textField"] as $q) {
                                $hNodes = $xp->query($q);
                                if ($hNodes && $hNodes->length) {
                                    foreach ($hNodes as $tf) {
                                        $exprNode = null; foreach ($tf->childNodes as $c) { if ($c->localName === 'textFieldExpression') { $exprNode = $c; break; } }
                                        if (!$exprNode) continue;
                                        $expr = trim($exprNode->textContent);
                                        if (!(strlen($expr) >= 2 && $expr[0] === '"' && substr($expr, -1) === '"')) continue;
                                        $label = trim($expr, '"');
                                        $x = 0; foreach ($tf->childNodes as $c) { if ($c->localName === 'reportElement') { if ($c->hasAttribute('x')) $x = (int)$c->getAttribute('x'); break; } }
                                        $headers_pos[] = ['x' => $x, 'label' => $label];
                                    }
                                }
                            }
                            usort($headers_pos, function($a,$b){ return $a['x'] <=> $b['x']; });
                            $detail_pos = [];
                            $dNodes = $xp->query("//jr:detail/jr:band//jr:textField");
                            if ($dNodes && $dNodes->length) {
                                foreach ($dNodes as $tf) {
                                    $exprNode = null; foreach ($tf->childNodes as $c) { if ($c->localName === 'textFieldExpression') { $exprNode = $c; break; } }
                                    $x = 0; foreach ($tf->childNodes as $c) { if ($c->localName === 'reportElement') { if ($c->hasAttribute('x')) $x = (int)$c->getAttribute('x'); break; } }
                                    $field = null;
                                    if ($exprNode) { $expr = trim($exprNode->textContent); if (preg_match('/\$F\{([^}]+)\}/', $expr, $m)) { $field = $m[1]; } }
                                    $detail_pos[] = ['x' => $x, 'field' => $field];
                                }
                                usort($detail_pos, function($a,$b){ return $a['x'] <=> $b['x']; });
                            }
                            if (!empty($headers_pos)) {
                                foreach ($headers_pos as $hp) {
                                    $closest = null; $closest_dx = PHP_INT_MAX;
                                    foreach ($detail_pos as $dp) { $dx = abs($dp['x'] - $hp['x']); if ($dx < $closest_dx) { $closest = $dp; $closest_dx = $dx; } }
                                    $jrxml_columns[] = ['header' => $hp['label'], 'field' => $closest && $closest_dx <= 20 ? ($closest['field'] ?: null) : null];
                                }
                            }
                        } catch (Throwable $pe) { error_log('Parseo JRXML (SimpleXLSXGen): ' . $pe->getMessage()); }
                    }
                    $headers = [];
                    $columns = [];
                    if (!empty($jrxml_columns)) {
                        foreach ($jrxml_columns as $col) { $headers[] = $col['header']; $columns[] = $col['field']; }
                    } else {
                        if (!empty($rows)) { foreach (array_keys($rows[0]) as $k) { if (strtolower($k) === 'logo') continue; $headers[] = $k; $columns[] = $k; } }
                    }
                    if (empty($headers)) { throw new Exception('No hay columnas para exportar a Excel'); }
                    $data = [];
                    if ($jrxml_title !== '') { $data[] = [$jrxml_title]; }
                    if ($jrxml_title !== '') { $data[] = []; }
                    $data[] = $headers;
                    foreach ($rows as $row) {
                        $line = [];
                        foreach ($columns as $fieldKey) { $line[] = $fieldKey === null ? '' : (isset($row[$fieldKey]) ? $row[$fieldKey] : ''); }
                        $data[] = $line;
                    }
                    $ts = date('Ymd_His');
                    $filename = 'reporte_' . $tipo . '_' . $ts . '.xlsx';
                    $output_file = $output_dir . $filename;
                    \Shuchkin\SimpleXLSXGen::fromArray($data)->saveAs($output_file);
                    if (!file_exists($output_file) || filesize($output_file) === 0) {
                        throw new Exception('No se pudo generar el archivo XLSX (SimpleXLSXGen)');
                    }
                    $debug_info['excel_native'] = true;
                    $debug_info['excel_engine'] = 'SimpleXLSXGen';
                    $debug_info['output_size'] = filesize($output_file);
                    $download_url = '../reports/download.php?file=' . rawurlencode($filename);
                    json_response([
                        'ok' => true,
                        'download' => $download_url,
                        'file' => $filename,
                        'records' => count($rows),
                        'debug' => $debug_info
                    ]);
                    return; // Importante: no continuar con JAR
                } catch (Throwable $te) {
                    error_log('Excel nativo (SimpleXLSXGen) falló: ' . $te->getMessage());
                    $debug_info['excel_native_error'] = $te->getMessage();
                }
            }

            // Sin librerías disponibles, continuar con flujo actual (HTML->XLS con JAR)
            $debug_info['excel_native'] = false;
            $debug_info['excel_native_error'] = ($formato === 'xlsx') ? 'PhpSpreadsheet/SimpleXLSXGen no disponibles' : 'PhpSpreadsheet no disponible';
        }
    }

    // =================================================================
    // GENERAR XML
    // =================================================================
    $temp_dir = $root_path . 'reports/temp/';
    $output_dir = $root_path . 'reports/output/';
    
    if (!is_dir($temp_dir)) mkdir($temp_dir, 0775, true);
    if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);
    
    $xml_file = $temp_dir . uniqid('report_', true) . '.xml';
    
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    
    $root = $xml->createElement('tickets');

    // Inyectar parámetros dinámicos (si fueron enviados como param[name])
    if (isset($_POST['param']) && is_array($_POST['param'])) {
        $paramsElem = $xml->createElement('parameters');
        $types = isset($_POST['param_type']) && is_array($_POST['param_type']) ? $_POST['param_type'] : [];
        foreach ($_POST['param'] as $pname => $pval) {
            $p = $xml->createElement('param');
            $p->setAttribute('name', (string)$pname);
            if (isset($types[$pname]) && $types[$pname] !== '') {
                $p->setAttribute('type', (string)$types[$pname]);
            }
            $p->appendChild($xml->createTextNode((string)$pval));
            $paramsElem->appendChild($p);
        }
        $root->appendChild($paramsElem);
    }
    
    // Volcar filas en XML (sirve como fallback si JDBC no devuelve páginas)
    foreach ($rows as $ticket) {
        $ticket_elem = $xml->createElement('ticket');
        foreach ($ticket as $key => $value) {
            $elem = $xml->createElement($key);
            $elem->appendChild($xml->createTextNode($value ?? ''));
            $ticket_elem->appendChild($elem);
        }
        $root->appendChild($ticket_elem);
    }
    
    $xml->appendChild($root);
    
    if (!$xml->save($xml_file)) {
        throw new Exception('Error al guardar XML');
    }
    
    $xml_size = filesize($xml_file);
    error_log("XML guardado: " . $xml_file . " (" . $xml_size . " bytes)");
    
    // =================================================================
    // INTENTAR GENERAR CON JASPERSTARTER (desde jasper-test)
    // =================================================================
    try {
        $output_dir = $root_path . 'reports/output/';
        if (!is_dir($output_dir)) mkdir($output_dir, 0775, true);

        $js_jar = $root_path . 'jasper-test/jasperstarter/bin/jasperstarter.jar';
        $js_mysql = $root_path . 'jasper-test/mysql-connector-java.jar';

        // Ubicar plantilla JRXML
        $jrxml_input = $root_path . 'reports/java/templates/' . $tipo . '.jrxml';
        if (!is_file($jrxml_input)) {
            // Fallback: jrxml en jasper-test
            $jrxml_input2 = $root_path . 'jasper-test/' . $tipo . '.jrxml';
            if (is_file($jrxml_input2)) $jrxml_input = $jrxml_input2;
        }

        if (is_file($js_jar) && is_file($jrxml_input)) {
            // Credenciales desde ost-config
            $cfg_file = $root_path . 'include/ost-config.php';
            $dbhost = 'localhost'; $dbname = ''; $dbuser = ''; $dbpass = ''; $dbport = '3306';
            if (is_file($cfg_file)) {
                try {
                    $content = @file_get_contents($cfg_file) ?: '';
                    $get = function($name) use ($content) { $re = '/define\s*\(\s*\'' . $name . '\'\s*,\s*\'(.*?)\'\s*\)\s*;/i'; return preg_match($re, $content, $m) ? $m[1] : null; };
                    $dbhost = $get('DBHOST') ?: $dbhost;
                    $dbname = $get('DBNAME') ?: $dbname;
                    $dbuser = $get('DBUSER') ?: $dbuser;
                    $dbpass = $get('DBPASS') ?: $dbpass;
                    $dbport = $get('DBPORT') ?: $dbport;
                } catch (Throwable $te) { /* usar defaults */ }
            }

            $ts = date('Ymd_His');
            $out_base = $output_dir . 'reporte_' . $tipo . '_' . $ts;
            $fmt = strtolower($formato);
            // Mapear formatos conocidos a JasperStarter
            $fmt_map = ['pdf'=>'pdf','html'=>'html','xlsx'=>'xlsx','xls'=>'xlsx','docx'=>'docx','csv'=>'csv'];
            $fmt = $fmt_map[$fmt] ?? 'pdf';

            // Construir comando JasperStarter
            $cmd = 'java -jar "' . $js_jar . '"';
            $cmd .= ' --locale es_ES';
            $cmd .= ' pr "' . $jrxml_input . '"';
            $cmd .= ' -o "' . $out_base . '"';
            $cmd .= ' -f ' . $fmt;
            $cmd .= ' -t mysql';
            $cmd .= ' -u ' . $dbuser;
            $cmd .= ' -p ' . $dbpass;
            $cmd .= ' -H ' . $dbhost;
            $cmd .= ' -n ' . $dbname;
            $cmd .= ' --db-port ' . $dbport;
            if (is_file($js_mysql)) {
                $cmd .= ' --jdbc-dir "' . dirname($js_mysql) . '"';
            }
            // Parámetros JRXML
            $params_to_pass = [
                'fecha_desde' => $desde_dt,
                'fecha_hasta' => $hasta_dt,
                'FECHA_DESDE' => $desde_dt,
                'FECHA_HASTA' => $hasta_dt,
            ];
            if ($departamento !== '') $params_to_pass['departamento'] = (int)$departamento;
            if ($estado !== '') $params_to_pass['estado'] = (int)$estado;
            foreach ($params_to_pass as $k=>$v) { $cmd .= ' -P ' . $k . '="' . str_replace('"','', (string)$v) . '"'; }
            $cmd .= ' 2>&1';

            $debug_info['jasperstarter_cmd'] = $cmd;
            exec($cmd, $js_out, $js_rc);
            $debug_info['jasperstarter_out'] = implode("\n", $js_out);

            // Detectar archivo generado
            $generated = null;
            $cands = [$out_base . '.' . $fmt, $out_base . '.' . strtoupper($fmt), $out_base];
            foreach ($cands as $c) { if (file_exists($c)) { $generated = $c; break; } }
            if (!$generated) {
                $globbed = glob($out_base . '.*') ?: [];
                if (!empty($globbed)) $generated = $globbed[0];
            }

            if ($js_rc === 0 && $generated && filesize($generated) > 0) {
                $filename = basename($generated);
                $is_html = (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'html');
                $download_url = $is_html
                    ? ('../reports/output/' . $filename)
                    : ('../reports/download.php?file=' . rawurlencode($filename));

                json_response([
                    'ok' => true,
                    'engine' => 'jasperstarter',
                    'download' => $download_url,
                    'file' => $filename,
                    'records' => ($datasource === 'sql') ? ($total ?? 0) : count($rows),
                    'debug' => $debug_info
                ]);
                return; // No continuar con JAR
            }
            // Si falla, continuar con JAR como fallback
            $debug_info['jasperstarter_error_code'] = $js_rc;
        }
    } catch (Throwable $te) {
        $debug_info['jasperstarter_error'] = $te->getMessage();
    }

    // =================================================================
    // EJECUTAR JAR (fallback)
    // =================================================================
    $jar_dir = $root_path . 'reports/java/';
    $jar_path = $jar_dir . 'osticket-reporter.jar';
    
    if (!file_exists($jar_path)) {
        @unlink($xml_file);
        throw new Exception('Archivo JAR no encontrado: ' . $jar_path);
    }
    
    // Mapear formatos solicitados a formatos soportados por el JAR
    $requested_format = $formato; // pdf|html|csv|xlsx|xls
    $jar_format = ($requested_format === 'xlsx' || $requested_format === 'xls') ? 'html' : $requested_format;
    $out_ext = ($requested_format === 'xlsx' || $requested_format === 'xls') ? 'xls' : $requested_format;
    $filename = 'reporte_' . $tipo . '_' . date('Ymd_His') . '.' . $out_ext;
    $output_file = $output_dir . $filename;
    $debug_info['format_requested'] = $requested_format;
    $debug_info['format_jar'] = $jar_format;
    $debug_info['format_output_ext'] = $out_ext;
    
    // Asegurar CWD para que Java encuentre templates/<tipo>.jrxml
    $prev_cwd = getcwd();
    @chdir($jar_dir);
    // Configurar propiedades de exportación para Excel sin tocar los JRXML
    $java_props = '';
    if ($formato === 'xls' || $formato === 'xlsx') {
        $excelFlags = [
            // XLS
            '-Dnet.sf.jasperreports.export.xls.detect.cell.type=true',
            '-Dnet.sf.jasperreports.export.xls.remove.empty.space.between.rows=true',
            '-Dnet.sf.jasperreports.export.xls.remove.empty.space.between.columns=true',
            '-Dnet.sf.jasperreports.export.xls.one.page.per.sheet=false',
            '-Dnet.sf.jasperreports.export.xls.white.page.background=false',
            // XLSX
            '-Dnet.sf.jasperreports.export.xlsx.detect.cell.type=true',
            '-Dnet.sf.jasperreports.export.xlsx.remove.empty.space.between.rows=true',
            '-Dnet.sf.jasperreports.export.xlsx.remove.empty.space.between.columns=true',
            '-Dnet.sf.jasperreports.export.xlsx.one.page.per.sheet=false',
            '-Dnet.sf.jasperreports.export.xlsx.white.page.background=false'
        ];
        $java_props = implode(' ', $excelFlags) . ' ';
    }
    // Datasource forzar sql
    $java_props .= '-Ddatasource=sql ';

    // Si se pide SQL, pasar credenciales desde include/ost-config.php
    if ($datasource === 'sql') {
        $cfg_file = $root_path . 'include/ost-config.php';
        $dbhost = 'localhost'; $dbname = ''; $dbuser = ''; $dbpass = ''; $dbport = '3306';
        if (is_file($cfg_file)) {
            try {
                $content = @file_get_contents($cfg_file) ?: '';
                $get = function($name) use ($content) {
                    $re = '/define\s*\(\s*\'' . $name . '\'\s*,\s*\'(.*?)\'\s*\)\s*;/i';
                    if (preg_match($re, $content, $m)) { return $m[1]; }
                    return null;
                };
                $dbhost = $get('DBHOST') ?: $dbhost;
                $dbname = $get('DBNAME') ?: $dbname;
                $dbuser = $get('DBUSER') ?: $dbuser;
                $dbpass = $get('DBPASS') ?: $dbpass;
                $dbport = $get('DBPORT') ?: $dbport;
            } catch (Throwable $te) { /* ignore and use defaults */ }
        }
        // Debug de conectividad a BD (equivalente a JDBC): probar conexión MySQL/MariaDB desde PHP
        if (function_exists('mysqli_connect')) {
            $mysqli = @mysqli_connect($dbhost, $dbuser, $dbpass, $dbname, (int)$dbport);
            if ($mysqli) {
                $debug_info['jdbc_connect_php'] = true;
                mysqli_close($mysqli);
            } else {
                $debug_info['jdbc_connect_php'] = false;
                $debug_info['jdbc_connect_php_error'] = mysqli_connect_error();
            }
        } else {
            $debug_info['jdbc_connect_php'] = 'mysqli_not_available';
        }
        // Añadir como propiedades Java sin comillas (evita valores con comillas literales en System.getProperty)
        $sanitize = function($v) { return str_replace(['"',"'"], '', (string)$v); };
        $java_props .= '-Ddb.host=' . $sanitize($dbhost) . ' ';
        $java_props .= '-Ddb.name=' . $sanitize($dbname) . ' ';
        $java_props .= '-Ddb.user=' . $sanitize($dbuser) . ' ';
        $java_props .= '-Ddb.pass=' . $sanitize($dbpass) . ' ';
        $java_props .= '-Ddb.port=' . $sanitize($dbport) . ' ';
    }
    // Construir comando Java: si datasource=sql usar -cp (para incluir driver JDBC); de lo contrario usar -jar
    // Verificar presencia de mysql-connector-j en lib/
    $lib_dir = rtrim($jar_dir, '/\\') . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR;
    $driver_present = false;
    $driver_name = null;
    if (is_dir($lib_dir)) {
        $patterns = [
            'mysql-connector-j-*.jar',
            'mysql-connector-java-*.jar',
            'mysql-connector-*.jar',
            'mariadb-java-client-*.jar'
        ];
        foreach ($patterns as $pat) {
            foreach (glob($lib_dir . $pat) ?: [] as $f) {
                if (is_file($f)) { $driver_present = true; $driver_name = basename($f); break 2; }
            }
        }
    }
    if (!$driver_present) {
        $debug_info['warnings'][] = 'No se detectó mysql-connector-j en reports/java/lib. El modo SQL fallará.';
        $debug_info['hints'][] = 'Copia el archivo mysql-connector-j-<versión>.jar dentro de upload/reports/java/lib/';
        $debug_info['jdbc_driver_present'] = false;
    } else {
        $debug_info['jdbc_driver_present'] = true;
        $debug_info['jdbc_driver'] = $driver_name;
    }
    $sep = (stripos(PHP_OS, 'WIN') === 0) ? ';' : ':';
    $classpath = '"osticket-reporter.jar' . $sep . 'lib/*"';
    $mainClass = 'com.miempresa.osticket.OsTicketReporter';
    $cmd = 'java ' . $java_props . '-cp ' . $classpath . ' ' . $mainClass . ' "' . $xml_file . '" "' . $tipo . '" "' . $jar_format . '" "' . $output_file . '" 2>&1';
    error_log("Ejecutando Java (cwd=" . getcwd() . "): " . $cmd);
    
    exec($cmd, $cmd_out, $rc);
    $debug_info['java_cmd'] = preg_replace('/-Ddb\.pass=\S+/', '-Ddb.pass=****', $cmd);
    $debug_info['jar_output'] = implode("\n", $cmd_out);
    
    // Limpiar archivo temporal
    @unlink($xml_file);
    
    error_log("JAR resultado: código={$rc}, salida=" . implode("\n", $cmd_out));
    // Restaurar CWD
    @chdir($prev_cwd ?: $prev_cwd);
    
    if ($rc !== 0) {
        $jar_output = implode("\n", $cmd_out);
        // Fallback: si falta plantilla JRXML, intentar generar HTML simple
        $msg = strtolower($jar_output);
        $missing_template = (stripos($msg, 'jrxml') !== false && (stripos($msg, 'plantilla') !== false || stripos($msg, 'template') !== false || stripos($msg, 'no se encontr') !== false));
        if ($missing_template && $formato !== 'html') {
            error_log('Plantilla JRXML no encontrada; generando HTML simple como fallback');
            $original_format = $formato;
            $formato = 'html';
            $filename = 'reporte_' . $tipo . '_' . date('Ymd_His') . '.' . $formato;
            $output_file = $output_dir . $filename;
            $debug_info['fallback'] = 'html_error';
            $debug_info['jar_output'] = $jar_output;
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error reporte ' . htmlspecialchars($tipo) . '</title>' .
                '<style>body{font-family:Arial,sans-serif;font-size:13px;margin:20px;}pre{background:#f5f5f5;border:1px solid #ddd;padding:10px;white-space:pre-wrap;}</style>' .
                '</head><body><h2>Error al generar reporte</h2>' .
                '<p><strong>Tipo:</strong> ' . htmlspecialchars($tipo) . '</p>' .
                '<p><strong>Formato solicitado:</strong> ' . htmlspecialchars($original_format) . '</p>' .
                '<p><strong>Motivo:</strong> No se encontró la plantilla JRXML esperada (templates/' . htmlspecialchars($tipo) . '.jrxml).</p>' .
                '<h3>Salida del proceso</h3><pre>' . htmlspecialchars($jar_output) . '</pre>' .
                '</body></html>';
            file_put_contents($output_file, $html);
            // Si se solicitó PDF originalmente y Dompdf está disponible, convertir a PDF
            if (strtolower($original_format) === 'pdf') {
                $dompdf_autoload = $root_path . 'vendor/autoload.php';
                if (file_exists($dompdf_autoload)) {
                    require_once $dompdf_autoload;
                }
                if (class_exists('Dompdf\\Dompdf')) {
                    error_log('Usando Dompdf para convertir HTML a PDF como fallback');
                    try {
                        $pdf_file = $output_dir . 'reporte_' . $tipo . '_' . date('Ymd_His') . '.pdf';
                        $dompdf = new Dompdf\Dompdf();
                        $dompdf->loadHtml($html);
                        $dompdf->setPaper('A4', 'portrait');
                        $dompdf->render();
                        file_put_contents($pdf_file, $dompdf->output());
                        $formato = 'pdf';
                        $filename = basename($pdf_file);
                        $output_file = $pdf_file;
                        $debug_info['pdf_fallback'] = 'dompdf';
                    } catch (Throwable $te) {
                        error_log('Error Dompdf fallback: ' . $te->getMessage());
                        // dejar HTML
                        $debug_info['pdf_fallback_error'] = $te->getMessage();
                    }
                } else {
                    $debug_info['pdf_fallback'] = 'dompdf_not_available';
                }
            }
        } else {
            @unlink($output_file);
            throw new Exception('Error ejecutando JAR (código: ' . $rc . '): ' . $jar_output);
        }
    }
    
    if (!file_exists($output_file) || filesize($output_file) === 0) {
        throw new Exception('JAR no generó archivo de salida');
    }
    
    error_log("✅ Reporte generado: " . $filename . " (" . filesize($output_file) . " bytes)");
    
    // =================================================================
    // ÉXITO
    // =================================================================
    $debug_info['tickets_found'] = $total;
    $debug_info['tickets_processed'] = count($rows);
    $debug_info['xml_size'] = $xml_size ?? 0;
    $debug_info['output_size'] = filesize($output_file);

    $is_html = (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'html');
    $download_url = $is_html
        ? ('../reports/output/' . $filename)
        : ('../reports/download.php?file=' . rawurlencode($filename));

    error_log('Debug dump (ok): ' . json_encode($debug_info));
    json_response([
        'ok' => true,
        'download' => $download_url,
        'file' => $filename,
        'records' => ($datasource === 'sql') ? ($total ?? 0) : count($rows),
        'debug' => $debug_info
    ]);
    
} catch (Throwable $e) {
    error_log("❌ ERROR (throwable): " . $e->getMessage());
    if (!isset($debug_info)) {
        $debug_info = [];
    }
    $debug_info['error'] = $e->getMessage();
    $debug_info['file'] = $e->getFile();
    $debug_info['line'] = $e->getLine();
    error_log('Debug dump (error): ' . json_encode($debug_info));
    json_response(['error' => $e->getMessage(), 'debug' => $debug_info], 500);
}