<?php
// report_generator.php - GENERADOR UNIVERSAL DE REPORTES
// Detecta automáticamente todos los reportes JRXML disponibles

// Incluir funciones compartidas
require_once __DIR__ . '/jasper_functions.php';

// ========== LÓGICA PRINCIPAL ==========
$accion = $_GET['accion'] ?? 'listar';
$reporte = $_GET['reporte'] ?? '';
$formato = strtolower($_GET['formato'] ?? 'pdf');
$skin   = $_GET['skin'] ?? '';
$isOst  = ($skin === 'ost');

// Buscar todos los reportes desde carpeta actual
$reportes = buscarReportes(__DIR__);

// Verificar requisitos
$requisitos = verificarRequisitos();
$todosOk = true;
foreach ($requisitos as $req) {
    if (!$req['existe']) {
        $todosOk = false;
        break;
    }
}

if (!$isOst) {
echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>📊 Generador Universal de Reportes</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
    <style>
        .card-reporte { transition: transform 0.3s, box-shadow 0.3s; cursor: pointer; }
        .card-reporte:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .log-output { background: #1e1e1e; color: #d4d4d4; font-family: 'Consolas','Monaco', monospace; font-size: 12px; padding: 15px; border-radius: 5px; max-height: 300px; overflow-y: auto; white-space: pre-wrap; }
    </style>
</head>
<body class='bg-light'>
<div class='container-fluid py-4'>
    <div class='row'>
        <div class='col-12'>
            <div class='card mb-4 shadow'>
                <div class='card-header bg-primary text-white d-flex justify-content-between align-items-center'>
                    <div>
                        <h1 class='h3 mb-0'><i class='fas fa-file-alt me-2'></i>Generador Universal de Reportes</h1>
                        <p class='mb-0 opacity-75'>Sistema automático de generación de reportes Jasper</p>
                    </div>
                    <div>
                        <span class='badge bg-light text-dark'><i class='fas fa-database me-1'></i>{$configDB['database']}</span>
                    </div>
                </div>
                <div class='card-body'>
                    <div class='row mb-4'>
                        <div class='col-md-12'>
                            <h5><i class='fas fa-clipboard-check me-2'></i>Requisitos del Sistema</h5>
                            <div class='row'>";
} else {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Generador de Reportes</title>
    <link rel='stylesheet' href='../css/osticket.css'>
    <link rel='stylesheet' href='../css/font-awesome.min.css'>
    <style>.well{border:1px solid #ddd;padding:10px;border-radius:3px;margin-bottom:10px}.muted{color:#777}.btn{cursor:pointer}</style>
    </head><body>
    <div class='container-fluid'>
    <h3><i class='icon-file'></i> Generador de Reportes</h3>
    <div class='well'>
        <h5><i class='icon-clipboard'></i> Requisitos del Sistema</h5>
        <ul class='unstyled'>";
    foreach ($requisitos as $nombre => $info) {
        $estado = $info['existe'] ? 'OK' : 'FALTANTE';
        $class = $info['existe'] ? 'label-success' : 'label-important';
        echo "<li><span class='label {$class}' style='display:inline-block;min-width:70px;'>{$estado}</span> ";
        echo htmlspecialchars($nombre) . ": <code>" . htmlspecialchars($info['ruta']) . "</code></li>";
    }
    echo "</ul></div>";
    if (!$todosOk) {
        echo "<div class='well' style='background:#fff3cd;border-color:#ffeeba;color:#856404;'>".
             "<strong>Requisitos incompletos:</strong> No se pueden generar reportes hasta que estén instalados.</div>";
    }
}
                            
if (!$isOst) {
    foreach ($requisitos as $nombre => $info) {
        $icono = $info['existe'] ? 'check-circle' : 'times-circle';
        $color = $info['existe'] ? 'success' : 'danger';
        $texto = $info['existe'] ? 'OK' : 'FALTANTE';
        echo "<div class='col-md-4 mb-2'><div class='card border-$color'><div class='card-body py-2'><div class='d-flex align-items-center'>
        <i class='fas fa-$icono text-$color me-3 fs-4'></i>
        <div><h6 class='mb-0'>$nombre</h6><small class='text-muted'>{$info['desc']}</small><br>
        <small><code class='text-truncate d-block' style='max-width: 250px;'>{$info['ruta']}</code></small></div>
        <span class='badge bg-$color ms-auto'>$texto</span></div></div></div></div>";
    }
}

echo "                  </div>
                        </div>
                    </div>
                    
                    <!-- MENSAJE DE ADVERTENCIA SI FALTAN REQUISITOS -->
                    ";
if (!$isOst) {
    if (!$todosOk) {
        echo "<div class='alert alert-danger'><h5><i class='fas fa-exclamation-triangle me-2'></i>Requisitos incompletos</h5>
        <p>No se pueden generar reportes hasta que todos los requisitos estén instalados.</p>
        <a href='download_missing.php' class='btn btn-warning'><i class='fas fa-download me-1'></i>Descargar componentes faltantes</a></div>";
    }
}

// ========== PANTALLA DE LISTADO DE REPORTES ==========
if ($accion == 'listar' || !$todosOk) {
    if (!$isOst) {
        echo "<div class='row'><div class='col-12'><div class='d-flex justify-content-between align-items-center mb-4'>
        <h4><i class='fas fa-copy me-2'></i>Reportes Disponibles</h4><div>
        <span class='badge bg-info'>" . count($reportes) . " encontrados</span>
        <button class='btn btn-sm btn-outline-primary' onclick='location.reload()'><i class='fas fa-sync-alt'></i> Actualizar</button>
        </div></div>";
        if (empty($reportes)) {
            echo "<div class='alert alert-warning'><h5><i class='fas fa-search me-2'></i>No se encontraron reportes</h5>
            <p>Coloca archivos .jrxml en subcarpetas como <code>/reports/</code>, <code>/jasper/</code>, <code>/templates/</code>.</p></div>";
        } else {
            echo "<div class='row'>";
            foreach ($reportes as $rep) {
                $iconos = ['pdf'=>'file-pdf','xlsx'=>'file-excel','docx'=>'file-word','html'=>'file-code','csv'=>'file-csv'];
                echo "<div class='col-xl-3 col-lg-4 col-md-6 mb-4'><div class='card card-reporte h-100 border-primary'>";
                echo "<div class='card-header bg-primary bg-opacity-10'><div class='d-flex justify-content-between align-items-center'><h6 class='mb-0'>".
                     htmlspecialchars($rep['nombre_amigable'])."</h6><span class='badge bg-secondary'>{$rep['tamano_kb']} KB</span></div></div>";
                echo "<div class='card-body'><p class='small text-muted mb-2'><code>".htmlspecialchars($rep['ruta_relativa'])."</code></p>";
                echo "<div class='btn-group btn-group-sm d-flex' role='group'>";
                foreach (['pdf','xlsx','docx','html','csv'] as $fmt) {
                    $disabled = !$todosOk ? 'disabled' : '';
                    echo "<a href='?accion=generar&reporte=".urlencode($rep['archivo'])."&formato=$fmt&skin=' class='btn btn-outline-primary flex-fill $disabled'>".strtoupper($fmt)."</a>";
                }
                echo "</div></div><div class='card-footer bg-transparent'><a href='?accion=generar&reporte=".urlencode($rep['archivo'])."&formato=pdf' class='btn btn-primary w-100'>Generar Ahora</a></div></div></div>";
            }
            echo "</div>";
        }
        echo "</div></div>";
    } else {
        echo "<div class='well'><h5><i class='icon-copy'></i> Reportes Disponibles <span class='label'>".count($reportes)."</span></h5>";
        if (empty($reportes)) {
            echo "<div class='muted'>No se encontraron archivos .jrxml</div>";
        } else {
            foreach ($reportes as $rep) {
                echo "<div class='well' style='padding:8px;margin-bottom:8px;'>".
                     "<strong><i class='icon-file'></i> ".htmlspecialchars($rep['nombre_amigable'])."</strong> ".
                     "<span class='muted'>(".$rep['tamano_kb']." KB, ".htmlspecialchars($rep['fecha_mod']).")</span><br>";
                foreach (['pdf','xlsx','docx','html','csv'] as $fmt) {
                    $url = "?accion=generar&reporte=".urlencode($rep['archivo'])."&formato=$fmt&skin=ost";
                    echo "<a class='btn btn-mini' href='".$url."' style='margin-right:6px;'>".strtoupper($fmt)."</a>";
                }
                echo "</div>";
            }
        }
        echo "</div>";
    }
}

// ========== PANTALLA DE GENERACIÓN ==========
elseif ($accion == 'generar' && $todosOk && $reporte) {
    echo "<div class='row'>
            <div class='col-md-8'>
                <div class='card mb-4'>
                    <div class='card-header bg-warning text-dark'>
                        <h5 class='mb-0'>
                            <i class='fas fa-cogs me-2'></i>
                            Generando Reporte
                        </h5>
                    </div>
                    <div class='card-body'>
                        <div class='alert alert-info'>
                            <h6><i class='fas fa-info-circle me-2'></i>Información del Reporte</h6>
                            <p><strong>Archivo:</strong> <code>" . basename($reporte) . "</code></p>
                            <p><strong>Formato:</strong> <span class='badge bg-primary'>" . strtoupper($formato) . "</span></p>
                            <p><strong>Base de datos:</strong> {$configDB['database']}@{$configDB['host']}</p>
                        </div>";
    
    if (!$isOst) {
        echo "<div class='text-center my-4'><div class='spinner-border text-primary' role='status' style='width:3rem;height:3rem;'>
        <span class='visually-hidden'>Generando...</span></div><p class='mt-3'>Generando reporte, por favor espera...</p></div>";
    } else {
        echo "<div class='well'><strong>Generando...</strong> Por favor espera...</div>";
    }
    
    ob_flush();
    flush();
    
    // Construir parámetros desde GET
    $params = [];
    foreach ($_GET as $k=>$v) {
        if (in_array($k, ['accion','reporte','formato'], true)) continue;
        if ($v === '' || $v === null) continue;
        $params[$k] = (string)$v;
    }
    // Duplicar nombres comunes en mayúsculas
    if (isset($params['fecha_desde'])) $params['FECHA_DESDE'] = $params['fecha_desde'];
    if (isset($params['fecha_hasta'])) $params['FECHA_HASTA'] = $params['fecha_hasta'];

    // Generar el reporte
    $resultado = generarReporte($reporte, $formato, $params);
    
    echo "<script>document.querySelector('.spinner-border').style.display = 'none';</script>";
    
    if ($resultado['exitoso']) {
        if ($resultado['archivo']) {
            $archivoNombre = basename($resultado['archivo']);
            $archivoTamanio = round(filesize($resultado['archivo']) / 1024, 2);
            $archivoUrl = 'storage/' . $archivoNombre;
            if (!$isOst) {
                echo "<div class='alert alert-success'><h5><i class='fas fa-check-circle me-2'></i>¡Reporte Generado Exitosamente!</h5>
                <p><strong>Archivo:</strong> $archivoNombre ($archivoTamanio KB)</p>
                <div class='d-flex gap-2 mt-3'>
                <a href='$archivoUrl' class='btn btn-primary' download><i class='fas fa-download me-1'></i> Descargar</a>
                <a href='$archivoUrl' class='btn btn-success' target='_blank'><i class='fas fa-eye me-1'></i> Ver</a>
                <a href='?skin=' class='btn btn-secondary'><i class='fas fa-list me-1'></i> Ver Todos</a></div></div>";
            } else {
                echo "<div class='well' style='background:#e8f5e9;border-color:#c8e6c9;'>
                <strong>Reporte generado:</strong> ".htmlspecialchars($archivoNombre)." (".$archivoTamanio." KB) 
                <div style='margin-top:6px;'>
                <a class='btn btn-mini btn-primary' href='".$archivoUrl."' download>Descargar</a>
                <a class='btn btn-mini btn-success' href='".$archivoUrl."' target='_blank'>Ver</a>
                <a class='btn btn-mini' href='?skin=ost'>Ver Todos</a>
                </div></div>";
            }
            
            // Vista previa para PDF
            if ($formato == 'pdf') {
                if (!$isOst) {
                    echo "<div class='mt-4'><h6><i class='fas fa-file-pdf me-2'></i>Vista Previa</h6>
                    <iframe src='$archivoUrl' width='100%' height='500' style='border:1px solid #ddd;border-radius:5px;'></iframe></div>";
                } else {
                    echo "<div class='well'><strong>Vista previa (PDF)</strong><br>
                    <iframe src='$archivoUrl' width='100%' height='500' style='border:1px solid #ddd;'></iframe></div>";
                }
            }
        } else {
            if (!$isOst) {
                echo "<div class='alert alert-warning'><h5><i class='fas fa-exclamation-triangle me-2'></i>Comando exitoso pero archivo no encontrado</h5>
                <p>Salida del comando:</p><div class='log-output'>" . htmlspecialchars(implode("\n", $resultado['salida'])) . "</div></div>";
            } else {
                echo "<div class='well' style='background:#fff3cd;border-color:#ffeeba;color:#856404;'>
                <strong>Comando exitoso pero archivo no encontrado</strong>
                <pre class='log-output'>" . htmlspecialchars(implode("\n", $resultado['salida'])) . "</pre></div>";
            }
        }
    } else {
        if (!$isOst) {
            echo "<div class='alert alert-danger'><h5><i class='fas fa-times-circle me-2'></i>Error al generar reporte</h5>
            <p>Código de error: {$resultado['codigo']}</p>
            <h6 class='mt-3'>Detalles del error:</h6><div class='log-output'>" . htmlspecialchars(implode("\n", $resultado['salida'])) . "</div>
            <h6 class='mt-3'>Comando ejecutado:</h6><div class='log-output'>" . htmlspecialchars($resultado['comando']) . "</div>
            <div class='mt-3'><a href='?' class='btn btn-secondary'>Volver</a>
            <a href='test_manual_cmd.php' class='btn btn-warning'>Probar Manualmente</a></div></div>";
        } else {
            echo "<div class='well' style='background:#f8d7da;border-color:#f5c2c7;color:#842029;'>
            <strong>Error al generar reporte</strong> (código: {$resultado['codigo']})
            <h6>Salida:</h6><pre class='log-output'>" . htmlspecialchars(implode("\n", $resultado['salida'])) . "</pre>
            <h6>Comando:</h6><pre class='log-output'>" . htmlspecialchars($resultado['comando']) . "</pre>
            <a class='btn btn-mini' href='?skin=ost'>Volver</a>
            <a class='btn btn-mini btn-warning' href='test_manual_cmd.php'>Probar Manualmente</a></div>";
        }
    }
    
    echo "      </div>
                </div>
            </div>
            
            <!-- PANEL LATERAL CON HISTORIAL -->
            <div class='col-md-4'>
                <div class='card'>
                    <div class='card-header'>
                        <h6 class='mb-0'><i class='fas fa-history me-2'></i>Historial Reciente</h6>
                    </div>
                    <div class='card-body'>";
    
    // Mostrar últimos archivos generados
    $archivosStorage = glob($storage . '/*');
    usort($archivosStorage, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $contador = 0;
    foreach (array_slice($archivosStorage, 0, 5) as $archivo) {
        if (is_file($archivo)) {
            $contador++;
            $nombre = basename($archivo);
            $tamano = round(filesize($archivo) / 1024, 2);
            $fecha = date('H:i', filemtime($archivo));
            $ext = pathinfo($archivo, PATHINFO_EXTENSION);
            
            $iconosExt = [
                'pdf' => 'file-pdf text-danger',
                'xlsx' => 'file-excel text-success',
                'docx' => 'file-word text-primary',
                'html' => 'file-code text-info',
                'csv' => 'file-csv text-secondary'
            ];
            
            $icono = isset($iconosExt[$ext]) ? $iconosExt[$ext] : 'file text-muted';
            
            echo "<div class='d-flex align-items-center mb-2 p-2 border rounded'>
                    <i class='fas fa-$icono me-3 fs-5'></i>
                    <div class='flex-grow-1'>
                        <div class='small'>
                            <strong class='d-block'>$nombre</strong>
                            <span class='text-muted'>$tamano KB • $fecha</span>
                        </div>
                    </div>
                    <a href='storage/$nombre' class='btn btn-sm btn-outline-primary' download>
                        <i class='fas fa-download'></i>
                    </a>
                  </div>";
        }
    }
    
    if ($contador == 0) {
        echo "<p class='text-muted text-center'><i>No hay archivos generados aún</i></p>";
    }
    
    echo "      </div>
                </div>
            </div>
          </div>";
}

if (!$isOst) {
echo "      </div></div></div></div>
<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js'></script>
<script>
function mostrarParametros(reporteNombre) {
    document.getElementById('reporteActual').value = reporteNombre;
    document.getElementById('formatoActual').value = 'pdf';
    
    var modal = new bootstrap.Modal(document.getElementById('modalParametros'));
    modal.show();
}

function enviarConParametros() {
    var form = document.getElementById('formParametros');
    var formData = new FormData(form);
    var params = new URLSearchParams();
    
    // Agregar parámetros básicos
    params.append('accion', 'generar');
    params.append('formato', document.getElementById('formatoActual').value);
    
    // Buscar el archivo JRXML correspondiente
    var reportes = " . json_encode($reportes) . ";
    var reporteNombre = document.getElementById('reporteActual').value;
    var archivoJRXML = '';
    
    for (var i = 0; i < reportes.length; i++) {
        if (reportes[i].nombre === reporteNombre) {
            archivoJRXML = reportes[i].archivo;
            break;
        }
    }
    
    if (archivoJRXML) {
        params.append('reporte', encodeURIComponent(archivoJRXML));
        
        // Agregar parámetros del formulario
        for (var pair of formData.entries()) {
            if (pair[1]) {
                params.append(pair[0], pair[1]);
            }
        }
        
        // Redirigir con todos los parámetros
        window.location.href = '?' + params.toString();
    }
}

// Auto-redirect si estamos en modo generación
";
if ($accion == 'generar' && $todosOk && $reporte) {
    echo "// Ya estamos en generación, no hacer nada";
} elseif ($todosOk && !empty($reportes)) {
    echo "// Mostrar mensaje de bienvenida después de 1 segundo
    setTimeout(function() {
        var alertEl = document.createElement('div');
        alertEl.className = 'alert alert-info alert-dismissible fade show position-fixed top-0 end-0 m-3';
        alertEl.style.zIndex = '9999';
        alertEl.innerHTML = `
            <strong><i class='fas fa-info-circle me-1'></i>¡Listo para generar!</strong>
            <p class='mb-0 small'>Se encontraron ${reportes.length} reportes. Haz clic en uno para comenzar.</p>
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        `;
        document.body.appendChild(alertEl);
        setTimeout(() => alertEl.remove(), 5000);
    }, 1000);";
}
echo "</script>
</body>
</html>";
} else {
    echo "</div></body></html>";
}