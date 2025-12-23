<?php
// Shared Jasper configuration and functions

// ========== CONFIGURACIÓN ==========
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';
$mysqlConnector = __DIR__ . '/mysql-connector-java.jar';

// Configuración BD
$configDB = [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'osticket_db',
    'username' => 'osticket_user',
    'password' => '123456'
];

// Carpeta de salida
$storage = __DIR__ . '/storage';
if (!is_dir($storage)) {
    @mkdir($storage, 0777, true);
}

// ========== FUNCIONES ==========
function buscarReportes($directorio) {
    $reportes = [];
    if (!is_dir($directorio)) return $reportes;
    $archivos = glob($directorio . '/*.jrxml');
    foreach ($archivos as $archivo) {
        $nombre = basename($archivo, '.jrxml');
        $tamano = @round(filesize($archivo) / 1024, 2);
        $fecha = @date('Y-m-d H:i', filemtime($archivo));
        $nombreAmigable = ucwords(str_replace(['_', '-'], ' ', $nombre));
        $reportes[] = [
            'archivo' => $archivo,
            'nombre' => $nombre,
            'nombre_amigable' => $nombreAmigable,
            'tamano_kb' => $tamano,
            'fecha_mod' => $fecha,
            'ruta_relativa' => basename($archivo)
        ];
    }
    // Buscar en subdirectorios comunes
    foreach (['reports','reportes','jasper','jrxml','templates'] as $subdir) {
        $path = $directorio . '/' . $subdir;
        if (is_dir($path)) {
            $reportes = array_merge($reportes, buscarReportes($path));
        }
    }
    return $reportes;
}

function verificarRequisitos() {
    global $java8Path, $jasperJar, $mysqlConnector;
    $requisitos = [
        'Java 8' => ['archivo' => $java8Path, 'desc' => 'JDK 8 para ejecutar Jasper'],
        'JasperStarter' => ['archivo' => $jasperJar, 'desc' => 'Motor de generación de reportes'],
        'MySQL Connector' => ['archivo' => $mysqlConnector, 'desc' => 'Driver JDBC para MySQL'],
    ];
    $resultados = [];
    foreach ($requisitos as $nombre => $info) {
        $existe = file_exists($info['archivo']);
        $resultados[$nombre] = [
            'existe' => $existe,
            'desc' => $info['desc'],
            'ruta' => $info['archivo']
        ];
    }
    return $resultados;
}

function generarReporte($jrxml, $formato = 'pdf', $parametros = []) {
    global $java8Path, $jasperJar, $mysqlConnector, $configDB, $storage;
    $nombreBase = basename($jrxml, '.jrxml');
    $timestamp = date('Ymd_His');
    $outputBase = $storage . '/' . $nombreBase . '_' . $timestamp;
    $cmd = '"' . $java8Path . '" -jar ' . '"' . $jasperJar . '"';
    $cmd .= ' --locale es_ES';
    $cmd .= ' pr ' . '"' . $jrxml . '"';
    $cmd .= ' -o ' . '"' . $outputBase . '"';
    $cmd .= ' -f ' . $formato;
    $cmd .= ' -t mysql';
    $cmd .= ' -u ' . $configDB['username'];
    $cmd .= ' -p ' . $configDB['password'];
    $cmd .= ' -H ' . $configDB['host'];
    $cmd .= ' -n ' . $configDB['database'];
    $cmd .= ' --db-port ' . $configDB['port'];
    $cmd .= ' --jdbc-dir ' . '"' . dirname($mysqlConnector) . '"';
    foreach ($parametros as $key => $value) {
        if ($value === '' || $value === null) continue;
        $cmd .= ' -P ' . $key . '="' . $value . '"';
    }
    $cmd .= ' 2>&1';
    exec($cmd, $output, $code);
    $archivoGenerado = null;
    $formatoExt = strtolower($formato);
    foreach ([$outputBase . '.' . $formatoExt, $outputBase . '.' . strtoupper($formatoExt), $outputBase] as $candidato) {
        if (file_exists($candidato)) { $archivoGenerado = $candidato; break; }
    }
    if (!$archivoGenerado) {
        $archivos = glob($outputBase . '.*');
        if (!empty($archivos)) { $archivoGenerado = $archivos[0]; }
    }
    return [
        'exitoso' => ($code === 0),
        'codigo' => $code,
        'salida' => $output,
        'archivo' => $archivoGenerado,
        'comando' => $cmd,
        'nombre_base' => $nombreBase
    ];
}
