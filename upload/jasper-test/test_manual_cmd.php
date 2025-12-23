<?php
// test_manual_cmd.php - VERSIÓN CORREGIDA
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';
$mysqlConnector = __DIR__ . '/mysql-connector-java.jar';
$jrxml = __DIR__ . '/Tickets_Cerrados_por.jrxml';

echo "<h3>🧪 Probando configuración manual - CORREGIDO</h3>";

// Verificar archivos
echo "<h4>1. Archivos requeridos:</h4>";
echo "<ul>";
echo "<li>Java 8: " . (file_exists($java8Path) ? "✅" : "❌") . "</li>";
echo "<li>JasperStarter JAR: " . (file_exists($jasperJar) ? "✅ " . round(filesize($jasperJar)/1024,2) . " KB" : "❌") . "</li>";
echo "<li>MySQL Connector: " . (file_exists($mysqlConnector) ? "✅ " . round(filesize($mysqlConnector)/1024/1024,2) . " MB" : "❌") . "</li>";
echo "<li>JRXML: " . (file_exists($jrxml) ? "✅ " . basename($jrxml) : "❌") . "</li>";
echo "</ul>";

// Probar Java
echo "<h4>2. Probando Java:</h4>";
exec("\"$java8Path\" -version 2>&1", $javaOutput, $javaCode);
echo "<pre>" . htmlspecialchars(implode("\n", array_slice($javaOutput, 0, 2))) . "</pre>";

// ========== MÉTODO CORRECTO ==========
echo "<h4>3. Método CORRECTO para ejecutar JasperStarter:</h4>";

// **OPCIÓN 1: Usar -jar (recomendado)**
echo "<h5>Opción 1: Usando -jar (el JAR ya incluye las dependencias)</h5>";

$cmd1 = "\"$java8Path\" -jar \"$jasperJar\" --version 2>&1";
exec($cmd1, $output1, $code1);

echo "<pre>Comando: " . htmlspecialchars($cmd1) . "\n\n";
echo "Salida (código: $code1):\n" . htmlspecialchars(implode("\n", $output1)) . "</pre>";

if ($code1 === 0) {
    echo "<div class='alert alert-success'>✅ JasperStarter funciona con -jar</div>";
    
    // Probar generación REAL
    echo "<h4>4. Probando generación REAL con -jar:</h4>";
    
    $configDB = [
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'osticket_db',
        'username' => 'osticket_user',
        'password' => '123456'
    ];
    
    $outputFile = __DIR__ . '/storage/test_real_' . date('His');
    
    // **COMANDO CORRECTO: usar -jar y pasar el classpath para MySQL**
    $cmd2 = "\"$java8Path\" -jar \"$jasperJar\"";
    $cmd2 .= " --locale es_ES";
    $cmd2 .= " pr \"$jrxml\"";
    $cmd2 .= " -o \"$outputFile\"";
    $cmd2 .= " -f pdf";
    $cmd2 .= " -t mysql";
    $cmd2 .= " -u " . $configDB['username'];
    $cmd2 .= " -p " . $configDB['password'];
    $cmd2 .= " -H " . $configDB['host'];
    $cmd2 .= " -n " . $configDB['database'];
    $cmd2 .= " --db-port " . $configDB['port'];
    // ESTA ES LA LÍNEA IMPORTANTE CORREGIDA:
    $cmd2 .= " --jdbc-dir \"" . dirname($mysqlConnector) . "\"";
    $cmd2 .= " 2>&1";
    
    echo "<pre>Comando completo:\n" . htmlspecialchars($cmd2) . "</pre>";
    echo "<p>Ejecutando... ⏳ (puede tardar 10-20 segundos)</p>";
    
    ob_flush();
    flush();
    
    $startTime = microtime(true);
    exec($cmd2, $output2, $code2);
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    echo "<pre>Salida (código: $code2, tiempo: {$executionTime}s):\n" . htmlspecialchars(implode("\n", $output2)) . "</pre>";
    
    if ($code2 === 0) {
        $pdfFile = $outputFile . '.pdf';
        if (file_exists($pdfFile)) {
            $size = round(filesize($pdfFile) / 1024, 2);
            echo "<div class='alert alert-success'>
                    <h5>🎉 ¡ÉXITO TOTAL!</h5>
                    <p>Archivo generado: " . basename($pdfFile) . " ($size KB)</p>
                    <p>Tiempo de ejecución: {$executionTime} segundos</p>
                    <a href='storage/" . basename($pdfFile) . "' class='btn btn-primary' target='_blank'>👁️ Ver PDF</a>
                    <a href='generate_final_correct.php' class='btn btn-success'>🚀 Usar generador principal</a>
                  </div>";
        } else {
            echo "<div class='alert alert-warning'>
                    <h5>⚠️ Comando exitoso pero archivo no encontrado</h5>
                    <p>Archivos en carpeta storage:</p>
                    <ul>";
            
            $files = glob(__DIR__ . '/storage/*');
            foreach (array_slice($files, -5) as $file) {
                echo "<li>" . basename($file) . " (" . round(filesize($file)/1024,2) . " KB)</li>";
            }
            
            echo "</ul></div>";
        }
    } else {
        echo "<div class='alert alert-danger'>
                <h5>❌ Error en generación</h5>
                <p>Posibles problemas:</p>
                <ol>
                    <li><strong>Falta --cp para MySQL Connector</strong></li>
                    <li>Conexión a MySQL falla</li>
                    <li>Credenciales incorrectas</li>
                </ol>
                
                <h6 class='mt-3'>🛠️ Prueba alternativa:</h6>
                <p><a href='test_simple_generation.php' class='btn btn-sm btn-outline-primary'>🧪 Probar comando alternativo</a></p>
              </div>";
    }
} else {
    echo "<div class='alert alert-danger'>❌ JasperStarter no funciona con -jar</div>";
    
    // **OPCIÓN 2: Probar método alternativo**
    echo "<h5>Opción 2: Método alternativo (usando el batch file)</h5>";
    
    $batchFile = __DIR__ . '/jasperstarter/bin/jasperstarter_fixed.bat';
    if (file_exists($batchFile)) {
        echo "<p>✅ Batch file encontrado</p>";
        
        $cmd3 = "\"$batchFile\" --version 2>&1";
        exec($cmd3, $output3, $code3);
        
        echo "<pre>Comando: " . htmlspecialchars($cmd3) . "\n\n";
        echo "Salida: " . htmlspecialchars(implode("\n", $output3)) . "</pre>";
    }
}