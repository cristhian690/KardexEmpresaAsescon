<?php
set_time_limit(0);
ini_set('memory_limit', '512M');

// ============================================================
// CONFIGURACIÓN DE BASE DE DATOS
// ============================================================
$host   = 'localhost';
$dbname = 'dbasescon';
$user   = 'root';
$pass   = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Error de conexión: ' . $e->getMessage());
date_default_timezone_set('America/Lima');

}

// ============================================================
// FUNCIÓN LOG DE ACTIVIDAD
// ============================================================
function registrarLog(PDO $pdo, string $accion, string $descripcion, string $detalle = '', int $registros = 0): void {
    try {
        $pdo->prepare("INSERT INTO kardex_log (accion, descripcion, detalle, registros) VALUES (?,?,?,?)")
            ->execute([$accion, $descripcion, $detalle, $registros]);
    } catch (Exception $e) { /* silencioso */ }
}

// ============================================================
// FUNCIONES HELPERS
// ============================================================
function parsearFecha($valor) {
    if ($valor === null || $valor === '') return null;
    $valor = trim((string)$valor);
    if (is_numeric($valor) && (int)$valor > 1000 && (int)$valor < 100000) {
        $fecha = gmdate('Y-m-d', ((int)$valor - 25569) * 86400);
        if ($fecha && $fecha !== '1970-01-01') return $fecha;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $valor, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3]))
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $valor;
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $valor, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3]))
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

function limpiarDecimal($v): float {
    if ($v === null || $v === '') return 0.0;
    $v = trim(str_replace(' ', '', (string)$v));
    // Si tiene punto: la coma es separador de miles (formato anglosajón: 125,067.568)
    if (strpos($v, '.') !== false) {
        $v = str_replace(',', '', $v);
    } else {
        // Si solo tiene coma: es decimal europeo (125,568 → 125.568)
        $v = str_replace(['.', ','], ['', '.'], $v);
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function limpiarTexto($v, int $max = 255): string {
    return mb_substr(trim((string)($v ?? '')), 0, $max);
}

function detectarSeparador(string $linea): string {
    $sep = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
    foreach ($sep as $s => $_) $sep[$s] = substr_count($linea, $s);
    arsort($sep);
    return array_key_first($sep);
}

// ============================================================
// FUNCIÓN: PROCESAR UN ARCHIVO CSV
// ============================================================
function procesarCSV(string $tmp, int $saltar, PDO $pdo): array {
    $handle = fopen($tmp, 'r');
    // Saltar BOM UTF-8
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $primeraLinea = fgets($handle);
    rewind($handle);
    if ($bom === "\xEF\xBB\xBF") fread($handle, 3);

    $sep = detectarSeparador($primeraLinea);

    // Detectar formato leyendo primera fila de datos
    // Formato A (16 cols): codigo, descripcion, fecha, ...
    // Formato B (15 cols): codigo, fecha, ... (sin descripcion)
    $formato = 'A';
    $handleDet = fopen($tmp, 'r');
    $bomDet = fread($handleDet, 3);
    if ($bomDet !== "ï»¿") rewind($handleDet);
    $idxDet = 0;
    while (($rowDet = fgetcsv($handleDet, 0, $sep)) !== false) {
        $idxDet++;
        if ($idxDet <= $saltar) continue;
        if (empty(array_filter(array_map('trim', $rowDet)))) continue;
        $numCols = count($rowDet);
        $col1    = trim($rowDet[1] ?? '');
        // Si col[1] parece fecha DD/MM/YYYY → sin descripcion (Formato B)
        if ($numCols <= 15 || preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/', $col1)) {
            $formato = 'B';
        }
        break;
    }
    fclose($handleDet);

    $sql = "INSERT INTO kardex
            (codigo, descripcion, fecha, comprobante_tipo, comprobante_serie,
             comprobante_numero, tipo_operacion,
             e_cantidad, e_costo_u, e_total,
             s_cantidad, s_costo_u, s_total,
             saldo_cantidad, saldo_costo_u, saldo_total)
            VALUES
            (:codigo,:descripcion,:fecha,:comprobante_tipo,:comprobante_serie,
             :comprobante_numero,:tipo_operacion,
             :e_cantidad,:e_costo_u,:e_total,
             :s_cantidad,:s_costo_u,:s_total,
             :saldo_cantidad,:saldo_costo_u,:saldo_total)";
    $stmt = $pdo->prepare($sql);

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET autocommit=0;");

    $insertados = 0; $errores = 0; $errores_det = [];
    $idx = 0; $en_tx = false; $LOTE = 500;

    while (($col = fgetcsv($handle, 0, $sep)) !== false) {
        $idx++;
        if ($idx <= $saltar) continue;
        if (empty(array_filter(array_map('trim', $col)))) continue;

        if ($insertados % $LOTE === 0) {
            if ($en_tx) $pdo->commit();
            $pdo->beginTransaction();
            $en_tx = true;
        }

        try {
            if ($formato === 'A') {
                // 16 cols: codigo, descripcion, fecha, c_tipo, c_serie, c_num, tipo_op, 9 nums
                $params = [
                    ':codigo'             => limpiarTexto($col[0]  ?? '', 50),
                    ':descripcion'        => limpiarTexto($col[1]  ?? '', 255),
                    ':fecha'              => parsearFecha($col[2]  ?? null),
                    ':comprobante_tipo'   => limpiarTexto($col[3]  ?? '', 20),
                    ':comprobante_serie'  => limpiarTexto($col[4]  ?? '', 20),
                    ':comprobante_numero' => limpiarTexto($col[5]  ?? '', 50),
                    ':tipo_operacion'     => limpiarTexto($col[6]  ?? '', 100),
                    ':e_cantidad'         => limpiarDecimal($col[7]  ?? 0),
                    ':e_costo_u'          => limpiarDecimal($col[8]  ?? 0),
                    ':e_total'            => limpiarDecimal($col[9]  ?? 0),
                    ':s_cantidad'         => limpiarDecimal($col[10] ?? 0),
                    ':s_costo_u'          => limpiarDecimal($col[11] ?? 0),
                    ':s_total'            => limpiarDecimal($col[12] ?? 0),
                    ':saldo_cantidad'     => limpiarDecimal($col[13] ?? 0),
                    ':saldo_costo_u'      => limpiarDecimal($col[14] ?? 0),
                    ':saldo_total'        => limpiarDecimal($col[15] ?? 0),
                ];
            } else {
                // 15 cols: codigo, fecha, c_tipo, c_serie, c_num, tipo_op, 9 nums (sin descripcion)
                $params = [
                    ':codigo'             => limpiarTexto($col[0]  ?? '', 50),
                    ':descripcion'        => '',
                    ':fecha'              => parsearFecha($col[1]  ?? null),
                    ':comprobante_tipo'   => limpiarTexto($col[2]  ?? '', 20),
                    ':comprobante_serie'  => limpiarTexto($col[3]  ?? '', 20),
                    ':comprobante_numero' => limpiarTexto($col[4]  ?? '', 50),
                    ':tipo_operacion'     => limpiarTexto($col[5]  ?? '', 100),
                    ':e_cantidad'         => limpiarDecimal($col[6]  ?? 0),
                    ':e_costo_u'          => limpiarDecimal($col[7]  ?? 0),
                    ':e_total'            => limpiarDecimal($col[8]  ?? 0),
                    ':s_cantidad'         => limpiarDecimal($col[9]  ?? 0),
                    ':s_costo_u'          => limpiarDecimal($col[10] ?? 0),
                    ':s_total'            => limpiarDecimal($col[11] ?? 0),
                    ':saldo_cantidad'     => limpiarDecimal($col[12] ?? 0),
                    ':saldo_costo_u'      => limpiarDecimal($col[13] ?? 0),
                    ':saldo_total'        => limpiarDecimal($col[14] ?? 0),
                ];
            }
            $stmt->execute($params);
            $insertados++;
        } catch (PDOException $e) {
            $errores++;
            if (count($errores_det) < 3) $errores_det[] = "Fila $idx: " . $e->getMessage();
        }
    }

    if ($en_tx) $pdo->commit();
    fclose($handle);

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1; SET autocommit=1;");

    return ['insertados' => $insertados, 'errores' => $errores, 'errores_det' => $errores_det, 'formato' => $formato];
}

// ============================================================
// PROCESAMIENTO POST — MÚLTIPLES ARCHIVOS
// ============================================================
$resultados = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivos'])) {
    $archivos = $_FILES['archivos'];
    $accion   = $_POST['accion']       ?? 'agregar';
    $saltar   = max(0, (int)($_POST['filas_saltar'] ?? 2));

    // Reorganizar array de múltiples archivos
    $lista = [];
    for ($i = 0; $i < count($archivos['name']); $i++) {
        if ($archivos['error'][$i] === UPLOAD_ERR_OK) {
            $lista[] = [
                'tmp'    => $archivos['tmp_name'][$i],
                'nombre' => $archivos['name'][$i],
                'ext'    => strtolower(pathinfo($archivos['name'][$i], PATHINFO_EXTENSION)),
                'size'   => $archivos['size'][$i],
            ];
        }
    }

    if (empty($lista)) {
        $resultados = [['ok' => false, 'archivo' => '—', 'msg' => 'No se recibió ningún archivo válido.']];
    } else {
        // Si es "reemplazar", borrar UNA sola vez antes de procesar
        if ($accion === 'reemplazar') {
            $pdo->exec("TRUNCATE TABLE kardex");
        }

        $resultados = [];
        foreach ($lista as $f) {
            if (!in_array($f['ext'], ['csv'])) {
                $resultados[] = [
                    'ok'      => false,
                    'archivo' => $f['nombre'],
                    'msg'     => 'Solo se aceptan archivos .csv — guarda tu Excel como CSV UTF-8.',
                ];
                continue;
            }

            $r = procesarCSV($f['tmp'], $saltar, $pdo);
            registrarLog($pdo, 'IMPORTAR',
                "Importó archivo: {$f['nombre']}",
                "{$r['insertados']} registros insertados" . ($r['errores'] > 0 ? ", {$r['errores']} errores" : ''),
                $r['insertados']
            );
            $resultados[] = [
                'ok'          => true,
                'archivo'     => $f['nombre'],
                'size'        => round($f['size'] / 1024, 1),
                'insertados'  => $r['insertados'],
                'errores'     => $r['errores'],
                'errores_det' => $r['errores_det'],
                'formato'     => $r['formato'] ?? 'A',
            ];
        }
    }
}

// Stats actuales de la BD
$statsDB = $pdo->query("SELECT COUNT(*) as total, COUNT(DISTINCT codigo) as productos FROM kardex")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Importar — Kardex</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0c10;--bg2:#111318;--bg3:#181c24;--border:#232731;--border2:#2e3340;--accent:#4f9eff;--accent2:#7b61ff;--accent3:#00e5b0;--danger:#ff4d6a;--warning:#ffb547;--text:#e8eaf0;--text2:#8b92a8;--text3:#555e72;--radius:10px;--radius2:6px;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Syne',sans-serif;font-size:14px;min-height:100vh;}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 20% 10%,rgba(79,158,255,.06) 0%,transparent 60%);pointer-events:none;z-index:0;}
.wrapper{position:relative;z-index:1;max-width:860px;margin:0 auto;padding:40px 20px;}

.back-link{display:inline-flex;align-items:center;gap:8px;color:var(--text2);text-decoration:none;font-size:13px;margin-bottom:28px;transition:color .15s;}
.back-link:hover{color:var(--accent);}
.page-title{font-size:28px;font-weight:800;margin-bottom:6px;}
.page-sub{color:var(--text2);font-size:13px;margin-bottom:24px;}

/* Stats BD */
.db-stats{display:flex;gap:14px;margin-bottom:24px;}
.db-stat{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 20px;flex:1;text-align:center;}
.db-stat-v{font-size:22px;font-weight:800;font-family:'JetBrains Mono',monospace;color:var(--accent);}
.db-stat-l{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-top:3px;}

/* Tip */
.tip-box{background:rgba(0,229,176,.07);border:1px solid rgba(0,229,176,.2);border-left:4px solid var(--accent3);border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:13px;line-height:1.6;}
.tip-box strong{color:var(--accent3);}
.tip-steps{margin-top:6px;padding-left:18px;color:var(--text2);}
.tip-steps li{margin:2px 0;}

.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:28px;margin-bottom:20px;}
.card-title{font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text3);font-family:'JetBrains Mono',monospace;margin-bottom:18px;}

/* Drop Zone múltiple */
.drop-zone{border:2px dashed var(--border2);border-radius:var(--radius);padding:44px 24px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;}
.drop-zone:hover,.drop-zone.dragover{border-color:var(--accent);background:rgba(79,158,255,.04);}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.drop-icon{font-size:40px;margin-bottom:12px;}
.drop-main{font-size:15px;font-weight:600;color:var(--text);margin-bottom:6px;}
.drop-sub{font-size:12px;color:var(--text3);}
.multi-badge{display:inline-block;margin-top:10px;padding:3px 12px;border-radius:20px;background:rgba(79,158,255,.15);color:var(--accent);font-size:11px;font-weight:700;font-family:'JetBrains Mono',monospace;}

/* Lista de archivos seleccionados */
.file-list{margin-top:16px;display:none;}
.file-list-title{font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;font-family:'JetBrains Mono',monospace;}
.file-item{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius2);margin-bottom:6px;}
.file-item-icon{font-size:16px;}
.file-item-name{font-size:12px;color:var(--text);font-family:'JetBrains Mono',monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.file-item-size{font-size:11px;color:var(--text3);font-family:'JetBrains Mono',monospace;}
.file-count{display:inline-block;margin-top:4px;font-size:12px;color:var(--accent);font-family:'JetBrains Mono',monospace;}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:4px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group label{font-size:12px;color:var(--text2);}
.form-group input,.form-group select{background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius2);color:var(--text);font-family:'JetBrains Mono',monospace;font-size:13px;padding:9px 12px;outline:none;transition:border-color .15s;}
.form-group input:focus,.form-group select:focus{border-color:var(--accent);}
.form-group .hint{font-size:11px;color:var(--text3);}

/* Advertencia reemplazar */
.warn-replace{display:none;background:rgba(255,77,106,.08);border:1px solid rgba(255,77,106,.2);border-radius:var(--radius2);padding:10px 14px;font-size:12px;color:var(--danger);margin-top:8px;}

.col-map{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:7px;margin-top:12px;}
.col-item{background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius2);padding:8px 12px;display:flex;align-items:center;gap:9px;}
.col-num{width:22px;height:22px;border-radius:4px;background:var(--accent);color:#fff;font-size:10px;font-weight:700;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.col-name{font-size:11px;color:var(--text2);}

.btn-submit{width:100%;padding:14px;border:none;border-radius:var(--radius2);background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;cursor:pointer;transition:all .18s;margin-top:24px;box-shadow:0 2px 16px rgba(79,158,255,.3);}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(79,158,255,.5);}
.btn-submit:disabled{opacity:.5;cursor:not-allowed;transform:none;}

/* Resultados múltiples */
.results-wrap{margin-bottom:24px;}
.results-title{font-size:13px;font-weight:700;margin-bottom:12px;color:var(--text);}
.result-item{border-radius:var(--radius);padding:16px 20px;margin-bottom:10px;display:flex;align-items:flex-start;gap:14px;}
.result-item.ok{background:rgba(0,229,176,.07);border:1px solid rgba(0,229,176,.2);border-left:4px solid var(--accent3);}
.result-item.err{background:rgba(255,77,106,.07);border:1px solid rgba(255,77,106,.2);border-left:4px solid var(--danger);}
.result-icon{font-size:20px;flex-shrink:0;margin-top:1px;}
.result-body{flex:1;}
.result-archivo{font-size:13px;font-weight:700;font-family:'JetBrains Mono',monospace;margin-bottom:5px;word-break:break-all;}
.result-item.ok .result-archivo{color:var(--accent3);}
.result-item.err .result-archivo{color:var(--danger);}
.result-stats{display:flex;gap:16px;flex-wrap:wrap;}
.result-stat{font-size:12px;color:var(--text2);font-family:'JetBrains Mono',monospace;}
.result-stat strong{color:var(--text);}
.result-msg{font-size:12px;color:var(--text2);margin-top:4px;}
.err-det{font-size:11px;color:var(--danger);margin-top:4px;font-family:'JetBrains Mono',monospace;}

.results-summary{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.summary-total{font-size:15px;font-weight:700;}
.summary-total span{color:var(--accent3);}
.btn-volver{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:var(--radius2);background:var(--bg3);border:1px solid var(--border2);color:var(--text2);text-decoration:none;font-size:13px;font-weight:600;transition:all .15s;}
.btn-volver:hover{background:var(--border);color:var(--text);}
.btn-nuevo{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:var(--radius2);background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;text-decoration:none;font-size:13px;font-weight:600;transition:all .15s;}
.btn-nuevo:hover{transform:translateY(-1px);}

/* Progress */
.progress-wrap{display:none;margin-top:16px;}
.progress-bar{height:5px;background:var(--border2);border-radius:3px;overflow:hidden;}
.progress-fill{height:100%;width:60%;border-radius:3px;background:linear-gradient(90deg,var(--accent),var(--accent3));animation:pg 1.5s ease-in-out infinite;}
@keyframes pg{0%{transform:translateX(-100%);}100%{transform:translateX(200%);}}
.progress-text{font-size:12px;color:var(--text3);margin-top:8px;font-family:'JetBrains Mono',monospace;}

@media(max-width:600px){.form-row{grid-template-columns:1fr;}.db-stats{flex-direction:column;}
  .wrapper{padding:20px 12px 80px;}
}
.bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:#111318;border-top:1px solid #232731;z-index:100;padding:8px 0;}
.bottom-nav-inner{display:flex;justify-content:space-around;}
.nav-item{display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;opacity:.5;padding:4px 12px;}
.nav-item.active{opacity:1;}
.nav-icon{font-size:20px;}
.nav-label{font-size:9px;font-weight:700;color:#8b92a8;letter-spacing:.5px;text-transform:uppercase;}
.nav-item.active .nav-label{color:#4f9eff;}
@media(max-width:768px){.bottom-nav{display:block;}}
</style>
</head>
<body>
<div class="wrapper">
  <a href="index.php" class="back-link">← Volver al Kardex</a>
  <div class="page-title">⬆ Importar archivos</div>
  <div class="page-sub">Sube uno o varios CSV a la vez — todos los productos en una sola operación</div>

  <!-- STATS BD ACTUAL -->
  <div class="db-stats">
    <div class="db-stat">
      <div class="db-stat-v"><?= number_format($statsDB['total']) ?></div>
      <div class="db-stat-l">Registros en BD</div>
    </div>
    <div class="db-stat">
      <div class="db-stat-v" style="color:var(--accent3)"><?= number_format($statsDB['productos']) ?></div>
      <div class="db-stat-l">Productos cargados</div>
    </div>
  </div>

  <!-- TIP CSV -->
  <div class="tip-box">
    <strong>💡 Cómo exportar de Excel a CSV:</strong>
    <ol class="tip-steps">
      <li>Abre tu archivo en Excel → <b>Archivo → Guardar como</b></li>
      <li>Tipo: <b>CSV UTF-8 (delimitado por comas)</b> → Guardar</li>
      <li>Repite para cada producto y súbelos todos aquí a la vez</li>
    </ol>
  </div>

  <!-- RESULTADOS -->
  <?php if ($resultados !== null): ?>
    <?php
      $total_insertados = array_sum(array_column(array_filter($resultados, fn($r) => $r['ok']), 'insertados'));
      $total_archivos   = count($resultados);
      $total_ok         = count(array_filter($resultados, fn($r) => $r['ok']));
    ?>
    <div class="results-summary">
      <div class="summary-total">
        <span><?= number_format($total_insertados) ?></span> registros importados
        de <?= $total_ok ?>/<?= $total_archivos ?> archivos
      </div>
      <div style="display:flex;gap:8px">
        <a href="importar.php" class="btn-nuevo">⬆ Subir más</a>
        <a href="index.php" class="btn-volver">📦 Ver Kardex →</a>
      </div>
    </div>

    <div class="results-wrap">
      <div class="results-title">Detalle por archivo:</div>
      <?php foreach ($resultados as $r): ?>
        <div class="result-item <?= $r['ok'] ? 'ok' : 'err' ?>">
          <div class="result-icon"><?= $r['ok'] ? '✅' : '❌' ?></div>
          <div class="result-body">
            <div class="result-archivo"><?= htmlspecialchars($r['archivo']) ?></div>
            <?php if ($r['ok']): ?>
              <div class="result-stats">
                <div class="result-stat"><strong><?= number_format($r['insertados']) ?></strong> insertados</div>
                <?php if ($r['errores'] > 0): ?>
                  <div class="result-stat" style="color:var(--warning)"><strong><?= $r['errores'] ?></strong> filas con error</div>
                <?php endif; ?>
                <div class="result-stat"><?= $r['size'] ?> KB</div>
                <div class="result-stat" style="color:var(--text3)">Formato <?= ($r['formato']??'A') === 'B' ? 'sin descripción' : 'con descripción' ?></div>
              </div>
              <?php foreach ($r['errores_det'] as $ed): ?>
                <div class="err-det">⚠ <?= htmlspecialchars($ed) ?></div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="result-msg"><?= $r['msg'] ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>

  <!-- FORMULARIO -->
  <form method="POST" enctype="multipart/form-data" id="importForm">

    <div class="card">
      <div class="card-title">01 · Seleccionar archivos CSV</div>
      <div class="drop-zone" id="dropZone">
        <input type="file" name="archivos[]" id="fileInput" accept=".csv" multiple required>
        <div class="drop-icon">📂</div>
        <div class="drop-main">Arrastra uno o varios archivos CSV aquí</div>
        <div class="drop-sub">o haz clic para seleccionar</div>
        <div class="multi-badge">✦ SELECCIÓN MÚLTIPLE ACTIVADA</div>
      </div>
      <!-- Lista de archivos seleccionados -->
      <div class="file-list" id="fileList">
        <div class="file-list-title">Archivos seleccionados:</div>
        <div id="fileItems"></div>
        <div class="file-count" id="fileCount"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">02 · Opciones</div>
      <div class="form-row">
        <div class="form-group">
          <label>Filas de encabezado a omitir</label>
          <input type="number" name="filas_saltar" value="2" min="0" max="10">
          <span class="hint">Tu Excel tiene 2 filas de cabecera</span>
        </div>
        <div class="form-group">
          <label>Acción al importar</label>
          <select name="accion" id="accionSelect">
            <option value="agregar">➕ Agregar al final (conservar existentes)</option>
            <option value="reemplazar">🗑 Reemplazar TODO (borrar y reimportar)</option>
          </select>
          <div class="warn-replace" id="warnReplace">
            ⚠ Esto borrará <strong>todos</strong> los registros actuales (<?= number_format($statsDB['total']) ?> registros de <?= $statsDB['productos'] ?> productos) antes de importar.
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">03 · Orden de columnas en el CSV</div>
      <p style="font-size:12px;color:var(--text3);margin-bottom:12px;">El sistema lee las columnas en este orden (columna A en adelante):</p>
      <div class="col-map">
        <?php foreach (['A'=>'Código','B'=>'Descripción','C'=>'Fecha','D'=>'C. Tipo','E'=>'C. Serie','F'=>'C. Número','G'=>'Tipo Operación','H'=>'E. Cantidad','I'=>'E. Costo Unit.','J'=>'E. Costo Total','K'=>'S. Cantidad','L'=>'S. Costo Unit.','M'=>'S. Costo Total','N'=>'Saldo Cantidad','O'=>'Saldo C. Unit.','P'=>'Saldo Total'] as $l => $n): ?>
        <div class="col-item"><div class="col-num"><?= $l ?></div><div class="col-name"><?= $n ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="progress-wrap" id="progressWrap">
      <div class="progress-bar"><div class="progress-fill"></div></div>
      <div class="progress-text" id="progressText">Procesando archivos, por favor espera…</div>
    </div>

    <button type="submit" class="btn-submit" id="btnSubmit">⬆ Importar archivos</button>
  </form>

  <?php endif; ?>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php" class="nav-item"><span class="nav-icon">📦</span><span class="nav-label">Kardex</span></a>
    <a href="importar.php" class="nav-item active"><span class="nav-icon">⬆</span><span class="nav-label">Importar</span></a>
    <a href="reporte.php" class="nav-item"><span class="nav-icon">📄</span><span class="nav-label">Reporte</span></a>
    <a href="log.php" class="nav-item"><span class="nav-icon">📋</span><span class="nav-label">Log</span></a>
  </div>
</nav>

<script>
const fi  = document.getElementById('fileInput');
const dz  = document.getElementById('dropZone');
const fl  = document.getElementById('fileList');
const fit = document.getElementById('fileItems');
const fc  = document.getElementById('fileCount');

function mostrarArchivos(files) {
  fit.innerHTML = '';
  let totalSize = 0;
  Array.from(files).forEach(f => {
    const kb = (f.size/1024).toFixed(1);
    totalSize += f.size;
    fit.innerHTML += `<div class="file-item">
      <span class="file-item-icon">📄</span>
      <span class="file-item-name">${f.name}</span>
      <span class="file-item-size">${kb} KB</span>
    </div>`;
  });
  fc.textContent = `${files.length} archivo${files.length>1?'s':''} · ${(totalSize/1024/1024).toFixed(2)} MB total`;
  fl.style.display = 'block';
  document.getElementById('btnSubmit').textContent = `⬆ Importar ${files.length} archivo${files.length>1?'s':''}`;
}

fi.addEventListener('change', () => { if (fi.files.length) mostrarArchivos(fi.files); });

dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('dragover');
  const dt = new DataTransfer();
  Array.from(e.dataTransfer.files).filter(f => f.name.endsWith('.csv')).forEach(f => dt.items.add(f));
  fi.files = dt.files;
  if (fi.files.length) mostrarArchivos(fi.files);
});

// Advertencia reemplazar
document.getElementById('accionSelect').addEventListener('change', function() {
  document.getElementById('warnReplace').style.display = this.value === 'reemplazar' ? 'block' : 'none';
});

// Progress al enviar
document.getElementById('importForm')?.addEventListener('submit', function() {
  document.getElementById('progressWrap').style.display = 'block';
  document.getElementById('btnSubmit').disabled = true;
  const msgs = [
    'Leyendo archivos CSV…',
    'Insertando registros en la base de datos…',
    'Procesando lotes de 500 filas…',
    'Casi listo, no cierres esta página…',
  ];
  let mi = 0;
  const pt = document.getElementById('progressText');
  setInterval(() => { mi=(mi+1)%msgs.length; pt.textContent=msgs[mi]; }, 3500);
});
</script>
</body>
</html>