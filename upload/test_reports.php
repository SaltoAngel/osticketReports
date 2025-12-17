<?php
// Crea este script para encontrar dónde está la validación
$search_files = [
    'C:\xampp642\htdocs\osTicket\upload\include\staff.inc.php',
    'C:\xampp642\htdocs\osTicket\upload\include\class.osticket.php',
    'C:\xampp642\htdocs\osTicket\upload\include\class.http.php',
    'C:\xampp642\htdocs\osTicket\upload\include\class.validator.php',
];

foreach($search_files as $file) {
    if(file_exists($file)) {
        $content = file_get_contents($file);
        if(strpos($content, 'CSRF') !== false || strpos($content, 'csrf') !== false) {
            echo "✅ Encontrado en: $file\n";
            // Mostrar líneas alrededor
            $lines = explode("\n", $content);
            foreach($lines as $i => $line) {
                if(stripos($line, 'CSRF') !== false || stripos($line, 'csrf') !== false) {
                    echo "Línea " . ($i+1) . ": " . htmlspecialchars($line) . "\n";
                }
            }
            echo "---\n";
        }
    }
}
?>