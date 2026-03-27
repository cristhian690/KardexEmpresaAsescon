<?php
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
// ACCIONES (antes de cualquier output HTML)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'clear_log') {
    $pdo->exec("TRUNCATE TABLE kardex_log");
    header("Location: log.php?cleared=1");
    exit;
}

// ============================================================
// FILTROS
// ============================================================
$filter_accion    = trim($_GET['accion']    ?? '');
$filter_fecha_ini = trim($_GET['fecha_ini'] ?? '');
$filter_fecha_fin = trim($_GET['fecha_fin'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$where = []; $params = [];
if ($filter_accion) {
    $where[] = 'accion = :accion';
    $params[':accion'] = $filter_accion;
}
if ($filter_fecha_ini) {
    $where[] = 'DATE(fecha) >= :fi';
    $params[':fi'] = $filter_fecha_ini;
}
if ($filter_fecha_fin) {
    $where[] = 'DATE(fecha) <= :ff';
    $params[':ff'] = $filter_fecha_fin;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->prepare("SELECT COUNT(*) FROM kardex_log $whereSQL");
$total->execute($params);
$total = (int)$total->fetchColumn();
$totalPages = max(1, ceil($total / $limit));

$stmt = $pdo->prepare("SELECT * FROM kardex_log $whereSQL ORDER BY fecha DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats generales
$stats = $pdo->query("
    SELECT
        COUNT(*) as total_acciones,
        SUM(CASE WHEN accion='IMPORTAR'   THEN 1 ELSE 0 END) as importaciones,
        SUM(CASE WHEN accion='EXPORTAR'   THEN 1 ELSE 0 END) as exportaciones,
        SUM(CASE WHEN accion='ELIMINAR' THEN 1 ELSE 0 END) as eliminaciones,
        SUM(CASE WHEN accion='IMPORTAR'   THEN registros ELSE 0 END) as total_importados
    FROM kardex_log
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log de Actividad — Kardex</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0c10;--bg2:#111318;--bg3:#181c24;--border:#232731;--border2:#2e3340;--accent:#4f9eff;--accent2:#7b61ff;--accent3:#00e5b0;--danger:#ff4d6a;--warning:#ffb547;--text:#e8eaf0;--text2:#8b92a8;--text3:#555e72;--radius:10px;--radius2:6px;--font-mono:'JetBrains Mono',monospace;--font-main:'Syne',sans-serif;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--font-main);font-size:14px;min-height:100vh;}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 20% 10%,rgba(79,158,255,.06) 0%,transparent 60%);pointer-events:none;z-index:0;}
.wrapper{position:relative;z-index:1;max-width:1200px;margin:0 auto;padding:32px 20px;}

/* HEADER */
.header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid var(--border);}
.header-brand{display:flex;align-items:center;gap:14px;}
.header-icon{width:44px;height:44px;border-radius:var(--radius);background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 0 20px rgba(79,158,255,.3);}
.header-title{font-size:22px;font-weight:800;letter-spacing:-.5px;}
.header-sub{font-size:12px;color:var(--text2);font-family:var(--font-mono);margin-top:2px;}

/* BOTONES */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:var(--radius2);font-family:var(--font-main);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn-ghost{background:var(--bg3);color:var(--text2);border:1px solid var(--border2);}
.btn-ghost:hover{background:var(--border);color:var(--text);}
.btn-danger{background:rgba(255,77,106,.1);color:var(--danger);border:1px solid rgba(255,77,106,.2);}
.btn-danger:hover{background:rgba(255,77,106,.2);}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;position:relative;overflow:hidden;}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
.stat-card:nth-child(1)::before{background:linear-gradient(90deg,var(--accent),transparent);}
.stat-card:nth-child(2)::before{background:linear-gradient(90deg,var(--accent3),transparent);}
.stat-card:nth-child(3)::before{background:linear-gradient(90deg,var(--warning),transparent);}
.stat-card:nth-child(4)::before{background:linear-gradient(90deg,var(--danger),transparent);}
.stat-card:nth-child(5)::before{background:linear-gradient(90deg,var(--accent2),transparent);}
.stat-label{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.stat-value{font-size:22px;font-weight:800;font-family:var(--font-mono);}
.stat-value.blue{color:var(--accent);}.stat-value.green{color:var(--accent3);}
.stat-value.yellow{color:var(--warning);}.stat-value.red{color:var(--danger);}
.stat-value.purple{color:var(--accent2);}

/* FILTROS */
.filter-panel{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;}
.filter-title{font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;font-family:var(--font-mono);}
.filter-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.fg{display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px;}
.fg label{font-size:12px;color:var(--text2);}
.fg select,.fg input{background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius2);color:var(--text);font-family:var(--font-mono);font-size:12px;padding:8px 11px;outline:none;transition:border-color .15s;}
.fg select:focus,.fg input:focus{border-color:var(--accent);}

/* TABLA */
.table-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.table-topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;}
.table-topbar span{font-size:12px;color:var(--text2);}
.tscroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
thead tr{background:var(--bg3);}
th{padding:10px 14px;text-align:left;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border);white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
tbody tr:hover{background:rgba(79,158,255,.04);}
tbody tr:last-child{border-bottom:none;}
td{padding:10px 14px;color:var(--text2);font-family:var(--font-mono);font-size:12px;}
td.td-desc{font-family:var(--font-main);font-size:12.5px;color:var(--text);}
td.td-det{font-size:11px;color:var(--text3);}
td.td-num{text-align:right;color:var(--accent);font-weight:600;}
td.td-fecha{white-space:nowrap;}

/* BADGES ACCION */
.badge-accion{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;font-family:var(--font-main);white-space:nowrap;}
.badge-importar {background:rgba(79,158,255,.12); color:var(--accent);}
.badge-exportar {background:rgba(0,229,176,.12);  color:var(--accent3);}
.badge-eliminar {background:rgba(255,77,106,.12);  color:var(--danger);}


/* PAGINACIÓN */
.pagination{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px;border-top:1px solid var(--border);flex-wrap:wrap;}
.page-btn{width:34px;height:34px;border-radius:var(--radius2);background:var(--bg3);border:1px solid var(--border2);color:var(--text2);font-family:var(--font-mono);font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .15s;}
.page-btn:hover{background:var(--border);color:var(--text);}
.page-btn.active{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:700;}
.page-btn.disabled{opacity:.3;pointer-events:none;}

.empty{text-align:center;padding:60px;color:var(--text3);}
.empty-icon{font-size:40px;margin-bottom:14px;opacity:.4;}

/* Modal limpiar log */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9998;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
.modal-overlay.show{opacity:1;pointer-events:all;}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--radius);padding:32px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6);transform:translateY(20px);transition:transform .2s;}
.modal-overlay.show .modal{transform:translateY(0);}
.modal-icon{font-size:40px;text-align:center;margin-bottom:14px;}
.modal-title{font-size:17px;font-weight:800;text-align:center;color:var(--danger);margin-bottom:8px;}
.modal-sub{font-size:13px;color:var(--text2);text-align:center;margin-bottom:24px;line-height:1.6;}
.modal-actions{display:flex;gap:10px;}
.modal-actions .btn{flex:1;justify-content:center;padding:11px;}

@media(max-width:768px){
  .wrapper{padding:12px 12px 80px;}
  .stats{grid-template-columns:1fr 1fr;gap:8px;}
  .header{flex-direction:column;align-items:flex-start;}
  .filter-row{flex-direction:column;}
  .tscroll{overflow-x:auto;}
  th,td{padding:8px 10px;font-size:11px;}
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

  <!-- HEADER -->
  <div class="header">
    <div class="header-brand">
      <div class="header-icon">📋</div>
      <div>
        <div class="header-title">Log de Actividad</div>
        <div class="header-sub">Registro de todas las acciones del sistema · <?= htmlspecialchars($dbname) ?></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="index.php" class="btn btn-ghost">← Volver al Kardex</a>
      <button class="btn btn-danger" onclick="document.getElementById('modalOverlay').classList.add('show')">
        🗑 Limpiar log
      </button>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-label">Total Acciones</div>
      <div class="stat-value blue"><?= number_format($stats['total_acciones']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Importaciones</div>
      <div class="stat-value green"><?= number_format($stats['importaciones']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Exportaciones</div>
      <div class="stat-value yellow"><?= number_format($stats['exportaciones']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Eliminaciones</div>
      <div class="stat-value red"><?= number_format($stats['eliminaciones']) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Registros Importados</div>
      <div class="stat-value purple"><?= number_format($stats['total_importados']) ?></div>
    </div>
  </div>

  <!-- FILTROS -->
  <div class="filter-panel">
    <div class="filter-title"> Filtrar actividad</div>
    <form method="GET">
      <div class="filter-row">
        <div class="fg">
          <label>Tipo de acción</label>
          <select name="accion">
            <option value="">Todas las acciones</option>
            <option value="IMPORTAR" <?= $filter_accion==='IMPORTAR' ?'selected':'' ?>>⬆ Importar</option>
            <option value="EXPORTAR" <?= $filter_accion==='EXPORTAR' ?'selected':'' ?>>⬇ Exportar</option>
            <option value="ELIMINAR" <?= $filter_accion==='ELIMINAR' ?'selected':'' ?>>🗑 Eliminar</option>
          </select>
        </div>
        <div class="fg">
          <label>Desde</label>
          <input type="date" name="fecha_ini" value="<?= htmlspecialchars($filter_fecha_ini) ?>">
        </div>
        <div class="fg">
          <label>Hasta</label>
          <input type="date" name="fecha_fin" value="<?= htmlspecialchars($filter_fecha_fin) ?>">
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button type="submit" class="btn" style="background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff">Filtrar</button>
          <a href="log.php" class="btn btn-ghost">Limpiar</a>
        </div>
      </div>
    </form>
  </div>

  <!-- TABLA -->
  <div class="table-wrap">
    <div class="table-topbar">
      <span><?= number_format($total) ?> registros<?= $filter_accion||$filter_fecha_ini||$filter_fecha_fin ? ' <span style="color:var(--accent)">· filtrado</span>' : '' ?></span>
      <span style="font-family:var(--font-mono);font-size:11px;color:var(--text3)">Más recientes primero</span>
    </div>
    <div class="tscroll">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Acción</th>
            <th>Descripción</th>
            <th>Detalle</th>
            <th style="text-align:right">Registros</th>
            <th>Fecha y Hora</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="6">
            <div class="empty">
              <div class="empty-icon">📋</div>
              <div>No hay actividad registrada<?= $filter_accion||$filter_fecha_ini||$filter_fecha_fin ? ' con este filtro' : ' aún' ?>.</div>
            </div>
          </td></tr>
        <?php else: ?>
          <?php foreach ($logs as $i => $log):
            $badgeClass = match($log['accion']) {
              'IMPORTAR' => 'badge-importar',
              'EXPORTAR' => 'badge-exportar',
              'ELIMINAR' => 'badge-eliminar',
              default    => 'badge-importar',
            };
            $icono = match($log['accion']) {
              'IMPORTAR' => '⬆',
              'EXPORTAR' => '⬇',
              'ELIMINAR' => '🗑',
              default    => '•',
            };
            $fechaFmt = date('d/m/Y H:i:s', strtotime($log['fecha']));
            $hace = '';
            $diff = time() - strtotime($log['fecha']);
            if ($diff < 60)         $hace = 'hace ' . $diff . 's';
            elseif ($diff < 3600)   $hace = 'hace ' . floor($diff/60) . 'min';
            elseif ($diff < 86400)  $hace = 'hace ' . floor($diff/3600) . 'h';
            elseif ($diff < 604800) $hace = 'hace ' . floor($diff/86400) . 'd';
          ?>
          <tr>
            <td style="color:var(--text3)"><?= $offset + $i + 1 ?></td>
            <td>
              <span class="badge-accion <?= $badgeClass ?>">
                <?= $icono ?> <?= $log['accion'] ?>
              </span>
            </td>
            <td class="td-desc"><?= htmlspecialchars($log['descripcion']) ?></td>
            <td class="td-det"><?= htmlspecialchars($log['detalle'] ?? '—') ?></td>
            <td class="td-num"><?= $log['registros'] > 0 ? number_format($log['registros']) : '—' ?></td>
            <td class="td-fecha">
              <div style="color:var(--text2)"><?= $fechaFmt ?></div>
              <?php if ($hace): ?>
              <div style="font-size:10px;color:var(--text3);margin-top:2px"><?= $hace ?></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>1])) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">«</a>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for($p=max(1,$page-2);$p<=min($totalPages,$page+2);$p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>" class="page-btn <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">›</a>
      <a href="?<?= http_build_query(array_merge($_GET,['page'=>$totalPages])) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">»</a>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- MODAL LIMPIAR LOG -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-icon">⚠️</div>
    <div class="modal-title">¿Limpiar todo el log?</div>
    <div class="modal-sub">Se borrarán todos los <?= number_format($stats['total_acciones']) ?> registros de actividad. Los datos del kardex no se tocan.</div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="document.getElementById('modalOverlay').classList.remove('show')">Cancelar</button>
      <a href="log.php?action=clear_log" class="btn btn-danger" style="justify-content:center">🗑 Sí, limpiar</a>
    </div>
  </div>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php" class="nav-item"><span class="nav-icon">📦</span><span class="nav-label">Kardex</span></a>
    <a href="importar.php" class="nav-item"><span class="nav-icon">⬆</span><span class="nav-label">Importar</span></a>
    <a href="reporte.php" class="nav-item"><span class="nav-icon">📄</span><span class="nav-label">Reporte</span></a>
    <a href="log.php" class="nav-item active"><span class="nav-icon">📋</span><span class="nav-label">Log</span></a>
  </div>
</nav>

<script>
document.getElementById('modalOverlay').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('show');
});
<?php if (isset($_GET['cleared'])): ?>
// Toast simple
const d = document.createElement('div');
d.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#111318;border:1px solid #2e3340;border-left:3px solid #00e5b0;border-radius:10px;padding:14px 20px;color:#e8eaf0;font-family:Syne,sans-serif;font-size:13px;z-index:9999;box-shadow:0 4px 24px rgba(0,0,0,.5)';
d.textContent = '✅ Log limpiado correctamente';
document.body.appendChild(d);
setTimeout(() => d.remove(), 3500);
<?php endif; ?>
</script>
</body>
</html>