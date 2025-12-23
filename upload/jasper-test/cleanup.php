<?php
// cleanup.php - Elimina archivos antiguos automáticamente
$files = glob('*.{pdf,xlsx,html,docx}', GLOB_BRACE);
$now = time();
$maxAge = 3600; // 1 hora en segundos

foreach ($files as $file) {
    if (is_file($file)) {
        $fileAge = $now - filemtime($file);
        if ($fileAge > $maxAge) {
            @unlink($file);
        }
    }
}
// No mostramos salida, es un script silencioso