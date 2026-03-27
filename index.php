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
    die(json_encode(['error' => $e->getMessage()]));
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
// ELIMINAR REGISTROS POR CÓDIGO
// ============================================================
$msg_eliminado = null;
if (isset($_GET['action']) && $_GET['action'] === 'delete_codigo' && !empty($_GET['del_codigo'])) {
    $del_codigo = trim($_GET['del_codigo']);
    $stmtDel = $pdo->prepare("DELETE FROM kardex WHERE codigo = :codigo");
    $stmtDel->execute([':codigo' => $del_codigo]);
    $eliminados = $stmtDel->rowCount();
    $msg_eliminado = ['codigo' => $del_codigo, 'total' => $eliminados];
    registrarLog($pdo, 'ELIMINAR', "Eliminó código $del_codigo", "$eliminados registros borrados", $eliminados);
    // Redirigir limpio para evitar re-ejecución con F5
    header("Location: index.php?deleted=" . urlencode($del_codigo) . "&n=" . $eliminados);
    exit;
}

// ============================================================
// PARÁMETROS DE BÚSQUEDA / FILTRO
// ============================================================
$search_codigo = trim($_GET['codigo'] ?? '');
$search_fecha_ini = trim($_GET['fecha_ini'] ?? '');
$search_fecha_fin = trim($_GET['fecha_fin'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

// ============================================================
// CONSTRUCCIÓN DE QUERY
// ============================================================
$where  = [];
$params = [];

if ($search_codigo !== '') {
    $where[]  = 'codigo LIKE :codigo';
    $params[':codigo'] = '%' . $search_codigo . '%';
}
if ($search_fecha_ini !== '') {
    $where[]  = 'fecha >= :fecha_ini';
    $params[':fecha_ini'] = $search_fecha_ini;
}
if ($search_fecha_fin !== '') {
    $where[]  = 'fecha <= :fecha_fin';
    $params[':fecha_fin'] = $search_fecha_fin;
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total de registros para paginación
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM kardex $whereSQL");
$stmtCount->execute($params);
$total = $stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $limit));

// Registros de la página actual
$stmt = $pdo->prepare("SELECT * FROM kardex $whereSQL ORDER BY fecha ASC, id ASC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Códigos únicos para autocomplete
$codigos = $pdo->query("SELECT DISTINCT codigo FROM kardex ORDER BY codigo")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kardex — Sistema de Inventario</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
/* ============================================================
   VARIABLES & RESET
   ============================================================ */
:root {
  --bg:         #0a0c10;
  --bg2:        #111318;
  --bg3:        #181c24;
  --border:     #232731;
  --border2:    #2e3340;
  --accent:     #4f9eff;
  --accent2:    #7b61ff;
  --accent3:    #00e5b0;
  --danger:     #ff4d6a;
  --warning:    #ffb547;
  --text:       #e8eaf0;
  --text2:      #8b92a8;
  --text3:      #555e72;
  --radius:     10px;
  --radius2:    6px;
  --shadow:     0 4px 24px rgba(0,0,0,.5);
  --font-mono:  'JetBrains Mono', monospace;
  --font-main:  'Syne', sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-main);
  font-size: 14px;
  min-height: 100vh;
  overflow-x: hidden;
}

/* ============================================================
   FONDO ANIMADO
   ============================================================ */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 80% 50% at 20% 10%, rgba(79,158,255,.06) 0%, transparent 60%),
    radial-gradient(ellipse 60% 40% at 80% 80%, rgba(123,97,255,.05) 0%, transparent 60%);
  pointer-events: none;
  z-index: 0;
}

/* ============================================================
   LAYOUT
   ============================================================ */
.wrapper {
  position: relative;
  z-index: 1;
  max-width: 1600px;
  margin: 0 auto;
  padding: 24px 20px;
}

/* ============================================================
   HEADER
   ============================================================ */
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 28px;
  padding-bottom: 20px;
  border-bottom: 1px solid var(--border);
}

.header-brand {
  display: flex;
  align-items: center;
  gap: 14px;
}

.header-icon {
  width: 44px;
  height: 44px;
  border-radius: var(--radius);
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  box-shadow: 0 0 20px rgba(79,158,255,.3);
}

.header-title { font-size: 22px; font-weight: 800; letter-spacing: -.5px; }
.header-sub   { font-size: 12px; color: var(--text2); font-family: var(--font-mono); margin-top: 2px; }

.header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ============================================================
   BOTONES
   ============================================================ */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 9px 18px;
  border-radius: var(--radius2);
  font-family: var(--font-main);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: all .18s ease;
  text-decoration: none;
  white-space: nowrap;
}
.btn-primary {
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  color: #fff;
  box-shadow: 0 2px 12px rgba(79,158,255,.3);
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 20px rgba(79,158,255,.5); }

.btn-success {
  background: linear-gradient(135deg, var(--accent3), #00b87c);
  color: #0a0c10;
  box-shadow: 0 2px 12px rgba(0,229,176,.2);
}
.btn-success:hover { transform: translateY(-1px); box-shadow: 0 4px 20px rgba(0,229,176,.4); }

.btn-ghost {
  background: var(--bg3);
  color: var(--text2);
  border: 1px solid var(--border2);
}
.btn-ghost:hover { background: var(--border); color: var(--text); }

.btn-danger {
  background: rgba(255,77,106,.12);
  color: var(--danger);
  border: 1px solid rgba(255,77,106,.2);
}
.btn-danger:hover { background: rgba(255,77,106,.22); }

/* ============================================================
   STATS CARDS
   ============================================================ */
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}

.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px 18px;
  position: relative;
  overflow: hidden;
  transition: border-color .2s;
}
.stat-card:hover { border-color: var(--border2); }
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
}
.stat-card:nth-child(1)::before { background: linear-gradient(90deg, var(--accent), transparent); }
.stat-card:nth-child(2)::before { background: linear-gradient(90deg, var(--accent3), transparent); }
.stat-card:nth-child(3)::before { background: linear-gradient(90deg, var(--warning), transparent); }
.stat-card:nth-child(4)::before { background: linear-gradient(90deg, var(--accent2), transparent); }

.stat-label { font-size: 11px; color: var(--text3); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
.stat-value { font-size: 24px; font-weight: 800; font-family: var(--font-mono); }
.stat-value.blue   { color: var(--accent); }
.stat-value.green  { color: var(--accent3); }
.stat-value.yellow { color: var(--warning); }
.stat-value.purple { color: var(--accent2); }
.stat-sub { font-size: 11px; color: var(--text3); margin-top: 4px; font-family: var(--font-mono); }

/* ============================================================
   FILTROS
   ============================================================ */
.filter-panel {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 18px 20px;
  margin-bottom: 20px;
}

.filter-title {
  font-size: 11px;
  color: var(--text3);
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 14px;
  font-family: var(--font-mono);
}

.filter-row {
  display: flex;
  gap: 12px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.filter-group { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 160px; }
.filter-group label { font-size: 12px; color: var(--text2); }

.filter-group input, .filter-group select {
  background: var(--bg3);
  border: 1px solid var(--border2);
  border-radius: var(--radius2);
  color: var(--text);
  font-family: var(--font-mono);
  font-size: 13px;
  padding: 9px 12px;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
  width: 100%;
}
.filter-group input:focus,
.filter-group select:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(79,158,255,.12);
}
.filter-group input::placeholder { color: var(--text3); }

/* Datalist autocomplete styling */
input[list]::-webkit-calendar-picker-indicator { display: none; }

/* ============================================================
   TABLA
   ============================================================ */
.table-container {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}

.table-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
  gap: 10px;
}

.table-info {
  font-size: 13px;
  color: var(--text2);
}
.table-info strong { color: var(--text); }

.table-scroll { overflow-x: auto; }

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
  min-width: 1100px;
}

thead tr {
  background: var(--bg3);
}

th {
  padding: 11px 12px;
  text-align: left;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: .8px;
  text-transform: uppercase;
  color: var(--text3);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}

th.center { text-align: center; }

/* Cabeceras de grupo */
.th-group {
  background: var(--bg);
  text-align: center;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  border-bottom: 1px solid var(--border);
  padding: 8px 12px;
}
.th-group.entradas { color: var(--accent3); border-top: 2px solid var(--accent3); }
.th-group.salidas  { color: var(--danger);  border-top: 2px solid var(--danger); }
.th-group.saldo    { color: var(--warning); border-top: 2px solid var(--warning); }
.th-group.comprobante { color: var(--accent); border-top: 2px solid var(--accent); }
.th-group.basic    { color: var(--text3); }

tbody tr {
  border-bottom: 1px solid var(--border);
  transition: background .12s;
}
tbody tr:hover { background: rgba(79,158,255,.04); }
tbody tr:last-child { border-bottom: none; }

td {
  padding: 10px 12px;
  color: var(--text2);
  white-space: nowrap;
  font-family: var(--font-mono);
  font-size: 12px;
}

td.td-codigo {
  color: var(--accent);
  font-weight: 600;
}

td.td-desc {
  color: var(--text);
  font-family: var(--font-main);
  font-size: 12.5px;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}

td.td-fecha { color: var(--text2); }

td.td-tipo-op {
  color: var(--accent3);
  font-size: 11px;
  font-weight: 600;
}

td.td-num { text-align: right; }
td.td-entrada { color: var(--accent3); text-align: right; }
td.td-salida  { color: var(--danger);  text-align: right; }
td.td-saldo   { color: var(--warning); text-align: right; font-weight: 600; }

.zero { color: var(--text3) !important; font-weight: 400 !important; }

/* Badge tipo operación */
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 600;
  font-family: var(--font-main);
}
.badge-entrada { background: rgba(0,229,176,.1);  color: var(--accent3); }
.badge-salida  { background: rgba(255,77,106,.1);  color: var(--danger); }
.badge-saldo   { background: rgba(255,181,71,.1);  color: var(--warning); }

/* ============================================================
   PAGINACIÓN
   ============================================================ */
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 16px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}

.page-btn {
  width: 34px;
  height: 34px;
  border-radius: var(--radius2);
  background: var(--bg3);
  border: 1px solid var(--border2);
  color: var(--text2);
  font-family: var(--font-mono);
  font-size: 12px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  transition: all .15s;
}
.page-btn:hover { background: var(--border); color: var(--text); }
.page-btn.active {
  background: var(--accent);
  border-color: var(--accent);
  color: #fff;
  font-weight: 700;
}
.page-btn.disabled { opacity: .3; pointer-events: none; }

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--text3);
}
.empty-icon { font-size: 48px; margin-bottom: 16px; opacity: .5; }
.empty-text { font-size: 15px; }

/* ============================================================
   TOAST / ALERTAS
   ============================================================ */
.toast {
  position: fixed;
  bottom: 24px; right: 24px;
  background: var(--bg3);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  padding: 14px 20px;
  font-size: 13px;
  color: var(--text);
  box-shadow: var(--shadow);
  z-index: 9999;
  transform: translateY(80px);
  opacity: 0;
  transition: all .3s ease;
  display: flex;
  align-items: center;
  gap: 10px;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-left: 3px solid var(--accent3); }
.toast.error   { border-left: 3px solid var(--danger); }

/* ============================================================
   RESPONSIVE MÓVIL
   ============================================================ */

/* Barra de navegación inferior móvil */
.bottom-nav {
  display: none;
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: var(--bg2);
  border-top: 1px solid var(--border);
  z-index: 100;
  padding: 8px 0 max(8px, env(safe-area-inset-bottom));
}
.bottom-nav-inner {
  display: flex;
  justify-content: space-around;
  align-items: center;
}
.nav-item {
  display: flex; flex-direction: column; align-items: center;
  gap: 3px; text-decoration: none; opacity: .5; transition: opacity .15s;
  padding: 4px 12px;
}
.nav-item.active { opacity: 1; }
.nav-icon { font-size: 20px; }
.nav-label { font-size: 9px; font-weight: 700; color: var(--text2); letter-spacing: .5px; text-transform: uppercase; }
.nav-item.active .nav-label { color: var(--accent); }

/* Cards móvil para tabla */
.mobile-card {
  display: none;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  margin-bottom: 8px;
  overflow: hidden;
}
.mc-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-bottom: 1px solid var(--border);
}
.mc-codigo { font-size: 13px; font-weight: 700; color: var(--accent); font-family: var(--font-mono); }
.mc-fecha  { font-size: 11px; color: var(--text3); font-family: var(--font-mono); }
.mc-desc   { padding: 6px 14px 0; font-size: 12px; color: var(--text2); }
.mc-badge  { padding: 4px 14px 8px; }
.mc-nums   { display: grid; grid-template-columns: 1fr 1fr 1fr; border-top: 1px solid var(--border); }
.mc-num    { padding: 10px 8px; text-align: center; }
.mc-num:not(:last-child) { border-right: 1px solid var(--border); }
.mc-num-label { font-size: 8px; color: var(--text3); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 3px; }
.mc-num-val   { font-size: 12px; font-weight: 700; font-family: var(--font-mono); }
.mc-comprobante { padding: 4px 14px 8px; font-size: 10px; color: var(--text3); font-family: var(--font-mono); }

@media (max-width: 768px) {
  .wrapper { padding: 12px 12px 80px; }
  .header { flex-direction: column; align-items: flex-start; gap: 10px; }
  .header-actions { width: 100%; display: none; } /* se mueve al bottom-nav */
  .stats  { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .stat-card { padding: 12px 14px; }
  .stat-value { font-size: 20px; }
  .filter-row { flex-direction: column; gap: 8px; }
  .filter-group { min-width: unset; }

  /* Tabla → cards */
  .table-scroll { display: none; }
  .mobile-card  { display: block; }
  .bottom-nav   { display: block; }

  /* Paginación más compacta */
  .pagination { gap: 4px; padding: 12px; }
  .page-btn   { width: 30px; height: 30px; font-size: 11px; }

  /* Delete banner */
  .delete-banner { flex-direction: column; align-items: flex-start; gap: 8px; }
  .btn-danger-outline { width: 100%; justify-content: center; }
}

/* ============================================================
   BOTÓN ELIMINAR + MODAL CONFIRMACIÓN
   ============================================================ */
.btn-danger-outline {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; border-radius: var(--radius2);
  font-family: var(--font-main); font-size: 13px; font-weight: 600;
  cursor: pointer; border: 1px solid rgba(255,77,106,.4);
  background: rgba(255,77,106,.08); color: var(--danger);
  text-decoration: none; transition: all .18s; white-space: nowrap;
}
.btn-danger-outline:hover {
  background: rgba(255,77,106,.18);
  border-color: var(--danger);
  transform: translateY(-1px);
}

.delete-banner {
  background: rgba(255,77,106,.07);
  border: 1px solid rgba(255,77,106,.25);
  border-left: 4px solid var(--danger);
  border-radius: var(--radius);
  padding: 14px 20px;
  margin-bottom: 16px;
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px;
}
.delete-banner-info { font-size: 13px; }
.delete-banner-info strong { color: var(--danger); }
.delete-banner-sub { font-size: 12px; color: var(--text3); margin-top: 3px; font-family: var(--font-mono); }

/* Modal */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.7);
  z-index: 9998; display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.modal-overlay.show { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2); border: 1px solid var(--border2);
  border-radius: var(--radius); padding: 32px;
  max-width: 440px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.6);
  transform: translateY(20px); transition: transform .2s;
}
.modal-overlay.show .modal { transform: translateY(0); }
.modal-icon { font-size: 40px; text-align: center; margin-bottom: 16px; }
.modal-title { font-size: 18px; font-weight: 800; text-align: center; margin-bottom: 8px; color: var(--danger); }
.modal-sub { font-size: 13px; color: var(--text2); text-align: center; margin-bottom: 6px; line-height: 1.6; }
.modal-codigo { font-size: 20px; font-weight: 800; color: var(--danger); text-align: center; font-family: var(--font-mono); margin: 12px 0; }
.modal-warning { font-size: 12px; color: var(--text3); text-align: center; margin-bottom: 24px; }
.modal-actions { display: flex; gap: 10px; }
.modal-actions .btn { flex: 1; justify-content: center; padding: 12px; }
</style>
</head>
<body>
<?php
// Stats para los cards
$statsQ = $pdo->prepare("
  SELECT
    COUNT(*) as total,
    SUM(e_cantidad) as total_entradas,
    SUM(s_cantidad) as total_salidas,
    COUNT(DISTINCT codigo) as productos
  FROM kardex $whereSQL
");
$statsQ->execute($params);
$stats = $statsQ->fetch(PDO::FETCH_ASSOC);
?>

<div class="wrapper">
  <!-- HEADER -->
  <div class="header">
    <div class="header-brand">
      <div class="header-icon">📦</div>
      <div>
        <div class="header-title">KARDEX</div>
        <div class="header-sub">Sistema de Control de Inventario · <?= htmlspecialchars($dbname) ?></div>
      </div>
    </div>
    <div class="header-actions">
      <a href="importar.php" class="btn btn-primary">⬆ Importar Excel</a>
      <a href="reporte.php<?= $search_codigo || $search_fecha_ini || $search_fecha_fin ? '?codigo='.urlencode($search_codigo).'&fecha_ini='.urlencode($search_fecha_ini).'&fecha_fin='.urlencode($search_fecha_fin) : '' ?>" class="btn btn-success" target="_blank">📄 Exportar Reporte</a>
      <a href="log.php" class="btn btn-ghost">📋 Actividad</a>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat-card">
      <div class="stat-label">Total Registros</div>
      <div class="stat-value blue"><?= number_format($stats['total']) ?></div>
      <div class="stat-sub">movimientos</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Entradas</div>
      <div class="stat-value green"><?= number_format($stats['total_entradas'] ?? 0, 3) ?></div>
      <div class="stat-sub">unidades</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Salidas</div>
      <div class="stat-value yellow"><?= number_format($stats['total_salidas'] ?? 0, 3) ?></div>
      <div class="stat-sub">unidades</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Productos</div>
      <div class="stat-value purple"><?= number_format($stats['productos']) ?></div>
      <div class="stat-sub">códigos distintos</div>
    </div>
  </div>

  <!-- FILTROS -->
  <div class="filter-panel">
    <div class="filter-title"> Filtrar registros</div>
    <form method="GET" action="">
      <div class="filter-row">
        <div class="filter-group">
          <label>Código de producto</label>
          <input type="text" name="codigo" list="lista-codigos"
                 value="<?= htmlspecialchars($search_codigo) ?>"
                 placeholder="Ej: 021007">
          <datalist id="lista-codigos">
            <?php foreach ($codigos as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="filter-group">
          <label>Fecha desde</label>
          <input type="date" name="fecha_ini" value="<?= htmlspecialchars($search_fecha_ini) ?>">
        </div>
        <div class="filter-group">
          <label>Fecha hasta</label>
          <input type="date" name="fecha_fin" value="<?= htmlspecialchars($search_fecha_fin) ?>">
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button type="submit" class="btn btn-primary">Buscar</button>
          <a href="index.php" class="btn btn-ghost">Limpiar</a>
        </div>
      </div>
    </form>
  </div>

  <!-- BANNER ELIMINAR (solo cuando hay filtro de código exacto) -->
  <?php if (!empty($search_codigo) && $total > 0): ?>
  <div class="delete-banner">
    <div class="delete-banner-info">
      <div>Código filtrado: <strong><?= htmlspecialchars($search_codigo) ?></strong>
        — <strong style="color:var(--text)"><?= number_format($total) ?></strong> registros encontrados
      </div>
      <div class="delete-banner-sub">Puedes eliminar todos los registros de este código para reimportarlo limpio</div>
    </div>
    <button class="btn-danger-outline" onclick="confirmarEliminar('<?= htmlspecialchars(addslashes($search_codigo)) ?>', <?= $total ?>)">
      🗑 Eliminar código
    </button>
  </div>
  <?php endif; ?>

  <!-- TABLA -->
  <div class="table-container">
    <div class="table-header">
      <div class="table-info">
        Mostrando <strong><?= number_format(min($offset + 1, $total)) ?>–<?= number_format(min($offset + $limit, $total)) ?></strong>
        de <strong><?= number_format($total) ?></strong> registros
        <?php if ($search_codigo || $search_fecha_ini || $search_fecha_fin): ?>
          <span style="color:var(--accent);margin-left:8px;">· filtrado</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th class="th-group basic" rowspan="2" style="vertical-align:middle">#</th>
            <th class="th-group basic" rowspan="2" style="vertical-align:middle">Código</th>
            <th class="th-group basic" rowspan="2" style="vertical-align:middle">Descripción</th>
            <th class="th-group basic" rowspan="2" style="vertical-align:middle">Fecha</th>
            <th class="th-group comprobante" colspan="3">Comprobante</th>
            <th class="th-group basic" rowspan="2" style="vertical-align:middle">Tipo Operación</th>
            <th class="th-group entradas" colspan="3">Entradas</th>
            <th class="th-group salidas"  colspan="3">Salidas</th>
            <th class="th-group saldo"    colspan="3">Saldo Final</th>
          </tr>
          <tr>
            <th>Tipo</th><th>Serie</th><th>Número</th>
            <th class="center">Cant.</th><th class="center">C. Unit.</th><th class="center">C. Total</th>
            <th class="center">Cant.</th><th class="center">C. Unit.</th><th class="center">C. Total</th>
            <th class="center">Cant.</th><th class="center">C. Unit.</th><th class="center">C. Total</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
          <tr>
            <td colspan="17">
              <div class="empty-state">
                <div class="empty-icon">🔍</div>
                <div class="empty-text">No se encontraron registros con los filtros aplicados.</div>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($registros as $i => $r):
            $es_entrada = $r['e_cantidad'] > 0;
            $es_salida  = $r['s_cantidad'] > 0;
            $fecha_fmt  = $r['fecha'] ? date('d/m/Y', strtotime($r['fecha'])) : '—';
          ?>
          <tr>
            <td style="color:var(--text3)"><?= $offset + $i + 1 ?></td>
            <td class="td-codigo"><?= htmlspecialchars($r['codigo']) ?></td>
            <td class="td-desc" title="<?= htmlspecialchars($r['descripcion']) ?>"><?= htmlspecialchars($r['descripcion']) ?></td>
            <td class="td-fecha"><?= $fecha_fmt ?></td>
            <td><?= htmlspecialchars($r['comprobante_tipo'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['comprobante_serie'] ?? '—') ?></td>
            <td class="td-num"><?= htmlspecialchars($r['comprobante_numero'] ?? '—') ?></td>
            <td>
              <?php
                $op = strtolower($r['tipo_operacion'] ?? '');
                $cls = str_contains($op, 'venta') || str_contains($op, 'salida') ? 'badge-salida'
                     : (str_contains($op, 'entrada') || str_contains($op, 'compra') ? 'badge-entrada' : 'badge-saldo');
              ?>
              <span class="badge <?= $cls ?>"><?= htmlspecialchars($r['tipo_operacion'] ?? '—') ?></span>
            </td>
            <!-- Entradas -->
            <td class="td-entrada <?= $r['e_cantidad'] == 0 ? 'zero' : '' ?>"><?= number_format($r['e_cantidad'], 3) ?></td>
            <td class="td-entrada <?= $r['e_costo_u'] == 0 ? 'zero' : '' ?>"><?= number_format($r['e_costo_u'], 4) ?></td>
            <td class="td-entrada <?= $r['e_total'] == 0 ? 'zero' : '' ?>"><?= number_format($r['e_total'], 3) ?></td>
            <!-- Salidas -->
            <td class="td-salida <?= $r['s_cantidad'] == 0 ? 'zero' : '' ?>"><?= number_format($r['s_cantidad'], 3) ?></td>
            <td class="td-salida <?= $r['s_costo_u'] == 0 ? 'zero' : '' ?>"><?= number_format($r['s_costo_u'], 4) ?></td>
            <td class="td-salida <?= $r['s_total'] == 0 ? 'zero' : '' ?>"><?= number_format($r['s_total'], 3) ?></td>
            <!-- Saldo -->
            <td class="td-saldo"><?= number_format($r['saldo_cantidad'], 3) ?></td>
            <td class="td-saldo"><?= number_format($r['saldo_costo_u'], 4) ?></td>
            <td class="td-saldo"><?= number_format($r['saldo_total'], 3) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- CARDS MÓVIL -->
    <div style="padding:8px;" id="mobileCards">
    <?php if (empty($registros)): ?>
      <div class="empty-state"><div class="empty-icon">🔍</div><div class="empty-text">Sin resultados.</div></div>
    <?php else: ?>
      <?php foreach ($registros as $i => $r):
        $fecha_fmt = $r['fecha'] ? date('d/m/Y', strtotime($r['fecha'])) : '—';
        $op  = strtolower($r['tipo_operacion'] ?? '');
        $cls = str_contains($op,'venta')||str_contains($op,'salida') ? 'badge-salida'
             : (str_contains($op,'entrada')||str_contains($op,'compra') ? 'badge-entrada' : 'badge-saldo');
      ?>
      <div class="mobile-card">
        <div class="mc-header">
          <span class="mc-codigo"><?= htmlspecialchars($r['codigo']) ?></span>
          <span class="mc-fecha"><?= $fecha_fmt ?></span>
        </div>
        <div class="mc-desc"><?= htmlspecialchars($r['descripcion']) ?></div>
        <div class="mc-badge"><span class="badge <?= $cls ?>"><?= htmlspecialchars($r['tipo_operacion'] ?? '—') ?></span></div>
        <?php if (!empty($r['comprobante_serie'])): ?>
        <div class="mc-comprobante"><?= htmlspecialchars($r['comprobante_tipo']??'') ?> · <?= htmlspecialchars($r['comprobante_serie']??'') ?> · <?= htmlspecialchars($r['comprobante_numero']??'') ?></div>
        <?php endif; ?>
        <div class="mc-nums">
          <div class="mc-num">
            <div class="mc-num-label">Entrada</div>
            <div class="mc-num-val td-entrada <?= $r['e_cantidad']==0?'zero':'' ?>"><?= number_format($r['e_cantidad'],3) ?></div>
          </div>
          <div class="mc-num">
            <div class="mc-num-label">Salida</div>
            <div class="mc-num-val td-salida <?= $r['s_cantidad']==0?'zero':'' ?>"><?= number_format($r['s_cantidad'],3) ?></div>
          </div>
          <div class="mc-num">
            <div class="mc-num-label">Saldo</div>
            <div class="mc-num-val td-saldo"><?= number_format($r['saldo_cantidad'],3) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- PAGINACIÓN -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>"
         class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">«</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1,$page-1)])) ?>"
         class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">‹</a>

      <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($p = $start; $p <= $end; $p++):
      ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
           class="page-btn <?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>

      <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages,$page+1)])) ?>"
         class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">›</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"
         class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">»</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- MODAL CONFIRMAR ELIMINAR -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <div class="modal-icon">⚠️</div>
    <div class="modal-title">¿Eliminar este código?</div>
    <div class="modal-sub">Vas a eliminar todos los registros del código:</div>
    <div class="modal-codigo" id="modalCodigo"></div>
    <div class="modal-warning" id="modalWarning"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="cerrarModal()">Cancelar</button>
      <a href="#" id="modalConfirm" class="btn btn-danger-outline" style="justify-content:center">
        🗑 Sí, eliminar
      </a>
    </div>
  </div>
</div>

<!-- BOTTOM NAV MÓVIL -->
<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php" class="nav-item active">
      <span class="nav-icon">📦</span>
      <span class="nav-label">Kardex</span>
    </a>
    <a href="importar.php" class="nav-item">
      <span class="nav-icon">⬆</span>
      <span class="nav-label">Importar</span>
    </a>
    <a href="reporte.php<?= $search_codigo || $search_fecha_ini || $search_fecha_fin ? '?codigo='.urlencode($search_codigo).'&fecha_ini='.urlencode($search_fecha_ini).'&fecha_fin='.urlencode($search_fecha_fin) : '' ?>" class="nav-item">
      <span class="nav-icon">📄</span>
      <span class="nav-label">Reporte</span>
    </a>
    <a href="log.php" class="nav-item">
      <span class="nav-icon">📋</span>
      <span class="nav-label">Log</span>
    </a>
  </div>
</nav>

<div class="toast" id="toast"></div>

<script>
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = (type==='success' ? '✅ ' : '❌ ') + msg;
  t.className = 'toast show ' + type;
  setTimeout(() => t.className = 'toast', 3500);
}

function confirmarEliminar(codigo, total) {
  document.getElementById('modalCodigo').textContent = codigo;
  document.getElementById('modalWarning').textContent =
    'Esta acción eliminará ' + total.toLocaleString() + ' registros permanentemente. No se puede deshacer.';
  document.getElementById('modalConfirm').href =
    'index.php?action=delete_codigo&del_codigo=' + encodeURIComponent(codigo);
  document.getElementById('modalOverlay').classList.add('show');
}

function cerrarModal() {
  document.getElementById('modalOverlay').classList.remove('show');
}

// Cerrar modal al hacer clic fuera
document.getElementById('modalOverlay').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});

<?php if (isset($_GET['imported'])): ?>
showToast('<?= (int)$_GET['imported'] ?> registros importados correctamente', 'success');
<?php endif; ?>
<?php if (isset($_GET['deleted']) && isset($_GET['n'])): ?>
showToast('<?= number_format((int)$_GET['n']) ?> registros del código "<?= htmlspecialchars($_GET['deleted']) ?>" eliminados', 'success');
<?php endif; ?>

// ── BUSCADOR EN TIEMPO REAL ───────────────────────────────
(function() {
  const input  = document.querySelector('input[name="codigo"]');
  const form   = input ? input.closest('form') : null;
  if (!input || !form) return;

  let timer = null;
  input.addEventListener('input', function() {
    clearTimeout(timer);
    timer = setTimeout(() => {
      // Preservar fechas si están activas
      const params = new URLSearchParams();
      if (this.value.trim()) params.set('codigo', this.value.trim());
      const fi = form.querySelector('input[name="fecha_ini"]');
      const ff = form.querySelector('input[name="fecha_fin"]');
      if (fi && fi.value) params.set('fecha_ini', fi.value);
      if (ff && ff.value) params.set('fecha_fin', ff.value);
      window.location.href = 'index.php' + (params.toString() ? '?' + params.toString() : '');
    }, 400); // 400ms después de dejar de escribir
  });
})();
</script>
</body>
</html>