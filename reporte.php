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
}

// Zona horaria Perú (UTC-5)
date_default_timezone_set('America/Lima');

// ============================================================
// PARÁMETROS DE FILTRO
// ============================================================
$search_codigo    = trim($_GET['codigo']    ?? '');
$search_fecha_ini = trim($_GET['fecha_ini'] ?? '');
$search_fecha_fin = trim($_GET['fecha_fin'] ?? '');
$export           = $_GET['export'] ?? '';

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
$stmt = $pdo->prepare("SELECT * FROM kardex $whereSQL ORDER BY codigo ASC, fecha ASC, id ASC");
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// FUNCIÓN LOG
// ============================================================
function registrarLog(PDO $pdo, string $accion, string $descripcion, string $detalle = '', int $registros = 0): void {
    try {
        $pdo->prepare("INSERT INTO kardex_log (accion, descripcion, detalle, registros) VALUES (?,?,?,?)")
            ->execute([$accion, $descripcion, $detalle, $registros]);
    } catch (Exception $e) {}
}

// ============================================================
// EXPORTAR XLSX
// ============================================================
if ($export === 'excel') {
    if (empty($registros)) { die("No hay datos para exportar."); }

    // ── Helpers ───────────────────────────────────────────
    function colLetter(int $n): string {
        $s = '';
        while ($n > 0) { $n--; $s = chr(65 + ($n % 26)) . $s; $n = (int)($n / 26); }
        return $s;
    }
    function xe(string $v): string {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ── Definición de columnas ─────────────────────────────
    $colDefs = [
        // [grupo, encabezado, tipo, ancho]
        ['',            '#',                 'n', 5 ],
        ['',            'Código',            's', 13],
        ['',            'Descripción',       's', 35],
        ['',            'Fecha',             's', 12],
        ['COMPROBANTE', 'C. Tipo',           's', 9 ],
        ['COMPROBANTE', 'C. Serie',          's', 11],
        ['COMPROBANTE', 'C. Número',         's', 13],
        ['',            'Tipo Operación',    's', 18],
        ['ENTRADAS',    'E. Cantidad',       'n', 14],
        ['ENTRADAS',    'E. Costo Unit.',    'n', 14],
        ['ENTRADAS',    'E. Costo Total',    'n', 14],
        ['SALIDAS',     'S. Cantidad',       'n', 14],
        ['SALIDAS',     'S. Costo Unit.',    'n', 14],
        ['SALIDAS',     'S. Costo Total',    'n', 14],
        ['SALDO FINAL', 'Saldo Cantidad',    'n', 16],
        ['SALDO FINAL', 'Saldo Costo Unit.', 'n', 16],
        ['SALDO FINAL', 'Saldo Total',       'n', 16],
    ];

    // ── Shared Strings ─────────────────────────────────────
    $sstMap = []; $sstArr = [];
    $si = function(string $v) use (&$sstMap, &$sstArr): int {
        if (!array_key_exists($v, $sstMap)) {
            $sstMap[$v] = count($sstArr);
            $sstArr[]   = $v;
        }
        return $sstMap[$v];
    };
    // Pre-cargar cabeceras
    foreach ($colDefs as $cd) { $si($cd[0]); $si($cd[1]); }
    $si('TOTALES'); $si('');
    // Pre-cargar textos de datos
    foreach ($registros as $r) {
        $si((string)($r['codigo']             ?? ''));
        $si((string)($r['descripcion']        ?? ''));
        $si($r['fecha'] ? date('d/m/Y', strtotime($r['fecha'])) : '');
        $si((string)($r['comprobante_tipo']   ?? ''));
        $si((string)($r['comprobante_serie']  ?? ''));
        $si((string)($r['comprobante_numero'] ?? ''));
        $si((string)($r['tipo_operacion']     ?? ''));
    }

    // ── Estilos ────────────────────────────────────────────
    // s="0" texto normal con borde
    // s="1" cabecera: negrita blanca, fondo azul oscuro, centrado
    // s="2" número 4 dec, fondo azul claro
    // s="3" número 4 dec negrita (totales)
    // s="4" # fila centrado
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="1">
    <numFmt numFmtId="164" formatCode="#,##0.0000"/>
  </numFmts>
  <fonts count="2">
    <font><sz val="10"/><name val="Calibri"/></font>
    <font><b/><sz val="10"/><name val="Calibri"/></font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left   style="thin"><color rgb="FF000000"/></left>
      <right  style="thin"><color rgb="FF000000"/></right>
      <top    style="thin"><color rgb="FF000000"/></top>
      <bottom style="thin"><color rgb="FF000000"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="5">
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>
    <xf numFmtId="0"   fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"><alignment horizontal="right"/></xf>
    <xf numFmtId="0"   fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

    // ── Sheet XML ──────────────────────────────────────────
    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

    // Anchos
    $xml .= '<cols>';
    foreach ($colDefs as $ci => $cd) {
        $n = $ci + 1;
        $xml .= '<col min="'.$n.'" max="'.$n.'" width="'.$cd[3].'" customWidth="1"/>';
    }
    $xml .= '</cols>';

    $xml .= '<sheetData>';

    // Fila 1: grupos
    $xml .= '<row r="1" ht="20" customHeight="1">';
    foreach ($colDefs as $ci => $cd) {
        $xml .= '<c r="'.colLetter($ci+1).'1" t="s" s="1"><v>'.$si($cd[0]).'</v></c>';
    }
    $xml .= '</row>';

    // Fila 2: encabezados
    $xml .= '<row r="2" ht="28" customHeight="1">';
    foreach ($colDefs as $ci => $cd) {
        $xml .= '<c r="'.colLetter($ci+1).'2" t="s" s="1"><v>'.$si($cd[1]).'</v></c>';
    }
    $xml .= '</row>';

    // Filas de datos
    $numFields  = ['e_cantidad','e_costo_u','e_total','s_cantidad','s_costo_u','s_total','saldo_cantidad','saldo_costo_u','saldo_total'];
    $numLetters = ['I','J','K','L','M','N','O','P','Q'];

    foreach ($registros as $ri => $r) {
        $rn    = $ri + 3;
        $fecha = $r['fecha'] ? date('d/m/Y', strtotime($r['fecha'])) : '';

        $xml .= '<row r="'.$rn.'">';
        $xml .= '<c r="A'.$rn.'" s="4"><v>'.($ri+1).'</v></c>';
        $xml .= '<c r="B'.$rn.'" t="s" s="0"><v>'.$si((string)($r['codigo']??'')).'</v></c>';
        $xml .= '<c r="C'.$rn.'" t="s" s="0"><v>'.$si((string)($r['descripcion']??'')).'</v></c>';
        $xml .= '<c r="D'.$rn.'" t="s" s="0"><v>'.$si($fecha).'</v></c>';
        $xml .= '<c r="E'.$rn.'" t="s" s="0"><v>'.$si((string)($r['comprobante_tipo']??'')).'</v></c>';
        $xml .= '<c r="F'.$rn.'" t="s" s="0"><v>'.$si((string)($r['comprobante_serie']??'')).'</v></c>';
        $xml .= '<c r="G'.$rn.'" t="s" s="0"><v>'.$si((string)($r['comprobante_numero']??'')).'</v></c>';
        $xml .= '<c r="H'.$rn.'" t="s" s="0"><v>'.$si((string)($r['tipo_operacion']??'')).'</v></c>';
        foreach ($numFields as $ni => $field) {
            $xml .= '<c r="'.$numLetters[$ni].$rn.'" s="2"><v>'.(float)($r[$field]??0).'</v></c>';
        }
        $xml .= '</row>';
    }

    // Fila totales
    // Saldos: mostrar ÚLTIMO valor (no sumar) — el saldo es acumulativo
    $ultimo = end($registros);
    $tr  = count($registros) + 3;
    $xml .= '<row r="'.$tr.'" ht="18" customHeight="1">';
    $xml .= '<c r="A'.$tr.'" t="s" s="3"><v>'.$si('TOTALES').'</v></c>';
    foreach (['B','C','D','E','F','G','H'] as $tl) {
        $xml .= '<c r="'.$tl.$tr.'" t="s" s="3"><v>'.$si('').'</v></c>';
    }
    // I=E.Cant  J=E.CU  K=E.Total  L=S.Cant  M=S.CU  N=S.Total  O=Saldo.Cant  P=Saldo.CU  Q=Saldo.Total
    $totales = [
        'I' => (float)array_sum(array_column($registros, 'e_cantidad')),   // SUMAR entradas
        'J' => 0.0,
        'K' => (float)array_sum(array_column($registros, 'e_total')),      // SUMAR entradas total
        'L' => (float)array_sum(array_column($registros, 's_cantidad')),   // SUMAR salidas
        'M' => 0.0,
        'N' => (float)array_sum(array_column($registros, 's_total')),      // SUMAR salidas total
        'O' => (float)($ultimo['saldo_cantidad'] ?? 0),                    // ÚLTIMO saldo cantidad
        'P' => (float)($ultimo['saldo_costo_u']  ?? 0),                    // ÚLTIMO saldo costo unit
        'Q' => (float)($ultimo['saldo_total']    ?? 0),                    // ÚLTIMO saldo total
    ];
    foreach ($totales as $tl => $val) {
        $xml .= '<c r="'.$tl.$tr.'" s="3"><v>'.$val.'</v></c>';
    }
    $xml .= '</row>';

    $xml .= '</sheetData></worksheet>';

    // ── Shared Strings XML ─────────────────────────────────
    $sstXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sstXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $sstXml .= ' count="'.count($sstArr).'" uniqueCount="'.count($sstArr).'">';
    foreach ($sstArr as $v) {
        $sstXml .= '<si><t xml:space="preserve">'.xe($v).'</t></si>';
    }
    $sstXml .= '</sst>';

    // ── Archivos estructura ────────────────────────────────
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Kardex" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'</Relationships>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'</Types>';

    // ── Armar ZIP ──────────────────────────────────────────
    $tmpFile = tempnam(sys_get_temp_dir(), 'kxlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        die('Error: no se pudo crear el archivo temporal.');
    }
    $zip->addFromString('[Content_Types].xml',        $ct);
    $zip->addFromString('_rels/.rels',                $rels);
    $zip->addFromString('xl/workbook.xml',            $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml',   $xml);
    $zip->addFromString('xl/sharedStrings.xml',       $sstXml);
    $zip->addFromString('xl/styles.xml',              $stylesXml);
    $zip->close();

    $filename = 'kardex_reporte_' . date('Ymd_His') . '.xlsx';
    $filtroDesc = [];
    if ($search_codigo)    $filtroDesc[] = "código=$search_codigo";
    if ($search_fecha_ini) $filtroDesc[] = "desde=$search_fecha_ini";
    if ($search_fecha_fin) $filtroDesc[] = "hasta=$search_fecha_fin";
    $filtroStr = $filtroDesc ? implode(', ', $filtroDesc) : 'sin filtro';
    registrarLog($pdo, 'EXPORTAR', "Exportó reporte Excel", "$filtroStr · " . count($registros) . " registros", count($registros));

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.filesize($tmpFile));
    header('Cache-Control: max-age=0');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}

// ============================================================
// TOTALES Y RESUMEN PARA VISTA HTML
// ============================================================
$total_e_cant   = array_sum(array_column($registros, 'e_cantidad'));
$total_e_tot    = array_sum(array_column($registros, 'e_total'));
$total_s_cant   = array_sum(array_column($registros, 's_cantidad'));
$total_s_tot    = array_sum(array_column($registros, 's_total'));
$codigos_unicos = count(array_unique(array_column($registros, 'codigo')));

$por_codigo = [];
foreach ($registros as $r) {
    $c = $r['codigo'];
    if (!isset($por_codigo[$c])) {
        $por_codigo[$c] = ['desc'=>$r['descripcion'],'movs'=>0,
            'e_cant'=>0,'e_tot'=>0,'s_cant'=>0,'s_tot'=>0,'saldo_cant'=>0,'saldo_tot'=>0];
    }
    $por_codigo[$c]['movs']++;
    $por_codigo[$c]['e_cant']    += $r['e_cantidad'];
    $por_codigo[$c]['e_tot']     += $r['e_total'];
    $por_codigo[$c]['s_cant']    += $r['s_cantidad'];
    $por_codigo[$c]['s_tot']     += $r['s_total'];
    $por_codigo[$c]['saldo_cant'] = $r['saldo_cantidad'];
    $por_codigo[$c]['saldo_tot']  = $r['saldo_total'];
}

$fecha_hoy = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reporte Kardex — <?= $fecha_hoy ?></title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0c10;--bg2:#111318;--bg3:#181c24;--border:#232731;--border2:#2e3340;--accent:#4f9eff;--accent2:#7b61ff;--accent3:#00e5b0;--danger:#ff4d6a;--warning:#ffb547;--text:#e8eaf0;--text2:#8b92a8;--text3:#555e72;--radius:10px;--radius2:6px;--font-mono:'JetBrains Mono',monospace;--font-main:'Syne',sans-serif;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--font-main);font-size:13px;min-height:100vh;}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 50% at 10% 0%,rgba(79,158,255,.07) 0%,transparent 50%);pointer-events:none;z-index:0;}

.toolbar{position:sticky;top:0;z-index:100;background:rgba(10,12,16,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.toolbar-title{font-size:14px;font-weight:700;}
.toolbar-sub{font-size:11px;color:var(--text3);font-family:var(--font-mono);}
.toolbar-right{display:flex;gap:8px;flex-wrap:wrap;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--radius2);font-family:var(--font-main);font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .15s;white-space:nowrap;}
.btn-success{background:linear-gradient(135deg,var(--accent3),#00b87c);color:#0a0c10;}
.btn-success:hover{transform:translateY(-1px);}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;}
.btn-primary:hover{transform:translateY(-1px);}
.btn-ghost{background:var(--bg3);color:var(--text2);border:1px solid var(--border2);}
.btn-ghost:hover{background:var(--border);color:var(--text);}
.btn-print{background:rgba(255,255,255,.06);color:var(--text2);border:1px solid var(--border2);}
.btn-print:hover{background:rgba(255,255,255,.1);}

.wrapper{position:relative;z-index:1;max-width:1600px;margin:0 auto;padding:24px 20px;}

.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;}
.filter-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.fg{display:flex;flex-direction:column;gap:5px;flex:1;min-width:150px;}
.fg label{font-size:11px;color:var(--text2);}
.fg input{background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius2);color:var(--text);font-family:var(--font-mono);font-size:12px;padding:8px 11px;outline:none;transition:border-color .15s;}
.fg input:focus{border-color:var(--accent);}
.fg input::placeholder{color:var(--text3);}

.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:24px;}
.stat{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;position:relative;overflow:hidden;}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
.stat:nth-child(1)::before{background:linear-gradient(90deg,var(--accent),transparent);}
.stat:nth-child(2)::before{background:linear-gradient(90deg,var(--accent3),transparent);}
.stat:nth-child(3)::before{background:linear-gradient(90deg,var(--danger),transparent);}
.stat:nth-child(4)::before{background:linear-gradient(90deg,var(--warning),transparent);}
.stat-l{font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.stat-v{font-size:20px;font-weight:800;font-family:var(--font-mono);}
.stat-v.blue{color:var(--accent);}.stat-v.green{color:var(--accent3);}.stat-v.red{color:var(--danger);}.stat-v.yellow{color:var(--warning);}

.section-title{font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;font-family:var(--font-mono);}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-bottom:28px;}
.summary-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px;transition:border-color .15s;}
.summary-card:hover{border-color:var(--border2);}
.sc-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.sc-codigo{font-size:13px;font-weight:700;color:var(--accent);font-family:var(--font-mono);}
.sc-movs{font-size:11px;color:var(--text3);font-family:var(--font-mono);}
.sc-desc{font-size:12px;color:var(--text2);margin-bottom:12px;}
.sc-vals{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;}
.sc-val{text-align:center;}
.sc-val-l{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.sc-val-v{font-size:12px;font-weight:700;font-family:var(--font-mono);}
.sc-val-v.green{color:var(--accent3);}.sc-val-v.red{color:var(--danger);}.sc-val-v.yellow{color:var(--warning);}

.table-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;}
.table-head-bar{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;}
.table-head-bar span{font-size:12px;color:var(--text2);}
.tscroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:11.5px;min-width:1100px;}
thead tr{background:var(--bg3);}
th{padding:9px 10px;text-align:left;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text3);border-bottom:1px solid var(--border);white-space:nowrap;}
.th-g{background:var(--bg);text-align:center;font-size:9px;font-weight:800;letter-spacing:1.5px;text-transform:uppercase;border-bottom:1px solid var(--border);padding:7px 10px;}
.th-g.ent{color:var(--accent3);border-top:2px solid var(--accent3);}
.th-g.sal{color:var(--danger);border-top:2px solid var(--danger);}
.th-g.sld{color:var(--warning);border-top:2px solid var(--warning);}
.th-g.cmp{color:var(--accent);border-top:2px solid var(--accent);}
.th-g.bas{color:var(--text3);}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s;}
tbody tr:hover{background:rgba(79,158,255,.04);}
tbody tr:last-child{border-bottom:none;}
td{padding:8px 10px;color:var(--text2);white-space:nowrap;font-family:var(--font-mono);font-size:11.5px;}
.td-cod{color:var(--accent);font-weight:600;}
.td-desc{color:var(--text);font-family:var(--font-main);font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;}
.td-r{text-align:right;}
.td-e{color:var(--accent3);text-align:right;}
.td-s{color:var(--danger);text-align:right;}
.td-b{color:var(--warning);text-align:right;font-weight:600;}
.zero{color:var(--text3)!important;font-weight:400!important;}
tfoot tr{background:var(--bg3);}
tfoot td{padding:10px;font-weight:700;font-size:11.5px;font-family:var(--font-mono);border-top:2px solid var(--border2);}
.tf-label{color:var(--text);font-family:var(--font-main);font-size:12px;}
.badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:9.5px;font-weight:600;font-family:var(--font-main);}
.badge-e{background:rgba(0,229,176,.1);color:var(--accent3);}
.badge-s{background:rgba(255,77,106,.1);color:var(--danger);}
.badge-o{background:rgba(255,181,71,.1);color:var(--warning);}
.empty{text-align:center;padding:50px;color:var(--text3);}
.empty-icon{font-size:40px;margin-bottom:14px;opacity:.4;}
.report-footer{text-align:center;padding:16px;font-size:11px;color:var(--text3);font-family:var(--font-mono);border-top:1px solid var(--border);margin-top:20px;}

@media print{.toolbar,.filter-bar{display:none!important;}body{background:#fff;color:#000;}table{font-size:9px;}.table-wrap{border:1px solid #ccc;}}
@media(max-width:768px){
  .wrapper{padding:16px 12px 80px;}
  .stats{grid-template-columns:1fr 1fr;gap:8px;}
  .summary-grid{grid-template-columns:1fr;}
  .toolbar{flex-direction:column;align-items:flex-start;gap:8px;padding:10px 16px;}
  .toolbar-right{width:100%;flex-wrap:wrap;}
  .toolbar-right .btn{flex:1;justify-content:center;font-size:11px;padding:7px 10px;}
  .filter-row{flex-direction:column;}
  .tscroll{overflow-x:auto;}
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

<div class="toolbar">
  <div>
    <div class="toolbar-title">📄 Reporte Kardex</div>
    <div class="toolbar-sub">Generado: <?= $fecha_hoy ?> · <?= number_format(count($registros)) ?> registros</div>
  </div>
  <div class="toolbar-right">
    <a href="<?= '?'.http_build_query(array_merge($_GET,['export'=>'excel'])) ?>" class="btn btn-success">⬇ Exportar Excel (.xlsx)</a>
    <button onclick="window.print()" class="btn btn-print">🖨 Imprimir</button>
    <a href="index.php" class="btn btn-ghost">← Volver</a>
  </div>
</div>

<div class="wrapper">

  <div class="filter-bar">
    <form method="GET">
      <div class="filter-row">
        <div class="fg"><label>Código</label><input type="text" name="codigo" value="<?= htmlspecialchars($search_codigo) ?>" placeholder="Todos los códigos"></div>
        <div class="fg"><label>Fecha desde</label><input type="date" name="fecha_ini" value="<?= htmlspecialchars($search_fecha_ini) ?>"></div>
        <div class="fg"><label>Fecha hasta</label><input type="date" name="fecha_fin" value="<?= htmlspecialchars($search_fecha_fin) ?>"></div>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button type="submit" class="btn btn-primary">Filtrar</button>
          <a href="reporte.php" class="btn btn-ghost">Limpiar</a>
        </div>
      </div>
    </form>
  </div>

  <div class="stats">
    <div class="stat"><div class="stat-l">Registros</div><div class="stat-v blue"><?= number_format(count($registros)) ?></div></div>
    <div class="stat"><div class="stat-l">Total Entradas</div><div class="stat-v green"><?= number_format($total_e_cant,3) ?></div></div>
    <div class="stat"><div class="stat-l">Total Salidas</div><div class="stat-v red"><?= number_format($total_s_cant,3) ?></div></div>
    <div class="stat"><div class="stat-l">Productos</div><div class="stat-v yellow"><?= $codigos_unicos ?></div></div>
  </div>

  <?php if (!empty($por_codigo)): ?>
  <div class="section-title">📊 Resumen por producto</div>
  <div class="summary-grid">
    <?php foreach ($por_codigo as $cod => $dat): ?>
    <div class="summary-card">
      <div class="sc-header">
        <div class="sc-codigo"><?= htmlspecialchars($cod) ?></div>
        <div class="sc-movs"><?= $dat['movs'] ?> movs.</div>
      </div>
      <div class="sc-desc"><?= htmlspecialchars(mb_substr($dat['desc'],0,60)) ?></div>
      <div class="sc-vals">
        <div class="sc-val"><div class="sc-val-l">Entradas</div><div class="sc-val-v green"><?= number_format($dat['e_cant'],3) ?></div></div>
        <div class="sc-val"><div class="sc-val-l">Salidas</div><div class="sc-val-v red"><?= number_format($dat['s_cant'],3) ?></div></div>
        <div class="sc-val"><div class="sc-val-l">Saldo</div><div class="sc-val-v yellow"><?= number_format($dat['saldo_cant'],3) ?></div></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
    <div class="table-head-bar">
      <span>Detalle completo de movimientos</span>
      <span style="color:var(--accent);font-family:var(--font-mono)">
        <?= number_format(count($registros)) ?> registros
        <?php if ($search_codigo || $search_fecha_ini || $search_fecha_fin): ?>
          · <span style="color:var(--warning)">filtrado</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="tscroll">
      <table>
        <thead>
          <tr>
            <th class="th-g bas" rowspan="2" style="vertical-align:middle">#</th>
            <th class="th-g bas" rowspan="2" style="vertical-align:middle">Código</th>
            <th class="th-g bas" rowspan="2" style="vertical-align:middle">Descripción</th>
            <th class="th-g bas" rowspan="2" style="vertical-align:middle">Fecha</th>
            <th class="th-g cmp" colspan="3">Comprobante</th>
            <th class="th-g bas" rowspan="2" style="vertical-align:middle">Tipo Op.</th>
            <th class="th-g ent" colspan="3">Entradas</th>
            <th class="th-g sal" colspan="3">Salidas</th>
            <th class="th-g sld" colspan="3">Saldo Final</th>
          </tr>
          <tr>
            <th>Tipo</th><th>Serie</th><th>Número</th>
            <th>Cant.</th><th>C.Unit.</th><th>Total</th>
            <th>Cant.</th><th>C.Unit.</th><th>Total</th>
            <th>Cant.</th><th>C.Unit.</th><th>Total</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
          <tr><td colspan="17"><div class="empty"><div class="empty-icon">🔍</div>Sin resultados.</div></td></tr>
        <?php else: ?>
          <?php foreach ($registros as $i => $r):
            $fecha = $r['fecha'] ? date('d/m/Y', strtotime($r['fecha'])) : '—';
            $op    = strtolower($r['tipo_operacion'] ?? '');
            $bcls  = str_contains($op,'venta')||str_contains($op,'salida') ? 'badge-s'
                   : (str_contains($op,'entrada')||str_contains($op,'compra') ? 'badge-e' : 'badge-o');
          ?>
          <tr>
            <td style="color:var(--text3)"><?= $i+1 ?></td>
            <td class="td-cod"><?= htmlspecialchars($r['codigo']) ?></td>
            <td class="td-desc" title="<?= htmlspecialchars($r['descripcion']) ?>"><?= htmlspecialchars($r['descripcion']) ?></td>
            <td><?= $fecha ?></td>
            <td><?= htmlspecialchars($r['comprobante_tipo']   ?? '—') ?></td>
            <td><?= htmlspecialchars($r['comprobante_serie']  ?? '—') ?></td>
            <td class="td-r"><?= htmlspecialchars($r['comprobante_numero'] ?? '—') ?></td>
            <td><span class="badge <?= $bcls ?>"><?= htmlspecialchars($r['tipo_operacion'] ?? '—') ?></span></td>
            <td class="td-e <?= $r['e_cantidad']==0?'zero':'' ?>"><?= number_format($r['e_cantidad'],3) ?></td>
            <td class="td-e <?= $r['e_costo_u']==0?'zero':'' ?>"><?= number_format($r['e_costo_u'],4) ?></td>
            <td class="td-e <?= $r['e_total']==0?'zero':'' ?>"><?= number_format($r['e_total'],3) ?></td>
            <td class="td-s <?= $r['s_cantidad']==0?'zero':'' ?>"><?= number_format($r['s_cantidad'],3) ?></td>
            <td class="td-s <?= $r['s_costo_u']==0?'zero':'' ?>"><?= number_format($r['s_costo_u'],4) ?></td>
            <td class="td-s <?= $r['s_total']==0?'zero':'' ?>"><?= number_format($r['s_total'],3) ?></td>
            <td class="td-b"><?= number_format($r['saldo_cantidad'],3) ?></td>
            <td class="td-b"><?= number_format($r['saldo_costo_u'],4) ?></td>
            <td class="td-b"><?= number_format($r['saldo_total'],3) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        <?php if (!empty($registros)): ?>
        <?php $ultimo_reg = end($registros); ?>
        <tfoot>
          <tr>
            <td colspan="8" class="tf-label" style="text-align:right;padding-right:16px">TOTALES →</td>
            <td class="td-e" style="font-weight:700"><?= number_format($total_e_cant,3) ?></td>
            <td></td>
            <td class="td-e" style="font-weight:700"><?= number_format($total_e_tot,3) ?></td>
            <td class="td-s" style="font-weight:700"><?= number_format($total_s_cant,3) ?></td>
            <td></td>
            <td class="td-s" style="font-weight:700"><?= number_format($total_s_tot,3) ?></td>
            <td class="td-b" style="font-weight:700"><?= number_format($ultimo_reg['saldo_cantidad'],3) ?></td>
            <td class="td-b" style="font-weight:700"><?= number_format($ultimo_reg['saldo_costo_u'],4) ?></td>
            <td class="td-b" style="font-weight:700"><?= number_format($ultimo_reg['saldo_total'],3) ?></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="report-footer">
    Kardex · <?= htmlspecialchars($dbname) ?> · <?= $fecha_hoy ?>
    <?php if ($search_codigo||$search_fecha_ini||$search_fecha_fin): ?>
      · Filtros:
      <?= $search_codigo    ? "código='{$search_codigo}' " : '' ?>
      <?= $search_fecha_ini ? "desde {$search_fecha_ini} " : '' ?>
      <?= $search_fecha_fin ? "hasta {$search_fecha_fin}"  : '' ?>
    <?php endif; ?>
  </div>
</div>
<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php" class="nav-item"><span class="nav-icon">📦</span><span class="nav-label">Kardex</span></a>
    <a href="importar.php" class="nav-item"><span class="nav-icon">⬆</span><span class="nav-label">Importar</span></a>
    <a href="reporte.php" class="nav-item active"><span class="nav-icon">📄</span><span class="nav-label">Reporte</span></a>
    <a href="log.php" class="nav-item"><span class="nav-icon">📋</span><span class="nav-label">Log</span></a>
  </div>
</nav>
</body>
</html>