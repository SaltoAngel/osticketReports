<?php
require('staff.inc.php');

echo "<h2>Prueba de Reportes osTicket</h2>";

// Probar Java
echo "<h3>1. Probando Java:</h3>";
$output = [];
exec('java -version 2>&1', $output, $return);
echo "<pre>";
if($return === 0) {
    echo "✅ Java funciona\n";
    foreach($output as $line) echo htmlspecialchars($line) . "\n";
} else {
    echo "❌ Java NO funciona\n";
    echo "Código: $return\n";
    echo "Salida: " . implode("\n", $output);
}
echo "</pre>";

// Probar JAR
echo "<h3>2. Probando JAR:</h3>";
$jar_path = INCLUDE_DIR . '../reports/java/osticket-reporter.jar';
if(file_exists($jar_path)) {
    echo "✅ JAR encontrado: " . filesize($jar_path)/1024 . " KB<br>";
    
    // Probar ejecución simple
    exec('java -jar "' . $jar_path . '" 2>&1', $jar_output, $jar_return);
    if(strpos(implode(' ', $jar_output), 'Uso:') !== false) {
        echo "✅ JAR ejecutable correctamente";
    } else {
        echo "⚠️  JAR puede tener problemas: " . implode('<br>', $jar_output);
    }
} else {
    echo "❌ JAR no encontrado en: $jar_path";
}

// Probar permisos
echo "<h3>3. Permisos de directorios:</h3>";
$dirs = [
    '../reports' => 'Directorio principal',
    '../reports/java' => 'JAR',
    '../reports/templates' => 'Plantillas',
    '../reports/output' => 'Salida',
    '../reports/temp' => 'Temporal'
];

foreach($dirs as $dir => $desc) {
    $full_path = INCLUDE_DIR . $dir;
    if(is_dir($full_path) || file_exists($full_path)) {
        $writable = is_writable($full_path);
        echo ($writable ? "✅" : "❌") . " $desc: " . realpath($full_path) . 
             " (" . ($writable ? "escribible" : "NO escribible") . ")<br>";
    } else {
        echo "❌ $desc: NO existe<br>";
    }
}
?>