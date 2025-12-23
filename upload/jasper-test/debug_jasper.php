<?php
// debug_jasper.php - Diagnóstico completo
echo "<h3>🔍 Diagnóstico completo del sistema</h3>";

// 1. Verificar Java
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
echo "<h4>1. Java 8:</h4>";
if (file_exists($java8Path)) {
    exec("\"$java8Path\" -version 2>&1", $javaOutput, $javaCode);
    echo "<pre>" . htmlspecialchars(implode("\n", $javaOutput)) . "</pre>";
    
    // Verificar si es realmente Java 8
    $isJava8 = false;
    foreach ($javaOutput as $line) {
        if (strpos($line, '1.8') !== false || strpos($line, '8') !== false) {
            $isJava8 = true;
            break;
        }
    }
    echo $isJava8 ? "<p style='color:green'>✅ ES JAVA 8</p>" : "<p style='color:red'>❌ NO ES JAVA 8</p>";
} else {
    echo "<p style='color:red'>❌ Ruta no existe: $java8Path</p>";
}

// 2. Verificar JasperStarter
$jasperStarterExe = __DIR__ . '/jasperstarter/bin/jasperstarter.exe';
echo "<h4>2. JasperStarter:</h4>";
if (file_exists($jasperStarterExe)) {
    echo "<p>✅ Existe: " . basename($jasperStarterExe) . "</p>";
    echo "<p>Tamaño: " . round(filesize($jasperStarterExe) / 1024, 2) . " KB</p>";
    
    // Verificar versión
    exec("\"$jasperStarterExe\" --version 2>&1", $jsOutput, $jsCode);
    echo "<pre>Salida: " . htmlspecialchars(implode("\n", $jsOutput)) . "</pre>";
} else {
    echo "<p style='color:red'>❌ No existe</p>";
}

// 3. Verificar PATH y JAVA_HOME
echo "<h4>3. Variables de entorno:</h4>";
echo "<pre>JAVA_HOME: " . (getenv('JAVA_HOME') ?: 'No configurado') . "\n";
echo "PATH actual:\n" . getenv('PATH') . "</pre>";

// 4. Probar comando completo
echo "<h4>4. Probar ejecución completa:</h4>";
$testCmd = "\"$java8Path\" -jar \"" . __DIR__ . "/jasperstarter/bin/jasperstarter.jar\" --help 2>&1";
exec($testCmd, $fullOutput, $fullCode);
echo "<pre>Comando: " . htmlspecialchars($testCmd) . "\n\n";
echo "Salida: " . htmlspecialchars(implode("\n", $fullOutput)) . "</pre>";

// 5. Buscar otros Java en PATH
echo "<h4>5. Otros Java en el sistema:</h4>";
exec('where java 2>&1', $whereJava, $whereCode);
echo "<pre>" . htmlspecialchars(implode("\n", $whereJava)) . "</pre>";

// 6. Verificar PHPJasper
echo "<h4>6. PHPJasper instalado:</h4>";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    
    // Listar métodos disponibles
    echo "<p>✅ PHPJasper cargado</p>";
    
    // Verificar métodos
    $methods = get_class_methods('PHPJasper\PHPJasper');
    echo "<p>Métodos disponibles: " . implode(', ', $methods) . "</p>";
    
    if (in_array('setJasperStarterPath', $methods)) {
        echo "<p style='color:green'>✅ Método setJasperStarterPath() DISPONIBLE</p>";
    } else {
        echo "<p style='color:red'>❌ Método setJasperStarterPath() NO disponible</p>";
        echo "<p>Tu versión de PHPJasper es antigua. Instala la última:</p>";
        echo "<code>composer require geekcom/phpjasper:^3.0</code>";
    }
} else {
    echo "<p style='color:red'>❌ Composer/vendor no encontrado</p>";
}