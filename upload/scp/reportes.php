<?php
// reportes.php - Versión con DEBUG en CONSOLA del navegador
// =================================================================

require('staff.inc.php');

// Solo verificación de permisos
if(!$thisstaff || !$thisstaff->isAdmin()) {
    header('Location: index.php');
    exit;
}

$nav->setActiveTab('reportes');
include(STAFFINC_DIR.'header.inc.php');

// Configuración para JavaScript
$JAR_PATH = dirname(__FILE__) . '/../reports/java/osticket-reporter.jar';
$JAR_EXISTS = file_exists($JAR_PATH);
$DEBUG_MODE = isset($_GET['debug']);
?>

<div class="container-fluid">
    <h2><i class="icon-bar-chart"></i> <?php echo __('Generar Reportes'); ?>
        <small>
            <button onclick="toggleConsole()" class="btn btn-xs btn-info">
                <i class="icon-terminal"></i> Toggle Console
            </button>
            <button onclick="clearConsole()" class="btn btn-xs btn-warning">
                <i class="icon-trash"></i> Clear Console
            </button>
        </small>
    </h2>
    
    <!-- Consola de Debug flotante -->
    <div id="debugConsole" style="position: fixed; bottom: 0; right: 0; width: 500px; height: 300px; background: #1e1e1e; color: #00ff00; font-family: 'Courier New', monospace; font-size: 12px; z-index: 9999; display: <?php echo $DEBUG_MODE ? 'block' : 'none'; ?>; border-top: 2px solid #00ff00;">
        <div style="background: #333; padding: 5px 10px; border-bottom: 1px solid #555;">
            <strong style="color: white;">🛠️ Debug Console</strong>
            <span style="float: right;">
                <button onclick="toggleConsole()" style="background: #666; border: none; color: white; padding: 2px 8px; margin-left: 5px;">X</button>
            </span>
        </div>
        <div id="consoleContent" style="padding: 10px; height: 250px; overflow-y: auto;"></div>
        <div style="background: #333; padding: 5px 10px; border-top: 1px solid #555;">
            <input type="text" id="consoleInput" placeholder="Escribe un comando JS..." style="width: 80%; background: #444; color: white; border: 1px solid #666; padding: 3px;">
            <button onclick="executeConsoleCommand()" style="background: #666; border: none; color: white; padding: 3px 10px;">Ejecutar</button>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-3">
            <div class="list-group" id="reportTypes">
                <a href="#" class="list-group-item active" data-type="tickets">
                    <i class="icon-ticket"></i> <?php echo __('Tickets'); ?>
                </a>
                <a href="#" class="list-group-item" data-type="agentes">
                    <i class="icon-user"></i> <?php echo __('Agentes'); ?>
                </a>
                <a href="#" class="list-group-item" data-type="sla">
                    <i class="icon-time"></i> <?php echo __('SLA'); ?>
                </a>
                <a href="#" class="list-group-item" data-type="clientes">
                    <i class="icon-group"></i> <?php echo __('Clientes'); ?>
                </a>
            </div>
            
            <!-- Plantillas Jasper detectadas -->
            <div class="well well-small" style="margin-top: 15px;">
                <h5><i class="icon-copy"></i> <?php echo __('Plantillas Jasper'); ?></h5>
                <div id="templatesList" style="max-height: 220px; overflow-y: auto;">
                    <?php
                    // Buscar JRXML en rutas conocidas
                    $tplDirs = array(
                        realpath(dirname(__FILE__) . '/../reports/java/templates'),
                        realpath(dirname(__FILE__) . '/../reports/templates')
                    );
                    $templates = array();
                    foreach ($tplDirs as $d) {
                        if ($d && is_dir($d)) {
                            foreach (glob($d . DIRECTORY_SEPARATOR . '*.jrxml') as $jr) {
                                $base = pathinfo($jr, PATHINFO_FILENAME);
                                $templates[$base] = true; // evitar duplicados
                            }
                        }
                    }
                    ksort($templates);
                    if (!$templates) {
                        echo '<div class="muted" style="font-size:12px;">' . __('No se encontraron plantillas JRXML') . '</div>';
                    } else {
                        foreach (array_keys($templates) as $tpl) {
                            echo '<button type="button" class="btn btn-xs btn-default jrxml-btn" data-template="' . htmlspecialchars($tpl) . '" style="margin:2px 2px;">'
                                . '<i class="icon-file"></i> ' . htmlspecialchars($tpl) . '</button>';
                        }
                    }
                    ?>
                </div>
                <div class="help-block" style="font-size:11px; color:#666; margin-top:6px;">
                    <?php echo __('Clic para seleccionar la plantilla (ajusta el tipo del reporte).'); ?>
                </div>
            </div>
            
            <div class="well well-small" style="margin-top: 20px;">
                <h5><?php echo __('Debug Tools'); ?></h5>
                <div style="font-size: 12px;">
                    <button onclick="testJava()" class="btn btn-xs btn-default">
                        <i class="icon-coffee"></i> Test Java
                    </button>
                    <button onclick="testJAR()" class="btn btn-xs btn-default">
                        <i class="icon-archive"></i> Test JAR
                    </button>
                    <button onclick="testAjax()" class="btn btn-xs btn-default">
                        <i class="icon-exchange"></i> Test AJAX
                    </button>
                    <!-- AGREGAR ESTOS NUEVOS BOTONES -->
                    <button onclick="testDatabase()" class="btn btn-xs btn-default">
                        <i class="icon-database"></i> Test DB
                    </button>
                    <button onclick="testQuery()" class="btn btn-xs btn-default">
                        <i class="icon-search"></i> Test Query
                    </button>
                    <button onclick="countTickets()" class="btn btn-xs btn-default">
                        <i class="icon-list"></i> Count Tickets
                    </button>
                    <button onclick="dumpFormData()" class="btn btn-xs btn-default">
                        <i class="icon-list-alt"></i> Dump Form
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h4 id="reportTitle"><i class="icon-ticket"></i> <?php echo __('Reporte de Tickets'); ?>
                        <span id="debugIndicator" style="font-size: 12px; float: right; color: #666;">
                            <i class="icon-circle" style="color: #4CAF50;"></i> Sistema OK
                        </span>
                    </h4>
                </div>
                <div class="card-body">
<form id="reportForm" method="post">
    <input type="hidden" id="reportType" name="tipo" value="tickets">
    
    <?php 
    // Campo CSRF
    if (isset($thisstaff) && method_exists($thisstaff, 'getCSRFToken')) {
        $csrf_token = $thisstaff->getCSRFToken();
    } elseif (function_exists('csrf_token')) {
        $csrf_token = csrf_token();
    } else {
        $csrf_token = $_SESSION['csrf_token'] ?? $_SESSION['__CSRFToken__'] ?? '';
    }
    
    if (!empty($csrf_token)) {
        echo '<input type="hidden" name="__CSRFToken__" value="' . htmlspecialchars($csrf_token) . '">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">';
    }
    ?>
    
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label><?php echo __('Fecha Desde'); ?> *</label>
                <input type="date" id="fechaDesde" name="fecha_desde" class="form-control" 
                    value="<?php echo date('Y-m-01'); ?>" required
                       onchange="logEvent('fechaDesde cambiada: ' + this.value)">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label><?php echo __('Fecha Hasta'); ?> *</label>
                <input type="date" id="fechaHasta" name="fecha_hasta" class="form-control" 
                       value="<?php echo date('Y-m-d'); ?>" required
                       onchange="logEvent('fechaHasta cambiada: ' + this.value)">
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4">
            <div class="form-group">
                <label><?php echo __('Departamento'); ?></label>
                <select id="departamento" name="departamento" class="form-control" 
                        onchange="logEvent('departamento cambiado: ' + this.value)">
                    <option value=""><?php echo __('Todos'); ?></option>
                    <?php
                    $depts = db_query("SELECT dept_id, dept_name FROM ost_department ORDER BY dept_name");
                    while($row = db_fetch_array($depts)) {
                        echo '<option value="'.$row['dept_id'].'">'.htmlspecialchars($row['dept_name']).'</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="form-group">
                <label><?php echo __('Estado'); ?></label>
                <select id="estado" name="estado" class="form-control" 
                        onchange="logEvent('estado cambiado: ' + this.value)">
                    <option value=""><?php echo __('Todos'); ?></option>
                    <?php
                    $status = db_query("SELECT id, name FROM ost_ticket_status ORDER BY name");
                    while($row = db_fetch_array($status)) {
                        echo '<option value="'.$row['id'].'">'.htmlspecialchars($row['name']).'</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="form-group">
                <label><?php echo __('Formato'); ?> *</label>
                <select id="formato" name="formato" class="form-control" required 
                        onchange="logEvent('formato cambiado: ' + this.value)">
                    <option value="pdf">PDF</option>
                    <option value="html">HTML</option>
                    <option value="csv">CSV</option>
                    <option value="xlsx">Excel (XLSX)</option>
                    <option value="xls">Excel 97-2003 (XLS)</option>
                </select>
            </div>
        </div>
    </div>
                        
                        <div class="form-group">
                            <button type="button" class="btn btn-primary btn-lg" onclick="generateReport()" id="btnGenerar">
                                <i class="icon-download"></i> <?php echo __('Generar Reporte'); ?>
                            </button>
                            <button type="button" class="btn btn-default" onclick="resetForm()">
                                <i class="icon-refresh"></i> <?php echo __('Limpiar'); ?>
                            </button>
                            <button type="button" class="btn btn-info" onclick="showFormData()">
                                <i class="icon-eye-open"></i> Ver Datos
                            </button>
                        </div>
                        
                        <!-- Panel de progreso con debug -->
                        <div id="progressPanel" style="display: none; margin-top: 20px;">
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped active" id="progressBar" style="width: 0%">
                                    0%
                                </div>
                            </div>
                            <div id="progressDetails" style="font-size: 12px; margin-top: 10px;">
                                <div id="step1">1. Validando datos...</div>
                                <div id="step2">2. Generando XML...</div>
                                <div id="step3">3. Ejecutando Java...</div>
                                <div id="step4">4. Descargando archivo...</div>
                            </div>
                        </div>
                        
                        <div id="message" style="margin-top: 15px;"></div>
                    </form>
                </div>
            </div>
            
            <!-- Panel de información del sistema -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="icon-info-sign"></i> <?php echo __('Estado del Sistema'); ?></h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm" style="font-size: 12px;">
                        <tr>
                            <td><strong>Java:</strong></td>
                            <td id="sysJava">❌ No verificado</td>
                            <td><button onclick="testJava()" class="btn btn-xs btn-default">Test</button></td>
                        </tr>
                        <tr>
                            <td><strong>JAR:</strong></td>
                            <td id="sysJAR"><?php echo $JAR_EXISTS ? '✅ Existe' : '❌ No encontrado'; ?></td>
                            <td><button onclick="testJAR()" class="btn btn-xs btn-default">Test</button></td>
                        </tr>
                        <tr>
                            <td><strong>AJAX:</strong></td>
                            <td id="sysAJAX">❌ No verificado</td>
                            <td><button onclick="testAjax()" class="btn btn-xs btn-default">Test</button></td>
                        </tr>
                        <tr>
                            <td><strong>Sesión:</strong></td>
                            <td id="sysSession"><?php echo $thisstaff ? '✅ Activa' : '❌ Inactiva'; ?></td>
                            <td><button onclick="checkSession()" class="btn btn-xs btn-default">Verificar</button></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Script de DEBUG completo -->
<script>
// =================================================================
// SISTEMA DE LOG EN CONSOLA
// =================================================================
let consoleLog = [];
let consoleEnabled = <?php echo $DEBUG_MODE ? 'true' : 'false'; ?>;

function logToConsole(message, type = 'info') {
    const colors = {
        'info': '#00ff00',
        'error': '#ff5555',
        'warning': '#ffff55',
        'debug': '#55ffff',
        'success': '#55ff55'
    };
    
    const timestamp = new Date().toLocaleTimeString();
    const logEntry = {
        time: timestamp,
        message: message,
        type: type
    };
    
    consoleLog.push(logEntry);
    
    // Mostrar en consola del navegador
    const consoleMethods = {
        'info': console.info,
        'error': console.error,
        'warning': console.warn,
        'debug': console.debug,
        'success': console.log
    };
    
    if (consoleMethods[type]) {
        consoleMethods[type](`[${timestamp}] ${message}`);
    }
    
    // Mostrar en consola flotante
    if (consoleEnabled) {
        const consoleDiv = document.getElementById('consoleContent');
        if (consoleDiv) {
            const entry = document.createElement('div');
            entry.style.color = colors[type] || '#ffffff';
            entry.style.marginBottom = '2px';
            entry.style.fontFamily = 'monospace';
            entry.style.fontSize = '11px';
            entry.innerHTML = `[${timestamp}] ${message}`;
            consoleDiv.appendChild(entry);
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
        }
    }
}

function logEvent(eventName) {
    logToConsole(`📝 ${eventName}`, 'debug');
}

// Funciones de diagnóstico de base de datos
function testDatabase() {
    logToConsole('🛢️ Obteniendo estadísticas de base de datos...', 'info');
    
    fetch('ajax_reportes.php?test=db_stats')
        .then(response => response.text())
        .then(data => {
            logToConsole('📊 Estadísticas DB:', 'info');
            const lines = data.split('\n');
            lines.forEach(line => {
                if (line.trim()) {
                    if (line.includes('TOTAL TICKETS:') || line.includes('tickets')) {
                        logToConsole(line, 'success');
                    } else if (line.includes('RANGO DE FECHAS:')) {
                        logToConsole(line, 'info');
                    } else if (line.includes('ÚLTIMOS') || line.includes('TEST')) {
                        logToConsole(line, 'debug');
                    } else {
                        logToConsole(line, 'info');
                    }
                }
            });
        })
        .catch(error => {
            logToConsole(`❌ Error obteniendo stats DB: ${error.message}`, 'error');
        });
}

function testQuery() {
    logToConsole('🔍 Probando consulta SQL...', 'info');
    
    fetch('ajax_reportes.php?test_query')
        .then(response => response.text())
        .then(data => {
            logToConsole('📋 Resultado test query:', 'info');
            console.log(data);
            
            const lines = data.split('\n');
            lines.forEach(line => {
                if (line.trim()) {
                    if (line.includes('Registro') || line.includes('Array')) {
                        logToConsole(line, 'debug');
                    } else if (line.includes('✅') || line.includes('encontrados')) {
                        logToConsole(line, 'success');
                    } else if (line.includes('❌')) {
                        logToConsole(line, 'error');
                    } else {
                        logToConsole(line, 'info');
                    }
                }
            });
        })
        .catch(error => {
            logToConsole(`❌ Error en test query: ${error.message}`, 'error');
        });
}

function testQueryWithDates() {
    const desde = document.getElementById('fechaDesde').value || '2021-01-01';
    const hasta = document.getElementById('fechaHasta').value || '2025-12-31';
    
    logToConsole(`🔍 Probando consulta con fechas ${desde} a ${hasta}`, 'info');
    
    // Crear formulario de prueba
    const formData = new FormData();
    formData.append('action', 'test_dates');
    formData.append('fecha_desde', desde);
    formData.append('fecha_hasta', hasta);
    formData.append('__CSRFToken__', getCSRFToken());
    
    fetch('ajax_reportes.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            logToConsole(`❌ Error: ${data.error}`, 'error');
        } else {
            logToConsole(`✅ Tickets encontrados: ${data.count}`, 'success');
            if (data.range) {
                logToConsole(`📅 Rango real en BD: ${data.range.real_min} a ${data.range.real_max}`, 'info');
            }
            if (data.sample && data.sample.length > 0) {
                logToConsole('📝 Ejemplos:', 'info');
                data.sample.forEach(ticket => {
                    logToConsole(`  #${ticket.number}: ${ticket.subject} (${ticket.created})`, 'debug');
                });
            }
        }
    })
    .catch(error => {
        logToConsole(`❌ Error en test: ${error.message}`, 'error');
    });
}

function countTickets() {
    const desde = document.getElementById('fechaDesde').value || '2024-01-01';
    const hasta = document.getElementById('fechaHasta').value || '2025-12-18';
    
    logToConsole(`🔢 Contando tickets desde ${desde} hasta ${hasta}...`, 'info');
    
    // Crear un endpoint simple para contar
    const formData = new FormData();
    formData.append('action', 'count_tickets');
    formData.append('fecha_desde', desde);
    formData.append('fecha_hasta', hasta);
    formData.append('__CSRFToken__', getCSRFToken());
    
    fetch('ajax_reportes.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            logToConsole(`❌ Error: ${data.error}`, 'error');
        } else {
            logToConsole(`📊 Tickets encontrados: ${data.count}`, 'success');
            if (data.sample) {
                logToConsole('📝 Ejemplo de tickets:', 'info');
                data.sample.forEach(ticket => {
                    logToConsole(`  #${ticket.number}: ${ticket.subject} (${ticket.created})`, 'debug');
                });
            }
        }
    })
    .catch(error => {
        logToConsole(`❌ Error contando tickets: ${error.message}`, 'error');
    });
}

function debugAjaxResponse(responseText) {
    logToConsole('🔍 ANALIZANDO RESPUESTA:', 'debug');
    
    // Verificar si es HTML con errores PHP
    if (responseText.includes('<b>Deprecated</b>') || 
        responseText.includes('<b>Warning</b>') || 
        responseText.includes('<b>Fatal error</b>')) {
        logToConsole('⚠️ Se detectaron errores PHP en la respuesta', 'warning');
        
        // Extraer solo el texto del error
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = responseText;
        const textOnly = tempDiv.textContent || tempDiv.innerText || '';
        
        logToConsole('📄 Texto de error extraído:', 'error');
        logToConsole(textOnly.substring(0, 1000), 'error');
    }
    
    // Verificar si es JSON válido
    try {
        JSON.parse(responseText);
        logToConsole('✅ Respuesta es JSON válido', 'success');
    } catch (e) {
        logToConsole('❌ Respuesta NO es JSON válido', 'error');
        logToConsole('Primeros 500 caracteres:', 'debug');
        logToConsole(responseText.substring(0, 500), 'debug');
    }
}

function logFormData() {
    const formData = {
        tipo: document.getElementById('reportType').value,
        fecha_desde: document.getElementById('fechaDesde').value,
        fecha_hasta: document.getElementById('fechaHasta').value,
        departamento: document.getElementById('departamento').value,
        estado: document.getElementById('estado').value,
        formato: document.getElementById('formato').value
    };
    
    logToConsole('📋 Datos del formulario:', 'debug');
    logToConsole(JSON.stringify(formData, null, 2), 'debug');
    return formData;
}

// =================================================================
// FUNCIONES DE DEBUG
// =================================================================
function toggleConsole() {
    const consoleDiv = document.getElementById('debugConsole');
    consoleEnabled = !consoleEnabled;
    consoleDiv.style.display = consoleEnabled ? 'block' : 'none';
    logToConsole(`Consola ${consoleEnabled ? 'activada' : 'desactivada'}`, 'info');
}

function clearConsole() {
    const consoleDiv = document.getElementById('consoleContent');
    if (consoleDiv) {
        consoleDiv.innerHTML = '';
    }
    consoleLog = [];
    console.clear();
    logToConsole('Consola limpiada', 'warning');
}

function executeConsoleCommand() {
    const input = document.getElementById('consoleInput');
    if (input.value.trim()) {
        try {
            logToConsole(`>>> ${input.value}`, 'debug');
            const result = eval(input.value);
            logToConsole(`Resultado: ${result}`, 'success');
        } catch (error) {
            logToConsole(`Error: ${error.message}`, 'error');
        }
        input.value = '';
    }
}

// Helpers de red/errores
async function fetchTextOrError(url) {
    const res = await fetch(url);
    const txt = await res.text();
    if (!res.ok) {
        throw new Error(txt || `HTTP ${res.status}`);
    }
    return txt;
}

async function postJsonOrError(url, data) {
    // Siempre agregar el token CSRF
    const csrfToken = getCSRFToken();
    if (csrfToken) {
        data['__CSRFToken__'] = csrfToken;
    }
    
    logToConsole('📤 Enviando datos:', 'debug');
    logToConsole(JSON.stringify(data, null, 2), 'debug');
    
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(data)
    });
    
    const txt = await res.text();
    logToConsole(`📥 Respuesta HTTP ${res.status}:`, res.ok ? 'success' : 'error');
    logToConsole(txt, 'debug');
    
    let json;
    try {
        json = JSON.parse(txt);
    } catch (e) {
        throw new Error(txt || `Respuesta inválida (no JSON)`);
    }
    
    if (!res.ok || json.error || json.ok === false) {
        const msg = json.error || txt || `HTTP ${res.status}`;
        throw new Error(msg);
    }
    
    return json;
}

// =================================================================
// FUNCIONES DE TEST
// =================================================================
function testJava() {
    logToConsole('🧪 Probando Java...', 'info');
    updateSystemStatus('Java', '⌛ Probando...', 'warning');
    fetchTextOrError('ajax_reportes.php?test=java')
        .then(data => {
            logToConsole(data, 'info');
            logToConsole('✅ Java funciona correctamente', 'success');
            updateSystemStatus('Java', '✅ Funciona', 'success');
        })
        .catch(error => {
            logToConsole(`❌ Error en test Java: ${error.message}`, 'error');
            updateSystemStatus('Java', '❌ Error', 'error');
        });
}

function testJAR() {
    logToConsole('📦 Probando archivo JAR...', 'info');
    updateSystemStatus('JAR', '⌛ Verificando...', 'warning');
    fetchTextOrError('ajax_reportes.php?test=jar')
        .then(data => {
            logToConsole(data, 'info');
            updateSystemStatus('JAR', '✅ Encontrado', 'success');
        })
        .catch(error => {
            logToConsole(`❌ Error verificando JAR: ${error.message}`, 'error');
            updateSystemStatus('JAR', '❌ Error', 'error');
        });
}

function testAjax() {
    logToConsole('🔗 Probando conexión AJAX...', 'info');
    updateSystemStatus('AJAX', '⌛ Probando...', 'warning');
    const startTime = Date.now();
    fetchTextOrError('ajax_reportes.php?test=ping')
        .then(() => {
            const timeTaken = Date.now() - startTime;
            logToConsole(`✅ AJAX funciona (${timeTaken}ms)`, 'success');
            updateSystemStatus('AJAX', `✅ OK (${timeTaken}ms)`, 'success');
        })
        .catch(error => {
            logToConsole(`❌ AJAX falló: ${error.message}`, 'error');
            updateSystemStatus('AJAX', '❌ Error', 'error');
        });
}

function checkSession() {
    logToConsole('🔐 Verificando sesión...', 'info');
    updateSystemStatus('Session', '⌛ Verificando...', 'warning');
    fetchTextOrError('ajax_reportes.php?test=session')
        .then(data => {
            logToConsole(data, 'info');
            logToConsole('✅ Sesión activa y válida', 'success');
            updateSystemStatus('Session', '✅ Activa', 'success');
        })
        .catch(error => {
            logToConsole(`❌ Error verificando sesión: ${error.message}`, 'error');
            updateSystemStatus('Session', '❌ Error', 'error');
        });
}

function updateSystemStatus(system, message, type) {
    const element = document.getElementById(`sys${system}`);
    if (element) {
        const icons = {
            'success': '✅',
            'error': '❌',
            'warning': '⚠️'
        };
        element.innerHTML = `${icons[type] || ''} ${message}`;
        element.style.color = type === 'success' ? 'green' : type === 'error' ? 'red' : 'orange';
    }
}

// =================================================================
// FUNCIONES DEL FORMULARIO CON DEBUG
// =================================================================
function showFormData() {
    const formData = logFormData();
    
    // Mostrar en modal o alerta
    alert('📋 Datos del formulario:\n\n' + 
          `Tipo: ${formData.tipo}\n` +
          `Fecha Desde: ${formData.fecha_desde}\n` +
          `Fecha Hasta: ${formData.fecha_hasta}\n` +
          `Departamento: ${formData.departamento}\n` +
          `Estado: ${formData.estado}\n` +
          `Formato: ${formData.formato}`);
}

function dumpFormData() {
    logFormData();
    
    // También mostrar en consola del navegador
    console.table({
        'Tipo de Reporte': document.getElementById('reportType').value,
        'Fecha Desde': document.getElementById('fechaDesde').value,
        'Fecha Hasta': document.getElementById('fechaHasta').value,
        'Departamento': document.getElementById('departamento').value,
        'Estado': document.getElementById('estado').value,
        'Formato': document.getElementById('formato').value
    });
}

function updateProgress(step, percent, message) {
    const progressBar = document.getElementById('progressBar');
    const stepElement = document.getElementById(`step${step}`);
    
    if (progressBar) {
        progressBar.style.width = `${percent}%`;
        progressBar.textContent = `${percent}%`;
    }
    
    if (stepElement) {
        stepElement.innerHTML = `${step}. ${message}`;
        stepElement.style.fontWeight = percent === 100 ? 'bold' : 'normal';
    }
    
    logToConsole(`Progreso: Paso ${step} - ${percent}% - ${message}`, 'debug');
}

// =================================================================
// FUNCIÓN PRINCIPAL DE GENERACIÓN CON DEBUG DETALLADO
// =================================================================
function generateReport() {
    logToConsole('🚀 INICIANDO GENERACIÓN DE REPORTE', 'info');
    
    // Obtener y mostrar el token CSRF
    const csrfToken = getCSRFToken();
    if (csrfToken) {
        logToConsole(`🔑 Token CSRF a usar: ${csrfToken.substring(0, 20)}...`, 'success');
    } else {
        logToConsole('⚠️ ADVERTENCIA: No se encontró token CSRF', 'warning');
    }
    
    logFormData();

    // Validar
    if (!document.getElementById('fechaDesde').value || 
        !document.getElementById('fechaHasta').value || 
        !document.getElementById('formato').value) {
        logToConsole('❌ Validación fallida: Campos requeridos faltantes', 'error');
        showMessage('Por favor complete todos los campos requeridos', 'danger');
        return;
    }

    logToConsole('✅ Validación completada', 'success');

    // Mostrar panel de progreso
    document.getElementById('progressPanel').style.display = 'block';
    document.getElementById('btnGenerar').disabled = true;

    // Usar FormData para enviar el formulario completo
    const formData = new FormData(document.getElementById('reportForm'));
    
    // Agregar CSRF si no está en el formulario
    if (csrfToken && !formData.has('__CSRFToken__')) {
        formData.append('__CSRFToken__', csrfToken);
    }
    
    // Convertir FormData a objeto para logging
    const formDataObj = {};
    for (let [key, value] of formData.entries()) {
        formDataObj[key] = value;
    }
    
    logToConsole('📤 Enviando FormData:', 'debug');
    logToConsole(JSON.stringify(formDataObj, null, 2), 'debug');

    updateProgress(1, 25, 'Validando datos...');

    // Enviar usando FormData directamente
    fetch('ajax_reportes.php', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const text = await response.text();
        logToConsole(`📥 Respuesta HTTP ${response.status}:`, 'debug');
        logToConsole(text.substring(0, 500) + (text.length > 500 ? '...' : ''), 'debug');
        
        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            throw { message: `Respuesta no es JSON: ${text.substring(0, 200)}` };
        }
        
        if (!response.ok || json.error) {
            throw { message: json.error || `Error ${response.status}` , debug: json.debug || null };
        }
        
        return json;
    })
    .then(json => {
        updateProgress(2, 60, 'Generando XML...');
        updateProgress(3, 85, 'Ejecutando Java...');
        logToConsole('✅ JAR ejecutado correctamente', 'success');
        
        if (json.download) {
            const isHtml = (json.file || '').toLowerCase().endsWith('.html');
            updateProgress(4, 100, isHtml ? 'Abriendo en nueva pestaña...' : 'Descargando archivo...');
            logToConsole(isHtml ? `🧭 Abriendo reporte en nueva pestaña: ${json.download}` : `💾 Iniciando descarga: ${json.download}`, 'info');

            if (isHtml) {
                const win = window.open(json.download, '_blank');
                if (!win) {
                    const link = document.createElement('a');
                    link.href = json.download;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
                showMessage(`Reporte \"${json.file}\" generado. Abierto en una nueva pestaña.`, 'success');
            } else {
                // Forzar descarga (PDF, CSV) obteniendo blob y usando object URL
                fetch(json.download)
                    .then(res => res.blob())
                    .then(blob => {
                        const url = window.URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = json.file || 'reporte';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        window.URL.revokeObjectURL(url);
                        showMessage(`Reporte \"${json.file}\" generado. Descarga iniciada.`, 'success');
                    })
                    .catch(err => {
                        logToConsole('⚠️ Error descargando blob: ' + (err && err.message ? err.message : err), 'warning');
                        // Fallback: navegar al endpoint
                        const link = document.createElement('a');
                        link.href = json.download;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    });
            }
        } else {
            showMessage('Reporte generado pero no se recibió URL de descarga.', 'warning');
        }
    })
    .catch(error => {
        const debugData = error && error.debug ? error.debug : null;
        logToConsole(`❌ Error generando reporte: ${error.message || error}`, 'error');
        showMessage('Error generando reporte: ' + (error.message || error), 'danger');
        if (debugData) {
            try {
                logToConsole('🔎 Debug:', 'debug');
                logToConsole(JSON.stringify(debugData, null, 2), 'debug');
            } catch (e) {
                logToConsole('🔎 Debug (string): ' + debugData, 'debug');
            }
        }
        updateProgress(4, 100, 'Error');
    })
    .finally(() => {
        document.getElementById('btnGenerar').disabled = false;
        setTimeout(() => {
            document.getElementById('progressPanel').style.display = 'none';
            updateProgress(1, 0, 'Validando datos...');
            updateProgress(2, 0, 'Generando XML...');
            updateProgress(3, 0, 'Ejecutando Java...');
            updateProgress(4, 0, 'Descargando archivo...');
        }, 1200);
    });
}

function resetForm() {
    logToConsole('🔄 Restableciendo formulario', 'info');
    document.getElementById('fechaDesde').value = '<?php echo date("Y-m-01"); ?>';
    document.getElementById('fechaHasta').value = '<?php echo date("Y-m-d"); ?>';
    document.getElementById('departamento').value = '';
    document.getElementById('estado').value = '';
    document.getElementById('formato').value = 'pdf';
    document.getElementById('message').innerHTML = '';
    document.getElementById('message').className = '';
    logToConsole('✅ Formulario restablecido', 'success');
}

function showMessage(text, type) {
    const message = document.getElementById('message');
    message.innerHTML = text;
    message.className = `alert alert-${type}`;
    message.style.display = 'block';
    logToConsole(`💬 Mensaje: ${text}`, type === 'danger' ? 'error' : 'info');
}

function getCSRFToken() {
    // Primero buscar en inputs hidden
    const inputToken = document.querySelector('input[name="__CSRFToken__"]');
    if (inputToken && inputToken.value) {
        logToConsole('🔑 Token CSRF encontrado en input __CSRFToken__', 'debug');
        return inputToken.value;
    }
    
    const inputToken2 = document.querySelector('input[name="csrf_token"]');
    if (inputToken2 && inputToken2.value) {
        logToConsole('🔑 Token CSRF encontrado en input csrf_token', 'debug');
        return inputToken2.value;
    }
    
    // Buscar en meta tags
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (metaToken) {
        logToConsole('🔑 Token CSRF encontrado en meta tag', 'debug');
        return metaToken.getAttribute('content');
    }
    
    // Buscar en data attributes del body
    const bodyToken = document.body.dataset.csrfToken;
    if (bodyToken) {
        logToConsole('🔑 Token CSRF encontrado en body data attribute', 'debug');
        return bodyToken;
    }
    
    // Intentar obtener del objeto ost si existe
    if (window.ost && window.ost.csrf_token) {
        logToConsole('🔑 Token CSRF obtenido de window.ost', 'debug');
        return window.ost.csrf_token;
    }
    
    // Último intento: buscar cualquier input con "csrf" o "token"
    const csrfInputs = document.querySelectorAll('input');
    for (let input of csrfInputs) {
        if (input.name && input.name.toLowerCase().includes('csrf') && input.value) {
            logToConsole(`🔑 Token CSRF encontrado en input[name="${input.name}"]`, 'debug');
            return input.value;
        }
    }
    
    logToConsole('⚠️ Token CSRF no encontrado. La solicitud puede fallar.', 'warning');
    return '';
}
// =================================================================
// INICIALIZACIÓN
// =================================================================
$(document).ready(function() {
    logToConsole('📄 Página reportes.php cargada', 'success');
    logToConsole(`🖥️ User Agent: ${navigator.userAgent}`, 'debug');
    logToConsole(`🔗 URL: ${window.location.href}`, 'debug');
    
    // Cambiar tipo de reporte
    $('#reportTypes a').click(function(e) {
        e.preventDefault();
        $('#reportTypes a').removeClass('active');
        $(this).addClass('active');
        
        const type = $(this).data('type');
        $('#reportType').val(type);
        logToConsole(`📊 Tipo de reporte cambiado a: ${type}`, 'info');
        
        // Cambiar título
        const titles = {
            'tickets': 'Reporte de Tickets',
            'agentes': 'Productividad de Agentes',
            'sla': 'Cumplimiento de SLA',
            'clientes': 'Reporte de Clientes'
        };
        
        const icons = {
            'tickets': 'ticket',
            'agentes': 'user',
            'sla': 'time',
            'clientes': 'group'
        };
        
        $('#reportTitle').html(`<i class="icon-${icons[type]}"></i> ${titles[type] || 'Reporte'}`);
    });

    // Botones de plantillas JRXML -> ajustan el tipo al nombre de plantilla
    $(document).on('click', '.jrxml-btn', function(e) {
        e.preventDefault();
        const tpl = $(this).data('template');
        if (!tpl) return;
        $('#reportType').val(tpl);
        $('.jrxml-btn').removeClass('btn-primary').addClass('btn-default');
        $(this).removeClass('btn-default').addClass('btn-primary');
        logToConsole(`🧩 Plantilla seleccionada: ${tpl}`, 'info');
        $('#reportTitle').html(`<i class="icon-file"></i> ${tpl}`);
    });
    
    // Verificar sistema al cargar
    setTimeout(() => {
        testJava();
        testJAR();
        testAjax();
        checkSession();
    }, 1000);
    
    // Atajo de teclado para debug (Ctrl+Shift+D)
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && e.key === 'D') {
            toggleConsole();
            logToConsole('🎮 Atajo de teclado: Ctrl+Shift+D - Consola toggled', 'info');
        }
        if (e.ctrlKey && e.shiftKey && e.key === 'L') {
            clearConsole();
            logToConsole('🎮 Atajo de teclado: Ctrl+Shift+L - Consola limpiada', 'info');
        }
    });
    
    logToConsole('🎮 Atajos de teclado disponibles:', 'info');
    logToConsole('  Ctrl+Shift+D - Mostrar/Ocultar consola', 'debug');
    logToConsole('  Ctrl+Shift+L - Limpiar consola', 'debug');
    logToConsole('✅ Sistema de debug inicializado', 'success');
});
</script>

<?php
include(STAFFINC_DIR.'footer.inc.php');
?>