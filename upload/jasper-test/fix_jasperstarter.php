<?php
// fix_jasperstarter.php - Crear estructura correcta
echo "<h3>🛠️ Creando estructura correcta de JasperStarter</h3>";

$jasperDir = __DIR__ . '/jasperstarter';
$binDir = $jasperDir . '/bin';

// Crear directorios
if (!is_dir($jasperDir)) mkdir($jasperDir, 0777, true);
if (!is_dir($binDir)) mkdir($binDir, 0777, true);

// 1. Crear jasperstarter.bat que usa Java 8
$batContent = '@echo off
setlocal enabledelayedexpansion

:: Configurar Java 8 específicamente
set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6
set JAVA_EXE="%JAVA_HOME%\bin\java.exe"

:: Ruta al JAR (mismo directorio que este batch)
set JAR_FILE=%~dp0jasperstarter.jar

:: Verificar que Java existe
if not exist %JAVA_EXE% (
    echo ERROR: Java 8 no encontrado en %JAVA_HOME%
    echo.
    echo Solucion: Instala Java 8 desde: https://adoptium.net/?variant=openjdk8
    exit /b 1
)

:: Verificar que el JAR existe
if not exist "%JAR_FILE%" (
    echo ERROR: jasperstarter.jar no encontrado
    echo Buscando en: %JAR_FILE%
    exit /b 1
)

:: Ejecutar JasperStarter con Java 8
echo Usando Java 8: %JAVA_HOME%
%JAVA_EXE% -jar "%JAR_FILE%" %*
endlocal';

file_put_contents($binDir . '/jasperstarter.bat', $batContent);
echo "<p>✅ Creado: jasperstarter.bat</p>";

// 2. Buscar jasperstarter.jar en el sistema o descargarlo
echo "<p>🔍 Buscando jasperstarter.jar...</p>";

$possibleJarLocations = [
    'C:\Program Files (x86)\JasperStarter\jasperstarter.jar',
    'C:\Program Files\JasperStarter\jasperstarter.jar',
    __DIR__ . '/jasperstarter/jasperstarter.jar',
    __DIR__ . '/vendor/geekcom/phpjasper/bin/jasperstarter.jar',
];

$jarFound = false;
foreach ($possibleJarLocations as $jarPath) {
    if (file_exists($jarPath)) {
        copy($jarPath, $binDir . '/jasperstarter.jar');
        echo "<p style='color:green'>✅ Encontrado y copiado: " . basename($jarPath) . "</p>";
        $jarFound = true;
        break;
    }
}

if (!$jarFound) {
    echo "<p style='color:orange'>⚠️ jasperstarter.jar no encontrado. Intentando descargar...</p>";
    
    $jarUrl = 'https://github.com/cenote/jasperstarter/releases/download/v3.5.0/jasperstarter.jar';
    $jarContent = @file_get_contents($jarUrl);
    
    if ($jarContent !== false) {
        file_put_contents($binDir . '/jasperstarter.jar', $jarContent);
        echo "<p style='color:green'>✅ Descargado: jasperstarter.jar (" . round(strlen($jarContent)/1024, 2) . " KB)</p>";
        $jarFound = true;
    } else {
        echo "<p style='color:red'>❌ No se pudo descargar. Descarga manualmente:</p>";
        echo "<p><a href='$jarUrl' target='_blank'>$jarUrl</a></p>";
        echo "<p>Guárdalo en: $binDir/jasperstarter.jar</p>";
    }
}

// 3. Crear también un .exe wrapper por si acaso
$exeWrapper = $binDir . '/jasperstarter.exe';
if (!file_exists($exeWrapper) && $jarFound) {
    $exeContent = '<?php
// PHP wrapper para jasperstarter.exe
$javaPath = "C:\\Program Files\\Eclipse Adoptium\\jdk-8.0.402.6\\bin\\java.exe";
$jarPath = __DIR__ . "\\jasperstarter.jar";

$args = "";
for ($i = 1; $i < $argc; $i++) {
    $args .= " \"" . $argv[$i] . "\"";
}

$command = "\"" . $javaPath . "\" -jar \"" . $jarPath . "\"" . $args;
system($command, $returnCode);
exit($returnCode);
?>';

    file_put_contents($exeWrapper, $exeContent);
    echo "<p>✅ Creado: jasperstarter.exe (PHP wrapper)</p>";
}

// 4. Probar la instalación
echo "<hr><h4>🧪 Probando instalación...</h4>";

if (file_exists($binDir . '/jasperstarter.jar') && file_exists($binDir . '/jasperstarter.bat')) {
    echo "<p style='color:green'>✅ Estructura completa creada en: $jasperDir</p>";
    
    // Listar contenido
    echo "<pre>";
    echo "Contenido de jasperstarter/bin:\n";
    $files = scandir($binDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $size = filesize($binDir . '/' . $file);
            echo "- $file (" . round($size/1024, 2) . " KB)\n";
        }
    }
    echo "</pre>";
    
    // Probar Java
    $javaPath = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
    exec("\"$javaPath\" -version 2>&1", $javaOutput, $javaCode);
    
    echo "<p><strong>Java 8 verificado:</strong></p>";
    echo "<pre>" . htmlspecialchars(implode("\n", array_slice($javaOutput, 0, 2))) . "</pre>";
    
    echo "<hr><h4>🎯 Siguientes pasos:</h4>";
    echo "<ol>";
    echo "<li><a href='generate_final.php'>Probar generación de reporte FINAL</a></li>";
    echo "<li><a href='test_structure.php'>Verificar estructura completa</a></li>";
    echo "</ol>";
} else {
    echo "<p style='color:red'>❌ Estructura incompleta</p>";
}