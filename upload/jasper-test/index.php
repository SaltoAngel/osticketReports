<?php
require __DIR__ . '/vendor/autoload.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes osTicket con phpjasper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; padding: 20px; }
        .card { margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .btn-primary { background: #2c3e50; border-color: #2c3e50; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">📊 Generador de Reportes osTicket</h4>
                        <small class="text-light">Usando geekcom/phpjasper (JasperStarter)</small>
                    </div>
                    <div class="card-body">
                        
                        <!-- Formulario para generar reporte -->
                        <form action="generate.php" method="GET" target="_blank" class="mb-4">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Fecha Desde</label>
                                    <input type="date" name="fecha_desde" class="form-control" 
                                           value="<?php echo date('Y-m-01'); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Fecha Hasta</label>
                                    <input type="date" name="fecha_hasta" class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Formato</label>
                                    <select name="formato" class="form-select">
                                        <option value="pdf">PDF</option>
                                        <option value="xlsx">Excel</option>
                                        <option value="html">HTML</option>
                                        <option value="docx">Word</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-file-earmark-pdf"></i> Generar Reporte
                                </button>
                                <a href="test_db.php" class="btn btn-outline-secondary">
                                    🔍 Probar Conexión BD
                                </a>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <!-- Información del sistema -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6>📋 Reportes Disponibles</h6>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item">
                                                <strong>Tickets por Estado</strong>
                                                <br><small>Muestra tickets agrupados por estado</small>
                                            </li>
                                            <li class="list-group-item">
                                                <strong>Tickets por Usuario</strong>
                                                <br><small>Conteo de tickets por agente</small>
                                            </li>
                                            <li class="list-group-item">
                                                <strong>Tiempo de Respuesta</strong>
                                                <br><small>Promedio de tiempo por categoría</small>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h6>⚙️ Configuración Actual</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Base de Datos:</th>
                                                <td>osticket_bd</td>
                                            </tr>
                                            <tr>
                                                <th>Servidor:</th>
                                                <td>localhost:3306</td>
                                            </tr>
                                            <tr>
                                                <th>Usuario:</th>
                                                <td>root</td>
                                            </tr>
                                            <tr>
                                                <th>PHP Version:</th>
                                                <td><?php echo phpversion(); ?></td>
                                            </tr>
                                            <tr>
                                                <th>phpjasper:</th>
                                                <td>
                                                    <?php 
                                                    try {
                                                        if (class_exists('PHPJasper\\PHPJasper')) {
                                                            $test = new PHPJasper\PHPJasper();
                                                            echo '✅ Instalado correctamente';
                                                        } else {
                                                            echo '❌ Clase PHPJasper\\PHPJasper no encontrada';
                                                        }
                                                    } catch (Throwable $e) {
                                                        echo '❌ Error: ' . $e->getMessage();
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ejemplos de código -->
                        <div class="mt-4">
                            <h5>💻 Código de Ejemplo</h5>
                            <pre class="bg-dark text-light p-3 rounded"><code>
// Generar reporte con geekcom/phpjasper 3.4:
$jasper = new PHPJasper\PHPJasper();
$input  = __DIR__ . '/Tickets_Cerrados_por.jrxml';
$output = __DIR__ . '/storage/reporte_' . date('Ymd_His');

$jasper->process(
    $input,
    $output,
    [
        'format' => ['pdf'],
        'locale' => 'es_ES',
        'params' => [
            'fecha_desde' => date('Y-m-01'),
            'fecha_hasta' => date('Y-m-d')
        ],
        'db_connection' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => 'osticket_bd',
            'username' => 'root',
            'password' => ''
        ]
    ]
)->execute();
                            </code></pre>
                        </div>
                        
                    </div>
                    <div class="card-footer text-muted">
                        <small>
                            Usando <strong>geekcom/phpjasper v3.4</strong> | 
                            Conectado a <strong>osTicket MySQL</strong> | 
                            <a href="https://github.com/Geekcom/phpjasper" target="_blank">Documentación</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
</body>
</html>