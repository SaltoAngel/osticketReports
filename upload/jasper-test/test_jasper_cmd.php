<?php
// test_jasper_cmd.php - Probar JasperStarter básico
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';

echo "<h3>🧪 Probando JasperStarter básico</h3>";

// Probar --help (debería funcionar)
$cmd1 = "\"$java8Path\" -jar \"$jasperJar\" --help 2>&1";
exec($cmd1, $output1, $code1);

echo "<h4>1. --help (código: $code1)</h4>";
echo "<pre>" . htmlspecialchars(implode("\n", array_slice($output1, 0, 10))) . "</pre>";

// Probar --version
$cmd2 = "\"$java8Path\" -jar \"$jasperJar\" --version 2>&1";
exec($cmd2, $output2, $code2);

echo "<h4>2. --version (código: $code2)</h4>";
echo "<pre>" . htmlspecialchars(implode("\n", $output2)) . "</pre>";

// Probar comando INCOMPLETO (para ver error)
$cmd3 = "\"$java8Path\" -jar \"$jasperJar\" 2>&1";
exec($cmd3, $output3, $code3);

echo "<h4>3. Sin argumentos (código: $code3)</h4>";
echo "<pre>" . htmlspecialchars(implode("\n", array_slice($output3, 0, 5))) . "</pre>";

if ($code1 === 0) {
    echo "<div class='alert alert-success'>✅ JasperStarter funciona correctamente</div>";
    echo "<p><a href='generate_simple_now.php' class='btn btn-success'>🚀 Probar generación simple</a></p>";
} else {
    echo "<div class='alert alert-danger'>❌ JasperStarter no funciona</div>";
}