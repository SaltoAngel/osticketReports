<?php
// generate_universal.php - Versión UNIVERSAL para cualquier reporte
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';

// Configuración BD
$configDB = [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'osticket_db',
    'username' => 'osticket_user',
    'password' => '123456'
];

echo "<!DOCTYPE html>
<html>
<head>
    <title>🌐 Generador Universal de Reportes</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='p-4'>
<div class='container'>
    <h3 class='mb-4'>🌐 Generador Universal para CUALQUIER Reporte JRXML</h3>
    
    <div class='row'>
        <div class='col-md-6'>
            <div class='card'>
                <div class='card-header'>
                    <h5 class='mb-0'>📁 Reportes Disponibles</h5>
                </div>
                <div class='card-body'>";

// Listar todos los archivos JRXML
$jrxmlFiles = glob(__DIR__ . '/*.jrxml');
if (empty($jrxmlFiles)) {
    echo "<p class='text-danger'>❌ No hay archivos .jrxml en el directorio</p>";
} else {
    echo "<div class='list-group'>";
    foreach ($jrxmlFiles as $file) {
        $name = basename($file);
        $size = round(filesize($file) / 1024, 2);
        $selected = ($name == ($_GET['jrxml'] ?? 'Tickets_Cerrados_por.jrxml')) ? 'active' : '';
        
        echo "<a href='?jrxml=" . urlencode($name) . "' 
              class='list-group-item list-group-item-action $selected'>
                <div class='d-flex w-100 justify-content-between'>
                    <h6 class='mb-1'>📄 $name</h6>
                    <small>$size KB</small>
                </div>
                <small>" . date('Y-m-d H:i', filemtime($file)) . "</small>
              </a>";
    }
    echo "</div>";
}

echo "          </div>
            </div>
        </div>
        
        <div class='col-md-6'>
            <div class='card'>
                <div class='card-header'>
                    <h5 class='mb-0'>⚙️ Configuración</h5>
                </div>
                <div class='card-body'>
                    <form method='GET' action=''>
                        <input type='hidden' name='jrxml' value='" . htmlspecialchars($_GET['jrxml'] ?? 'Tickets_Cerrados_por.jrxml') . "'>
                        
                        <div class='mb-3'>
                            <h6>🗓️ Opciones de fecha (opcional):</h6>
                            <div class='row'>
                                <div class='col-md-6'>
                                    <label class='form-label'>Fecha inicio:</label>
                                    <input type='date' name='fecha_desde' class='form-control' 
                                           value='" . ($_GET['fecha_desde'] ?? date('Y-m-01')) . "'>
                                </div>
                                <div class='col-md-6'>
                                    <label class='form-label'>Fecha fin:</label>
                                    <input type='date' name='fecha_hasta' class='form-control' 
                                           value='" . ($_GET['fecha_hasta'] ?? date('Y-m-d')) . "'>
                                </div>
                            </div>
                            <small class='form-text text-muted'>
                                ⚠️ Solo si el reporte tiene parámetros con esos nombres
                            </small>
                        </div>
                        
                        <div class='mb-3'>
                            <h6>📊 Formato de salida:</h6>
                            <select name='formato' class='form-select'>
                                <option value='pdf'>PDF</option>
                                <option value='xlsx'>Excel (XLSX)</option>
                                <option value='xls'>Excel (XLS)</option>
                                <option value='html'>HTML</option>
                                <option value='csv'>CSV</option>
                            </select>
                        </div>
                        
                        <div class='mb-3'>
                            <h6>🎯 Modo de ejecución:</h6>
                            <div class='form-check'>
                                <input class='form-check-input' type='radio' name='modo' id='modo1' value='auto' checked>
                                <label class='form-check-label' for='modo1'>
                                    Auto-detectar (recomendado)
                                </label>
                            </div>
                            <div class='form-check'>
                                <input class='form-check-input' type='radio' name='modo' id='modo2' value='con_fecha'>
                                <label class='form-check-label' for='modo2'>
                                    Forzar con parámetros de fecha
                                </label>
                            </div>
                            <div class='form-check'>
                                <input class='form-check-input' type='radio' name='modo' id='modo3' value='sin_fecha'>
                                <label class='form-check-label' for='modo3'>
                                    Forzar SIN parámetros de fecha
                                </label>
                            </div>
                        </div>
                        
                        <button type='submit' name='preview' value='1' class='btn btn-info'>
                            🔍 Pre-visualizar configuración
                        </button>
                        <button type='submit' name='generate' value='1' class='btn btn-success'>
                            🚀 Generar Reporte
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>";

// Si se solicita generar o previsualizar
if (isset($_GET['preview']) || isset($_GET['generate'])) {
    $jrxml = __DIR__ . '/' . ($_GET['jrxml'] ?? 'Tickets_Cerrados_por.jrxml');
    $fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
    $fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
    $formato = $_GET['formato'] ?? 'pdf';
    $modo = $_GET['modo'] ?? 'auto';
    
    echo "<div class='card mt-4'>
            <div class='card-header'>
                <h5 class='mb-0'>🔍 Configuración para: " . basename($jrxml) . "</h5>
            </div>
            <div class='card-body'>";
    
    if (isset($_GET['preview'])) {
        echo "<div class='alert alert-info'>
                <h6>📋 Pre-visualización de configuración:</h6>
                <ul>
                    <li><strong>Reporte:</strong> " . basename($jrxml) . "</li>
                    <li><strong>Fecha desde:</strong> $fecha_desde</li>
                    <li><strong>Fecha hasta:</strong> $fecha_hasta</li>
                    <li><strong>Formato:</strong> $formato</li>
                    <li><strong>Modo:</strong> $modo</li>
                </ul>
                <p>Para generar, haz clic en <strong>🚀 Generar Reporte</strong></p>
              </div>";
    }
    
    if (isset($_GET['generate'])) {
        echo "<div class='alert alert-warning'>
                <h6>⏳ Generando reporte...</h6>
              </div>";
        
        // Determinar qué parámetros usar según el modo
        $useFechaParams = false;
        
        if ($modo === 'con_fecha') {
            $useFechaParams = true;
            echo "<p>🔧 <strong>Modo:</strong> Forzado CON parámetros de fecha</p>";
        } elseif ($modo === 'sin_fecha') {
            $useFechaParams = false;
            echo "<p>🔧 <strong>Modo:</strong> Forzado SIN parámetros de fecha</p>";
        } else {
            // Auto-detectar
            if (file_exists($jrxml)) {
                $xmlContent = file_get_contents($jrxml);
                $hasFechaParam = (strpos($xmlContent, 'fecha_desde') !== false || 
                                 strpos($xmlContent, 'fecha_hasta') !== false ||
                                 strpos($xmlContent, 'FechaInicio') !== false ||
                                 strpos($xmlContent, 'FechaFin') !== false);
                
                $useFechaParams = $hasFechaParam;
                echo "<p>🔍 <strong>Auto-detección:</strong> " . 
                     ($hasFechaParam ? 'Reporte TIENE parámetros de fecha' : 'Reporte NO tiene parámetros de fecha') . "</p>";
            }
        }
        
        // Ejecutar comando
        $outputBase = __DIR__ . '/storage/reporte_' . date('Ymd_His');
        
        $cmd = "\"$java8Path\" -jar \"$jasperJar\" pr \"$jrxml\"";
        $cmd .= " -o \"$outputBase\"";
        $cmd .= " -f $formato";
        
        if ($useFechaParams) {
            $cmd .= " -P fecha_desde=\"$fecha_desde\"";
            $cmd .= " -P fecha_hasta=\"$fecha_hasta\"";
        }
        
        $cmd .= " -t mysql";
        $cmd .= " -u " . $configDB['username'];
        $cmd .= " -p " . $configDB['password'];
        $cmd .= " -H " . $configDB['host'];
        $cmd .= " -n " . $configDB['database'];
        $cmd .= " --db-port " . $configDB['port'];
        $cmd .= " 2>&1";
        
        echo "<pre class='bg-light p-3 small'>" . htmlspecialchars($cmd) . "</pre>";
        
        exec($cmd, $output, $code);
        
        if ($code === 0) {
            $outputFile = $outputBase . '.' . $formato;
            if (file_exists($outputFile)) {
                $fileSize = round(filesize($outputFile) / 1024, 2);
                echo "<div class='alert alert-success'>
                        <h5>✅ ¡ÉXITO!</h5>
                        <p>Archivo generado: " . basename($outputFile) . " ($fileSize KB)</p>
                        <a href='storage/" . basename($outputFile) . "' class='btn btn-primary' download>
                            📥 Descargar
                        </a>
                      </div>";
            }
        } else {
            echo "<div class='alert alert-danger'>
                    <h5>❌ Error</h5>
                    <pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>
                  </div>";
        }
    }
    
    echo "  </div>
          </div>";
}

echo "</div></body></html>";