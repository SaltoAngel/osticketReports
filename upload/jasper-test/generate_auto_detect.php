<?php
// generate_auto_detect.php - Detecta automáticamente el tipo de reporte
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

// Parámetros del formulario (si existen)
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$jrxml_file = $_GET['jrxml_file'] ?? 'Tickets_Cerrados_por.jrxml';

$jrxml = __DIR__ . '/' . $jrxml_file;

echo "<!DOCTYPE html>
<html>
<head>
    <title>🧠 Generador Inteligente - Detecta parámetros</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
</head>
<body class='p-4'>
<div class='container'>
    <div class='card'>
        <div class='card-header bg-info text-white'>
            <h4 class='mb-0'>🧠 Generador Inteligente de Reportes</h4>
        </div>
        <div class='card-body'>";

// ========== DETECCIÓN AUTOMÁTICA ==========
echo "<h5>🔍 Analizando reporte: " . htmlspecialchars(basename($jrxml)) . "</h5>";

if (!file_exists($jrxml)) {
    die("<div class='alert alert-danger'>❌ Archivo no encontrado</div>");
}

// Leer contenido del JRXML
$xmlContent = file_get_contents($jrxml);

// Buscar parámetros en el XML
preg_match_all('/<parameter name="([^"]+)" class="([^"]+)"/', $xmlContent, $matches);

$parameters = [];
if (!empty($matches[1])) {
    for ($i = 0; $i < count($matches[1]); $i++) {
        $parameters[] = [
            'name' => $matches[1][$i],
            'type' => $matches[2][$i]
        ];
    }
}

echo "<div class='alert " . (empty($parameters) ? 'alert-warning' : 'alert-success') . "'>
        <h6>📋 Parámetros detectados:</h6>";

if (empty($parameters)) {
    echo "<p><strong>⚠️ Este reporte NO tiene parámetros definidos</strong></p>";
    echo "<p>Se generará el reporte COMPLETO sin filtros.</p>";
} else {
    echo "<ul>";
    foreach ($parameters as $param) {
        $isDateParam = (stripos($param['name'], 'fecha') !== false || 
                       stripos($param['name'], 'date') !== false);
        $icon = $isDateParam ? '📅' : '⚙️';
        echo "<li>$icon <strong>{$param['name']}</strong> ({$param['type']})</li>";
    }
    echo "</ul>";
}
echo "</div>";

// Determinar si necesita parámetros de fecha
$needsFechaParams = false;
$fechaParamNames = [];

foreach ($parameters as $param) {
    $name = strtolower($param['name']);
    if (strpos($name, 'fecha') !== false || 
        strpos($name, 'date') !== false ||
        strpos($name, 'inicio') !== false ||
        strpos($name, 'fin') !== false ||
        strpos($name, 'start') !== false ||
        strpos($name, 'end') !== false) {
        
        $needsFechaParams = true;
        $fechaParamNames[] = $param['name'];
    }
}

// Mostrar formulario inteligente
echo "<form method='GET' action='' class='mb-4'>
        <input type='hidden' name='jrxml_file' value='" . htmlspecialchars($jrxml_file) . "'>
        
        <div class='row'>";

if ($needsFechaParams && count($fechaParamNames) >= 2) {
    // Si necesita parámetros de fecha, mostrar campos
    echo "<div class='col-md-4'>
            <label class='form-label'>" . htmlspecialchars($fechaParamNames[0]) . ":</label>
            <input type='date' name='fecha_desde' class='form-control' value='$fecha_desde'>
          </div>
          <div class='col-md-4'>
            <label class='form-label'>" . htmlspecialchars($fechaParamNames[1]) . ":</label>
            <input type='date' name='fecha_hasta' class='form-control' value='$fecha_hasta'>
          </div>";
} elseif ($needsFechaParams && count($fechaParamNames) == 1) {
    // Si solo tiene un parámetro de fecha
    echo "<div class='col-md-6'>
            <label class='form-label'>" . htmlspecialchars($fechaParamNames[0]) . ":</label>
            <input type='date' name='fecha_desde' class='form-control' value='$fecha_desde'>
          </div>";
} else {
    // Si no necesita parámetros de fecha
    echo "<div class='col-md-12'>
            <div class='alert alert-info'>
                <p>Este reporte se generará <strong>sin filtros por fecha</strong>.</p>
                <p>Si necesitas filtros, selecciona otro reporte o modifica este JRXML.</p>
            </div>
          </div>";
}

// Listar otros archivos JRXML disponibles
$jrxmlFiles = glob(__DIR__ . '/*.jrxml');
if (count($jrxmlFiles) > 1) {
    echo "<div class='col-md-4'>
            <label class='form-label'>Cambiar reporte:</label>
            <select name='jrxml_file' class='form-select' onchange='this.form.submit()'>
                <option value=''>-- Seleccionar --</option>";
    
    foreach ($jrxmlFiles as $file) {
        $name = basename($file);
        $selected = ($name == $jrxml_file) ? 'selected' : '';
        echo "<option value='$name' $selected>$name</option>";
    }
    
    echo "  </select>
          </div>";
}

echo "  </div>
        
        <div class='mt-3'>
            <button type='submit' class='btn btn-primary'>🔍 Re-analizar</button>
            <button type='submit' name='generate' value='1' class='btn btn-success'>🚀 Generar Reporte</button>
            <a href='index.php' class='btn btn-secondary'>↩️ Volver</a>
        </div>
      </form>";

// ========== GENERACIÓN DEL REPORTE ==========
if (isset($_GET['generate'])) {
    echo "<hr><h5>⚙️ Generando reporte...</h5>";
    
    // Crear carpeta de salida
    $storage = __DIR__ . '/storage';
    if (!is_dir($storage)) {
        mkdir($storage, 0777, true);
    }
    
    $outputBase = $storage . '/reporte_' . date('Ymd_His');
    
    // Construir comando BASE
    $cmd = "\"$java8Path\" -jar \"$jasperJar\" pr \"$jrxml\"";
    $cmd .= " -o \"$outputBase\"";
    $cmd .= " -f pdf";
    $cmd .= " -t mysql";
    $cmd .= " -u " . $configDB['username'];
    $cmd .= " -p " . $configDB['password'];
    $cmd .= " -H " . $configDB['host'];
    $cmd .= " -n " . $configDB['database'];
    $cmd .= " --db-port " . $configDB['port'];
    $cmd .= " --locale es_ES";
    
    // AGREGAR PARÁMETROS SEGÚN DETECCIÓN
    $paramCount = 0;
    
    if ($needsFechaParams && !empty($fechaParamNames)) {
        if (count($fechaParamNames) >= 2) {
            // Usar los nombres EXACTOS del JRXML
            $cmd .= " -P " . $fechaParamNames[0] . "=\"$fecha_desde\"";
            $cmd .= " -P " . $fechaParamNames[1] . "=\"$fecha_hasta\"";
            $paramCount = 2;
            echo "<div class='alert alert-info'>
                    <p>✅ Usando parámetros detectados:</p>
                    <ul>
                        <li><strong>" . htmlspecialchars($fechaParamNames[0]) . "</strong> = $fecha_desde</li>
                        <li><strong>" . htmlspecialchars($fechaParamNames[1]) . "</strong> = $fecha_hasta</li>
                    </ul>
                  </div>";
        } elseif (count($fechaParamNames) == 1) {
            // Si solo tiene un parámetro, usar para ambas fechas
            $cmd .= " -P " . $fechaParamNames[0] . "=\"$fecha_desde a $fecha_hasta\"";
            $paramCount = 1;
            echo "<div class='alert alert-warning'>
                    <p>⚠️ Reporte tiene solo 1 parámetro de fecha:</p>
                    <p><strong>" . htmlspecialchars($fechaParamNames[0]) . "</strong> = $fecha_desde a $fecha_hasta</p>
                  </div>";
        }
    } else {
        echo "<div class='alert alert-warning'>
                <p>⚠️ Generando SIN parámetros de fecha</p>
                <p>Este reporte mostrará todos los registros sin filtro.</p>
              </div>";
    }
    
    // Agregar parámetro logo si existe
    foreach ($parameters as $param) {
        if (strtolower($param['name']) === 'logo') {
            $logoPath = __DIR__ . '/assets/logo.png';
            if (file_exists($logoPath)) {
                $cmd .= " -P logo=\"$logoPath\"";
                $paramCount++;
                echo "<p>✅ Agregado parámetro: logo</p>";
            }
            break;
        }
    }
    
    $cmd .= " 2>&1";
    
    echo "<div class='alert alert-secondary'>
            <h6>📋 Comando ejecutado:</h6>
            <pre class='small'>" . htmlspecialchars($cmd) . "</pre>
            <p>Total parámetros: $paramCount</p>
          </div>";
    
    echo "<div class='alert alert-warning'>
            <h6>⏳ Procesando...</h6>
            <div class='progress'>
                <div class='progress-bar progress-bar-striped progress-bar-animated' style='width: 50%'></div>
            </div>
          </div>";
    
    ob_flush();
    flush();
    
    // EJECUTAR
    exec($cmd, $output, $code);
    
    echo "<script>document.querySelector('.progress-bar').style.width = '100%';</script>";
    
    if ($code === 0) {
        // Buscar archivo generado
        $pdfFile = $outputBase . '.pdf';
        if (!file_exists($pdfFile)) {
            // Buscar cualquier archivo con ese nombre base
            $files = glob($outputBase . '.*');
            if (!empty($files)) {
                $pdfFile = $files[0];
            }
        }
        
        if (file_exists($pdfFile)) {
            $fileSize = round(filesize($pdfFile) / 1024, 2);
            $fileName = basename($pdfFile);
            
            echo "<div class='alert alert-success'>
                    <h5>🎉 ¡REPORTE GENERADO EXITOSAMENTE!</h5>
                    <p><strong>Archivo:</strong> $fileName ($fileSize KB)</p>
                    <p><strong>Parámetros usados:</strong> $paramCount</p>
                    <p><strong>Detección:</strong> " . ($needsFechaParams ? 'Con filtros' : 'Sin filtros') . "</p>
                    
                    <div class='mt-3'>
                        <a href='storage/$fileName' class='btn btn-primary' download>
                            📥 Descargar PDF
                        </a>
                        <a href='storage/$fileName' class='btn btn-success' target='_blank'>
                            👁️ Ver en navegador
                        </a>
                        <a href='" . $_SERVER['PHP_SELF'] . "' class='btn btn-secondary'>
                            🔄 Generar otro
                        </a>
                    </div>
                  </div>";
            
            // Vista previa
            echo "<div class='card mt-3'>
                    <div class='card-header'>
                        <h6 class='mb-0'>📄 Vista previa</h6>
                    </div>
                    <div class='card-body p-0'>
                        <iframe src='storage/$fileName' width='100%' height='500' style='border:none;'></iframe>
                    </div>
                  </div>";
        } else {
            echo "<div class='alert alert-warning'>
                    <h5>⚠️ Comando exitoso pero no se encontró PDF</h5>
                    <p>Archivos generados:</p>
                    <ul>";
            
            $files = glob($outputBase . '.*');
            foreach ($files as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                echo "<li>" . basename($file) . " (" . round(filesize($file)/1024,2) . " KB) - .$ext</li>";
            }
            
            echo "  </ul>
                  </div>";
        }
    } else {
        echo "<div class='alert alert-danger'>
                <h5>❌ Error en generación</h5>
                <p>Código: $code</p>
                
                <h6>Errores detectados:</h6>
                <pre style='max-height: 300px; overflow:auto; background:#f8d7da; padding:10px;'>" 
                . htmlspecialchars(implode("\n", $output)) . "</pre>
                
                <h6 class='mt-3'>🛠️ Soluciones:</h6>
                <ol>
                    <li><strong>Verificar nombres de parámetros:</strong> Asegúrate que coincidan con el JRXML</li>
                    <li><strong>Probar sin parámetros:</strong> 
                        <a href='generate_simple_final.php' class='btn btn-sm btn-outline-primary'>Generar simple</a>
                    </li>
                    <li><strong>Verificar conexión BD:</strong> 
                        <a href='test_db.php' class='btn btn-sm btn-outline-secondary'>Probar MySQL</a>
                    </li>
                </ol>
              </div>";
    }
}

echo "      </div> <!-- card-body -->
        </div> <!-- card -->
    </div> <!-- container -->
</body>
</html>";