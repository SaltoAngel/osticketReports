<?php
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

// Obtener parámetros
$tipo    = $_POST['tipo'] ?? 'tickets';
$tipo    = preg_replace('/[^a-zA-Z0-9_-]/', '', $tipo);
$desde   = $_POST['fecha_desde'] ?? '';
$hasta   = $_POST['fecha_hasta'] ?? '';
$formato = strtolower($_POST['formato'] ?? 'pdf');
$departamento = trim($_POST['departamento'] ?? '');
$estado = trim($_POST['estado'] ?? '');

$tickets_table = defined('TICKET_TABLE') ? TICKET_TABLE : TABLE_PREFIX . 'ticket';
// Tablas relacionadas para nombres legibles
$status_table = TABLE_PREFIX . 'ticket_status';
$dept_table = TABLE_PREFIX . 'department';
$user_table = TABLE_PREFIX . 'user';
$user_cdata_table = TABLE_PREFIX . 'user__cdata';

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
    
    // CONSULTA 2: Obtener datos con nombres (LEFT JOIN)
    $sql = "SELECT 
        t.ticket_id,
        t.number,
        t.subject,
        DATE_FORMAT(t.created, '%Y-%m-%d %H:%i:%s') as fecha_creacion,
        t.status_id,
        t.dept_id,
        t.user_id,
        s.name AS estado_nombre,
        d.name AS departamento_nombre,
        COALESCE(ucd.name, u.name) AS cliente_nombre
    FROM {$tickets_table} t
    LEFT JOIN {$status_table} s ON s.id = t.status_id
    LEFT JOIN {$dept_table} d ON d.dept_id = t.dept_id
    LEFT JOIN {$user_table} u ON u.id = t.user_id
    LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id
    WHERE {$where}
    ORDER BY t.created DESC 
    LIMIT 500";
    
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
        $result = $simple_result;
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
            t.status_id, t.dept_id, t.user_id,
            s.name AS estado_nombre,
            d.name AS departamento_nombre,
            COALESCE(ucd.name, u.name) AS cliente_nombre
        FROM {$tickets_table} t
        LEFT JOIN {$status_table} s ON s.id = t.status_id
        LEFT JOIN {$dept_table} d ON d.dept_id = t.dept_id
        LEFT JOIN {$user_table} u ON u.id = t.user_id
        LEFT JOIN {$user_cdata_table} ucd ON ucd.user_id = u.id
        WHERE {$date_where} 
        ORDER BY t.created DESC 
        LIMIT 500";
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
                status_id, dept_id, user_id
            FROM {$tickets_table}
            WHERE {$where}
            ORDER BY created DESC
            LIMIT 500";
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

    foreach ($raw_rows as $row) {
        $rows[] = [
            'id' => $row['ticket_id'] ?? $row['id'] ?? '',
            'numero' => $row['number'] ?? $row['numero'] ?? '',
            'asunto' => $row['subject'] ?? $row['asunto'] ?? '(Sin asunto)',
            'fecha_creacion' => $row['fecha_creacion'] ?? $row['created'] ?? '',
            'estado' => $row['estado_nombre'] ?? ('Estado #' . ($row['status_id'] ?? '0')),
            'departamento' => $row['departamento_nombre'] ?? ('Depto #' . ($row['dept_id'] ?? '0')),
            'cliente' => $row['cliente_nombre'] ?? ('Usuario #' . ($row['user_id'] ?? '0')),
            'logo' => $logo_to_embed
        ];
        $count++;
    }
    
    error_log("Filas procesadas: " . $count);
    
    if (empty($rows)) {
        // Probar una consulta directa sin alias
        $direct_sql = "SELECT * FROM {$tickets_table} 
                       WHERE {$where}
                       LIMIT 5";
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

            // Usar filas directas como último recurso si existen
            if (!empty($direct_rows)) {
                foreach ($direct_rows as $row) {
                    $rows[] = [
                        'id' => $row['ticket_id'] ?? $row['id'] ?? '',
                        'numero' => $row['number'] ?? $row['numero'] ?? '',
                        'asunto' => $row['subject'] ?? $row['asunto'] ?? '(Sin asunto)',
                        'fecha_creacion' => $row['created'] ?? '',
                        'estado' => $row['estado_nombre'] ?? ('Estado #' . ($row['status_id'] ?? '0')),
                        'departamento' => $row['departamento_nombre'] ?? ('Depto #' . ($row['dept_id'] ?? '0')),
                        'cliente' => $row['cliente_nombre'] ?? ('Usuario #' . ($row['user_id'] ?? '0'))
                    ];
                }
                $count = count($rows);
                error_log('Usando filas de consulta directa como fallback final');
            }
        }
        
        if (empty($rows)) {
            error_log('Debug dump (sin filas): ' . json_encode($debug_info));
            throw new Exception("No se pudieron procesar los datos. COUNT: {$total}, Filas procesadas: 0");
        }
    }
    
    error_log("Filas procesadas: " . count($rows));
    
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
    // EJECUTAR JAR
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
    $cmd = 'java ' . $java_props . '-jar "osticket-reporter.jar" "' . $xml_file . '" "' . $tipo . '" "' . $jar_format . '" "' . $output_file . '" 2>&1';
    error_log("Ejecutando JAR (cwd=" . getcwd() . "): " . $cmd);
    
    exec($cmd, $cmd_out, $rc);
    
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
        'records' => count($rows),
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