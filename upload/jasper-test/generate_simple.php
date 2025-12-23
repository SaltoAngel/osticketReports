<?php
// generate_simple.php - Modo sin JasperStarter
require __DIR__ . '/vendor/autoload.php';

use PHPJasper\PHPJasper;

// Configurar Java 8
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
putenv("JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6");

$configDB = [
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'osticket_db',
    'username' => 'osticket_user',
    'password' => '123456'
];

$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

echo "<h3>🔄 Modo Simple - Usando Java directamente</h3>";

// Verificar Java
exec("\"$java8Path\" -version 2>&1", $output, $code);
if ($code !== 0) {
    die("Java 8 no funciona");
}

echo "<p>✅ Java 8 funcionando</p>";
echo "<pre>" . htmlspecialchars(implode("\n", array_slice($output, 0, 2))) . "</pre>";

try {
    $jasper = new PHPJasper();
    
    // NO usar JasperStarter
    $jasper->setJasperStarterPath(null);
    
    $jrxml = __DIR__ . '/Tickets_Cerrados_por.jrxml';
    $outputFile = __DIR__ . '/storage/reporte_simple_' . date('Ymd_His') . '.pdf';
    
    if (!file_exists($jrxml)) {
        die("Archivo .jrxml no encontrado");
    }
    
    $options = [
        'format' => ['pdf'],
        'params' => [
            'fecha_desde' => $fecha_desde,
            'fecha_hasta' => $fecha_hasta
        ],
        'db_connection' => $configDB
    ];
    
    echo "<p>Generando reporte...</p>";
    ob_flush();
    flush();
    
    // Primero compilar a .jasper
    $jasperFile = str_replace('.jrxml', '.jasper', $jrxml);
    
    if (!file_exists($jasperFile)) {
        echo "<p>Compilando .jrxml...</p>";
        $jasper->compile($jrxml)->execute();
    }
    
    // Ejecutar con .jasper
    $jasper->process($jasperFile, __DIR__ . '/storage/reporte_simple', $options)->execute();
    
    echo "<div class='alert alert-success'>
            ✅ Reporte generado<br>
            <a href='storage/reporte_simple.pdf'>Descargar PDF</a>
          </div>";
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}