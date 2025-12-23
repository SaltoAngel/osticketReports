<?php
// test_direct_command.php - Probar comando directo
echo "<h3>🧪 Probando comando Java + JasperStarter directo</h3>";

$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';

if (!file_exists($java8Path)) {
    die("❌ Java 8 no encontrado");
}

if (!file_exists($jasperJar)) {
    die("❌ jasperstarter.jar no encontrado. Ejecuta fix_jasperstarter.php");
}

echo "<p>✅ Java 8: $java8Path</p>";
echo "<p>✅ JasperStarter JAR: " . basename($jasperJar) . " (" . round(filesize($jasperJar)/1024,2) . " KB)</p>";

// Probar --help
$cmd = "\"$java8Path\" -jar \"$jasperJar\" --help 2>&1";
echo "<h4>Comando:</h4><pre>" . htmlspecialchars($cmd) . "</pre>";

exec($cmd, $output, $code);

echo "<h4>Resultado (código: $code):</h4>";
echo "<pre style='max-height: 400px; overflow:auto;'>" . htmlspecialchars(implode("\n", $output)) . "</pre>";

if ($code === 0) {
    echo "<div class='alert alert-success'>✅ JasperStarter funciona con Java 8</div>";
    
    // Probar compilación simple
    echo "<h4>🧪 Probando compilación de JRXML...</h4>";
    
    $jrxml = __DIR__ . '/Tickets_Cerrados_por.jrxml';
    if (file_exists($jrxml)) {
        $compileCmd = "\"$java8Path\" -jar \"$jasperJar\" cp \"$jrxml\" . 2>&1";
        exec($compileCmd, $compileOutput, $compileCode);
        
        echo "<pre>Comando: " . htmlspecialchars($compileCmd) . "\n\n";
        echo "Salida: " . htmlspecialchars(implode("\n", $compileOutput)) . "</pre>";
        
        if ($compileCode === 0) {
            echo "<div class='alert alert-success'>✅ Compilación exitosa</div>";
            // Buscar archivo .jasper generado
            $jasperFile = str_replace('.jrxml', '.jasper', $jrxml);
            if (file_exists($jasperFile)) {
                echo "<p>✅ Archivo generado: " . basename($jasperFile) . "</p>";
            }
        }
    }
} else {
    echo "<div class='alert alert-danger'>❌ JasperStarter NO funciona</div>";
    
    // Diagnosticar
    echo "<h4>🔍 Diagnóstico:</h4>";
    
    // Probar Java solo
    exec("\"$java8Path\" -version 2>&1", $javaOutput, $javaCode);
    echo "<p>Java solo: " . ($javaCode === 0 ? "✅ OK" : "❌ Falla") . "</p>";
    
    // Verificar JAR
    echo "<p>JAR existe: " . (file_exists($jasperJar) ? "✅ Sí" : "❌ No") . "</p>";
    echo "<p>Tamaño JAR: " . filesize($jasperJar) . " bytes</p>";
    
    // Probar sin comillas
    $simpleCmd = "$java8Path -jar \"$jasperJar\" --help 2>&1";
    exec($simpleCmd, $simpleOutput, $simpleCode);
    echo "<p>Comando simple: código $simpleCode</p>";
}