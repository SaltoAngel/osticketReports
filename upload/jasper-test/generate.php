<?php
// report_generator.php - GENERADOR UNIVERSAL DE REPORTES
// Detecta automáticamente todos los reportes JRXML disponibles

// ========== CONFIGURACIÓN ==========
$java8Path = 'C:\Program Files\Eclipse Adoptium\jdk-8.0.402.6\bin\java.exe';
$jasperJar = __DIR__ . '/jasperstarter/bin/jasperstarter.jar';
$mysqlConnector = __DIR__ . '/mysql-connector-java.jar';

// Configuración BD
$configDB = [
    'host' => 'localhost',
    'port' => '3306',
    'database' => 'osticket_db',
    'username' => 'osticket_user',
    'password' => '123456'
];

// Crear carpeta storage si no existe
$storage = __DIR__ . '/storage';
if (!is_dir($storage)) {
    mkdir($storage, 0777, true);
}

// ========== FUNCIONES ==========
function buscarReportes($directorio = __DIR__) {
    $reportes = [];
    $archivos = glob($directorio . '/*.jrxml');
    
    foreach ($archivos as $archivo) {
        $nombre = basename($archivo, '.jrxml');
        $tamano = round(filesize($archivo) / 1024, 2);
        $fecha = date('Y-m-d H:i', filemtime($archivo));
        
        // Extraer nombre amigable (remover guiones y capitalizar)
        $nombreAmigable = ucwords(str_replace(['_', '-'], ' ', $nombre));
        
        $reportes[] = [
            'archivo' => $archivo,
            'nombre' => $nombre,
            'nombre_amigable' => $nombreAmigable,
            'tamano_kb' => $tamano,
            'fecha_mod' => $fecha,
            'ruta_relativa' => basename($archivo)
        ];
    }
    
    // Buscar también en subdirectorios comunes
    $subdirs = ['reports', 'reportes', 'jasper', 'jrxml', 'templates'];
    foreach ($subdirs as $subdir) {
        $path = $directorio . '/' . $subdir;
        if (is_dir($path)) {
            $subReportes = buscarReportes($path);
            $reportes = array_merge($reportes, $subReportes);
        }
    }
    
    return $reportes;
}

function verificarRequisitos() {
    global $java8Path, $jasperJar, $mysqlConnector;
    
    $requisitos = [
        'Java 8' => ['archivo' => $java8Path, 'desc' => 'JDK 8 para ejecutar Jasper'],
        'JasperStarter' => ['archivo' => $jasperJar, 'desc' => 'Motor de generación de reportes'],
        'MySQL Connector' => ['archivo' => $mysqlConnector, 'desc' => 'Driver JDBC para MySQL'],
    ];
    
    $resultados = [];
    foreach ($requisitos as $nombre => $info) {
        $existe = file_exists($info['archivo']);
        $resultados[$nombre] = [
            'existe' => $existe,
            'desc' => $info['desc'],
            'ruta' => $info['archivo']
        ];
    }
    
    return $resultados;
}

function generarReporte($jrxml, $formato = 'pdf', $parametros = []) {
    global $java8Path, $jasperJar, $mysqlConnector, $configDB, $storage;
    
    // Generar nombre único para el archivo
    $nombreBase = basename($jrxml, '.jrxml');
    $timestamp = date('Ymd_His');
    $outputBase = $storage . '/' . $nombreBase . '_' . $timestamp;
    
    // Construir comando
    $cmd = "\"$java8Path\" -jar \"$jasperJar\"";
    $cmd .= " --locale es_ES";
    $cmd .= " pr \"$jrxml\"";
    $cmd .= " -o \"$outputBase\"";
    $cmd .= " -f $formato";
    $cmd .= " -t mysql";
    $cmd .= " -u " . $configDB['username'];
    $cmd .= " -p " . $configDB['password'];
    $cmd .= " -H " . $configDB['host'];
    $cmd .= " -n " . $configDB['database'];
    $cmd .= " --db-port " . $configDB['port'];
    $cmd .= " --jdbc-dir \"" . dirname($mysqlConnector) . "\"";
    
    // Agregar parámetros si existen
    if (!empty($parametros)) {
        foreach ($parametros as $key => $value) {
            $cmd .= " -P $key=\"$value\"";
        }
    }
    
    $cmd .= " 2>&1";
    
    // Ejecutar comando
    exec($cmd, $output, $code);
    
    // Buscar archivo generado
    $archivoGenerado = null;
    $formatoExt = strtolower($formato);
    
    // Primero buscar con la extensión esperada
    $candidatos = [
        $outputBase . '.' . $formatoExt,
        $outputBase . '.' . strtoupper($formatoExt),
        $outputBase // Sin extensión
    ];
    
    foreach ($candidatos as $candidato) {
        if (file_exists($candidato)) {
            $archivoGenerado = $candidato;
            break;
        }
    }
    
    // Si no se encuentra, buscar cualquier archivo que comience con el nombre base
    if (!$archivoGenerado) {
        $patron = $outputBase . '.*';
        $archivos = glob($patron);
        if (!empty($archivos)) {
            $archivoGenerado = $archivos[0];
        }
    }
    
    return [
        'exitoso' => ($code === 0),
        'codigo' => $code,
        'salida' => $output,
        'archivo' => $archivoGenerado,
        'comando' => $cmd,
        'nombre_base' => $nombreBase
    ];
}

// ========== LÓGICA PRINCIPAL ==========
$accion = $_GET['accion'] ?? 'listar';
$reporte = $_GET['reporte'] ?? '';
$formato = strtolower($_GET['formato'] ?? 'pdf');

// Buscar todos los reportes
$reportes = buscarReportes();

// Verificar requisitos
$requisitos = verificarRequisitos();
$todosOk = true;
foreach ($requisitos as $req) {
    if (!$req['existe']) {
        $todosOk = false;
        break;
    }
}

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>📊 Generador Universal de Reportes</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>
    <style>
        .card-reporte {
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }
        .card-reporte:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .requisito-ok { color: #198754; }
        .requisito-error { color: #dc3545; }
        .format-badge { font-size: 0.7em; margin-right: 3px; }
        .log-output {
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 12px;
            padding: 15px;
            border-radius: 5px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .report-icon {
            font-size: 2em;
            opacity: 0.8;
        }
        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
    </style>
</head>
<body class='bg-light'>
<div class='container-fluid py-4'>
    <div class='row'>
        <div class='col-12'>
            <!-- HEADER -->
            <div class='card mb-4 shadow'>
                <div class='card-header bg-primary text-white d-flex justify-content-between align-items-center'>
                    <div>
                        <h1 class='h3 mb-0'><i class='fas fa-file-alt me-2'></i>Generador Universal de Reportes</h1>
                        <p class='mb-0 opacity-75'>Sistema automático de generación de reportes Jasper</p>
                    </div>
                    <div>
                        <span class='badge bg-light text-dark'>
                            <i class='fas fa-database me-1'></i>
                            {$configDB['database']}
                        </span>
                    </div>
                </div>
                <div class='card-body'>
                    <!-- PANEL DE REQUISITOS -->
                    <div class='row mb-4'>
                        <div class='col-md-12'>
                            <h5><i class='fas fa-clipboard-check me-2'></i>Requisitos del Sistema</h5>
                            <div class='row'>";
                            
foreach ($requisitos as $nombre => $info) {
    $icono = $info['existe'] ? 'check-circle' : 'times-circle';
    $color = $info['existe'] ? 'success' : 'danger';
    $texto = $info['existe'] ? 'OK' : 'FALTANTE';
    
    echo "<div class='col-md-4 mb-2'>
            <div class='card border-$color'>
                <div class='card-body py-2'>
                    <div class='d-flex align-items-center'>
                        <i class='fas fa-$icono text-$color me-3 fs-4'></i>
                        <div>
                            <h6 class='mb-0'>$nombre</h6>
                            <small class='text-muted'>{$info['desc']}</small><br>
                            <small><code class='text-truncate d-block' style='max-width: 250px;'>{$info['ruta']}</code></small>
                        </div>
                        <span class='badge bg-$color ms-auto'>$texto</span>
                    </div>
                </div>
            </div>
          </div>";
}

echo "                  </div>
                        </div>
                    </div>
                    
                    <!-- MENSAJE DE ADVERTENCIA SI FALTAN REQUISITOS -->
                    ";
if (!$todosOk) {
    echo "<div class='alert alert-danger'>
            <h5><i class='fas fa-exclamation-triangle me-2'></i>Requisitos incompletos</h5>
            <p>No se pueden generar reportes hasta que todos los requisitos estén instalados.</p>
            <a href='download_missing.php' class='btn btn-warning'>
                <i class='fas fa-download me-1'></i>Descargar componentes faltantes
            </a>
          </div>";
}

// ========== PANTALLA DE LISTADO DE REPORTES ==========
if ($accion == 'listar' || !$todosOk) {
    echo "<div class='row'>
            <div class='col-12'>
                <div class='d-flex justify-content-between align-items-center mb-4'>
                    <h4><i class='fas fa-copy me-2'></i>Reportes Disponibles</h4>
                    <div>
                        <span class='badge bg-info'>" . count($reportes) . " encontrados</span>
                        <button class='btn btn-sm btn-outline-primary' onclick='location.reload()'>
                            <i class='fas fa-sync-alt'></i> Actualizar
                        </button>
                    </div>
                </div>";
    
    if (empty($reportes)) {
        echo "<div class='alert alert-warning'>
                <h5><i class='fas fa-search me-2'></i>No se encontraron reportes</h5>
                <p>Coloca archivos .jrxml en la carpeta del proyecto o en subcarpetas como:</p>
                <ul>
                    <li><code>/reports/</code></li>
                    <li><code>/reportes/</code></li>
                    <li><code>/jasper/</code></li>
                    <li><code>/templates/</code></li>
                </ul>
              </div>";
    } else {
        echo "<div class='row'>";
        
        foreach ($reportes as $rep) {
            $iconos = [
                'pdf' => 'file-pdf',
                'excel' => 'file-excel',
                'word' => 'file-word',
                'html' => 'file-code'
            ];
            
            $iconoAleatorio = $iconos[array_rand($iconos)];
            
            echo "<div class='col-xl-3 col-lg-4 col-md-6 mb-4'>
                    <div class='card card-reporte h-100 border-primary'>
                        <div class='card-header bg-primary bg-opacity-10'>
                            <div class='d-flex justify-content-between align-items-center'>
                                <h6 class='mb-0'>
                                    <i class='fas fa-$iconoAleatorio text-primary me-2'></i>
                                    {$rep['nombre_amigable']}
                                </h6>
                                <span class='badge bg-secondary'>{$rep['tamano_kb']} KB</span>
                            </div>
                        </div>
                        <div class='card-body'>
                            <p class='small text-muted mb-2'>
                                <i class='far fa-file me-1'></i>
                                <code>{$rep['ruta_relativa']}</code>
                            </p>
                            <p class='small text-muted mb-3'>
                                <i class='far fa-clock me-1'></i>
                                Modificado: {$rep['fecha_mod']}
                            </p>
                            
                            <!-- BOTONES DE FORMATO -->
                            <div class='mb-3'>
                                <small class='text-muted d-block mb-1'>Exportar como:</small>
                                <div class='btn-group btn-group-sm d-flex' role='group'>";
            
            $formatos = [
                'pdf' => ['color' => 'danger', 'icon' => 'file-pdf', 'text' => 'PDF'],
                'xlsx' => ['color' => 'success', 'icon' => 'file-excel', 'text' => 'Excel'],
                'docx' => ['color' => 'primary', 'icon' => 'file-word', 'text' => 'Word'],
                'html' => ['color' => 'info', 'icon' => 'code', 'text' => 'HTML'],
                'csv' => ['color' => 'secondary', 'icon' => 'file-csv', 'text' => 'CSV']
            ];
            
            foreach ($formatos as $fmt => $info) {
                $disabled = !$todosOk ? 'disabled' : '';
                echo "<a href='?accion=generar&reporte=" . urlencode($rep['archivo']) . "&formato=$fmt' 
                      class='btn btn-outline-{$info['color']} flex-fill $disabled'>
                        <i class='fas fa-{$info['icon']} me-1'></i>
                        {$info['text']}
                    </a>";
            }
            
            echo "          </div>
                            </div>
                            
                            <!-- BOTÓN PARA VER PARÁMETROS -->
                            <button class='btn btn-sm btn-outline-warning w-100' 
                                    onclick=\"mostrarParametros('{$rep['nombre']}')\"
                                    $disabled>
                                <i class='fas fa-sliders-h me-1'></i> Parámetros
                            </button>
                        </div>
                        <div class='card-footer bg-transparent'>
                            <a href='?accion=generar&reporte=" . urlencode($rep['archivo']) . "&formato=pdf' 
                               class='btn btn-primary w-100 $disabled'>
                                <i class='fas fa-play me-1'></i> Generar Ahora
                            </a>
                        </div>
                    </div>
                  </div>";
        }
        
        echo "</div>"; // Cierra row
    }
    
    echo "</div></div>";
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
    
    echo "<div class='text-center my-4'>
            <div class='spinner-border text-primary' role='status' style='width: 3rem; height: 3rem;'>
                <span class='visually-hidden'>Generando...</span>
            </div>
            <p class='mt-3'>Generando reporte, por favor espera...</p>
          </div>";
    
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
            
            echo "<div class='alert alert-success'>
                    <h5><i class='fas fa-check-circle me-2'></i>¡Reporte Generado Exitosamente!</h5>
                    <p><strong>Archivo:</strong> $archivoNombre ($archivoTamanio KB)</p>
                    
                    <div class='d-flex gap-2 mt-3'>
                        <a href='$archivoUrl' class='btn btn-primary' download>
                            <i class='fas fa-download me-1'></i> Descargar
                        </a>
                        <a href='$archivoUrl' class='btn btn-success' target='_blank'>
                            <i class='fas fa-eye me-1'></i> Ver
                        </a>
                        <a href='?' class='btn btn-secondary'>
                            <i class='fas fa-list me-1'></i> Ver Todos
                        </a>
                    </div>
                  </div>";
            
            // Vista previa para PDF
            if ($formato == 'pdf') {
                echo "<div class='mt-4'>
                        <h6><i class='fas fa-file-pdf me-2'></i>Vista Previa</h6>
                        <iframe src='$archivoUrl' width='100%' height='500' style='border: 1px solid #ddd; border-radius: 5px;'></iframe>
                      </div>";
            }
        } else {
            echo "<div class='alert alert-warning'>
                    <h5><i class='fas fa-exclamation-triangle me-2'></i>Comando exitoso pero archivo no encontrado</h5>
                    <p>Salida del comando:</p>
                    <div class='log-output'>" . htmlspecialchars(implode("\n", $resultado['salida'])) . "</div>
                  </div>";
        }
    } else {
        echo "<div class='alert alert-danger'>
                <h5><i class='fas fa-times-circle me-2'></i>Error al generar reporte</h5>
                <p>Código de error: {$resultado['codigo']}</p>
                
                <h6 class='mt-3'>Detalles del error:</h6>
                <div class='log-output'>" . htmlspecialchars(implode("\n", $resultado['salida'])) . "</div>
                
                <h6 class='mt-3'>Comando ejecutado:</h6>
                <div class='log-output'>" . htmlspecialchars($resultado['comando']) . "</div>
                
                <div class='mt-3'>
                    <a href='?' class='btn btn-secondary'>
                        <i class='fas fa-arrow-left me-1'></i> Volver
                    </a>
                    <a href='test_manual_cmd.php' class='btn btn-warning'>
                        <i class='fas fa-tools me-1'></i> Probar Manualmente
                    </a>
                </div>
              </div>";
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

echo "      </div>
        </div>
    </div>
</div>

<!-- MODAL PARA PARÁMETROS -->
<div class='modal fade' id='modalParametros' tabindex='-1'>
    <div class='modal-dialog'>
        <div class='modal-content'>
            <div class='modal-header'>
                <h5 class='modal-title'><i class='fas fa-sliders-h me-2'></i>Parámetros del Reporte</h5>
                <button type='button' class='btn-close' data-bs-dismiss='modal'></button>
            </div>
            <div class='modal-body'>
                <form id='formParametros'>
                    <div class='mb-3'>
                        <label class='form-label'>Fecha Desde</label>
                        <input type='date' name='fecha_desde' class='form-control' value='" . date('Y-m-01') . "'>
                    </div>
                    <div class='mb-3'>
                        <label class='form-label'>Fecha Hasta</label>
                        <input type='date' name='fecha_hasta' class='form-control' value='" . date('Y-m-d') . "'>
                    </div>
                    <div class='mb-3'>
                        <label class='form-label'>Usuario</label>
                        <input type='text' name='usuario' class='form-control' placeholder='Opcional'>
                    </div>
                    <div class='mb-3'>
                        <label class='form-label'>Departamento</label>
                        <select name='departamento' class='form-select'>
                            <option value=''>Todos</option>
                            <option value='1'>Soporte Técnico</option>
                            <option value='2'>Ventas</option>
                            <option value='3'>Facturación</option>
                        </select>
                    </div>
                    <input type='hidden' id='reporteActual' name='reporte'>
                    <input type='hidden' id='formatoActual' name='formato'>
                </form>
            </div>
            <div class='modal-footer'>
                <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancelar</button>
                <button type='button' class='btn btn-primary' onclick='enviarConParametros()'>
                    <i class='fas fa-play me-1'></i> Generar con Parámetros
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SCRIPTS -->
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