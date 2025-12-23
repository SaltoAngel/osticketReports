<?php
// test_simple_cmd.php - Probar el comando EXACTO
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';
$jrxml = __DIR__ . '/Tickets_Cerrados_por.jrxml';

$configDB = [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'osticket_db',
    'username' => 'osticket_user',
    'password' => '123456'
];

echo "<h3>🧪 Probando comando EXACTO que debería funcionar</h3>";

// **COMANDO CORRECTO** con --locale en la posición correcta
$cmd = "\"$java8Path\" -jar \"$jasperJar\"";
$cmd .= " --locale es_ES";
$cmd .= " pr \"$jrxml\"";
$cmd .= " -o \"" . __DIR__ . "/storage/test_exact\"";
$cmd .= " -f pdf";
$cmd .= " -t mysql";
$cmd .= " -u " . $configDB['username'];
$cmd .= " -p " . $configDB['password'];
$cmd .= " -H " . $configDB['host'];
$cmd .= " -n " . $configDB['database'];
$cmd .= " --db-port " . $configDB['port'];
$cmd .= " 2>&1";

echo "<pre><strong>Comando:</strong>\n" . htmlspecialchars($cmd) . "</pre>";

echo "<p>Ejecutando... ⏳</p>";
ob_flush();
flush();

exec($cmd, $output, $code);

echo "<pre><strong>Salida (código: $code):</strong>\n" . htmlspecialchars(implode("\n", $output)) . "</pre>";

if ($code === 0) {
    $pdfFile = __DIR__ . '/storage/test_exact.pdf';
    if (file_exists($pdfFile)) {
        $fileSize = round(filesize($pdfFile) / 1024, 2);
        echo "<div class='alert alert-success'>
                <h5>✅ ¡FUNCIONÓ PERFECTAMENTE!</h5>
                <p>Archivo generado: test_exact.pdf ($fileSize KB)</p>
                <a href='storage/test_exact.pdf' class='btn btn-primary' target='_blank'>👁️ Ver PDF</a>
                <a href='index.php' class='btn btn-success'>🚀 Usar el generador principal</a>
              </div>";
    } else {
        echo "<div class='alert alert-warning'>Comando exitoso pero archivo no encontrado</div>";
    }
} else {
    echo "<div class='alert alert-danger'>
            <h5>❌ Error en ejecución</h5>
            <p>Posible problema con la conexión a la base de datos.</p>
            <p><a href='test_db.php' class='btn btn-sm btn-outline-warning'>🔍 Probar conexión MySQL</a></p>
          </div>";
}