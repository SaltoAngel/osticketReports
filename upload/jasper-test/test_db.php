<?php
// test_db.php - Probar conexión a la base de datos osTicket

$configDB = [
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'osticket_db',
    'username' => 'osticket_user',
    'password' => '123456'
];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Prueba de Conexión - osTicket</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body { padding: 20px; }
        .card { margin-bottom: 20px; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='row'>
            <div class='col-12'>
                <div class='card'>
                    <div class='card-header'>
                        <h4>🔍 Prueba de Conexión a osTicket MySQL</h4>
                    </div>
                    <div class='card-body'>
";

try {
    // 1. Probar conexión básica
    echo "<h5>1. Probando conexión MySQL...</h5>";
    $pdo = new PDO(
        "mysql:host={$configDB['host']};port={$configDB['port']};charset=utf8mb4",
        $configDB['username'],
        $configDB['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<p class='success'>✅ Conexión a MySQL exitosa</p>";
    
    // 2. Verificar base de datos osTicket
    echo "<h5>2. Verificando base de datos 'osticket_db'...</h5>";
    $stmt = $pdo->query("SHOW DATABASES LIKE 'osticket_db'");
    $dbExists = $stmt->fetch();
    
    if ($dbExists) {
        echo "<p class='success'>✅ Base de datos 'osticket_db' encontrada</p>";
        
        // 3. Verificar tablas
        echo "<h5>3. Tablas en osticket_db:</h5>";
        $pdo->exec("USE osticket_db");
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
        
        // 4. Probar consulta de tickets
        echo "<h5>4. Probando consulta de tickets...</h5>";
        $tickets = $pdo->query("
            SELECT COUNT(*) as total, 
                   MIN(created) as mas_antiguo,
                   MAX(created) as mas_reciente
            FROM ost_ticket
        ")->fetch();
        
        echo "<p>Total tickets: <strong>{$tickets['total']}</strong></p>";
        echo "<p>Ticket más antiguo: {$tickets['mas_antiguo']}</p>";
        echo "<p>Ticket más reciente: {$tickets['mas_reciente']}</p>";
        
        // 5. Ejemplo de datos reales
        echo "<h5>5. Últimos 5 tickets:</h5>";
        $recentTickets = $pdo->query("
            SELECT ticket_id, status_id, created 
            FROM ost_ticket 
            ORDER BY created DESC 
            LIMIT 5
        ")->fetchAll();
        
        echo "<table class='table table-sm table-striped'>";
        echo "<thead><tr><th>ID</th><th>Estado</th><th>Fecha</th></tr></thead>";
        echo "<tbody>";
        foreach ($recentTickets as $ticket) {
            echo "<tr>";
            echo "<td>{$ticket['ticket_id']}</td>";
            echo "<td>{$ticket['status_id']}</td>";
            echo "<td>{$ticket['created']}</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        
    } else {
        echo "<p class='error'>❌ Base de datos 'osticket_db' NO encontrada</p>";
        echo "<p>Bases de datos disponibles:</p>";
        $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        echo "<ul>";
        foreach ($databases as $db) {
            echo "<li>$db</li>";
        }
        echo "</ul>";
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error de conexión: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "
                    </div>
                    <div class='card-footer'>
                        <a href='index.php' class='btn btn-primary'>Volver al Generador</a>
                        <a href='generate.php?fecha_desde=" . date('Y-m-01') . "&fecha_hasta=" . date('Y-m-d') . "&formato=pdf' 
                           class='btn btn-success' target='_blank'>Probar Generación</a>
                    </div>
                </div>
                
                <div class='card'>
                    <div class='card-header'>
                        <h5>📋 Configuración Actual</h5>
                    </div>
                    <div class='card-body'>
                        <pre>" . htmlspecialchars(print_r($configDB, true)) . "</pre>
                        <p><strong>PHP Version:</strong> " . phpversion() . "</p>
                        <p><strong>PDO MySQL:</strong> " . (extension_loaded('pdo_mysql') ? '✅ Disponible' : '❌ No disponible') . "</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
";