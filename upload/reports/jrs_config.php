<?php
// Configuración de JasperReports Server para ajax_reportes.php
// Ajusta credenciales y mapeos de reportes. Este archivo se carga si existe y sobrescribe los valores por defecto.
return [
    'enabled' => true,
    'base_url' => 'http://localhost:8080/jasperserver', // URL base del servidor JRS (sin /rest_v2 al final)
    'username' => 'jasperadmin', // Usuario con permisos de solo lectura sobre los reportes
    'password' => 'jasperadmin',
    'verify_ssl' => false, // Pon en true si usas HTTPS con certificado válido
    // Mapea el valor recibido en $_POST['tipo'] al URI del report unit en el repositorio de Jaspersoft
    'report_uri_map' => [
        // 'tickets' => '/reports/osTicket/Tickets',
        // 'mi_otro_reporte' => '/reports/osTicket/MiOtroReporte',
    ],
];
