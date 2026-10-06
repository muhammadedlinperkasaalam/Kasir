<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../auth/login.php'); exit; }
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

function rp_amp($v){ return 'Rp ' . number_format((float)$v,0,',','.'); }
function pecahan_array_amp($json): array {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    $out=[];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}
function pecahan_total_amp(array $arr): float {
    $total=0;
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) $total += $p * (int)($arr[(string)$p] ?? 0);
    return (float)$total;
}
function pecahan_text_amp($json): string {
    $arr = is_array($json) ? $json : pecahan_array_amp($json);
    $parts=[];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty=(int)($arr[(string)$p] ?? 0);
        if($qty>0) $parts[] = $qty.'x'.number_format($p,0,',','.');
    }
    return $parts ? implode(', ', $parts) : '-';
}
function ensure_amp(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'opening_pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN opening_pecahan_json TEXT NULL AFTER opening_cash");
        $col2 = $pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'closing_pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col2) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN closing_pecahan_json TEXT NULL AFTER closing_cash");
    } catch (Throwable $e) {}
    try {
        $col = $pdo->query("SHOW COLUMNS FROM cash_movements LIKE 'pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) $pdo->exec("ALTER TABLE cash_movements ADD COLUMN pecahan_json TEXT NULL AFTER nominal");
    } catch (Throwable $e) {}
}
function stock_pecahan_amp(PDO $pdo, array $session): array {
    $stock = pecahan_array_amp($session['opening_pecahan_json'] ?? '');
    $sid=(int)$session['id'];
    $st=$pdo->prepare("SELECT jenis, pecahan_json FROM cash_movements WHERE session_id=? AND pecahan_json IS NOT NULL AND pecahan_json<>''");
    $st->execute([$sid]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $m){
        $obj=pecahan_array_amp($m['pecahan_json'] ?? '');
        foreach($obj as $nom=>$qty){
            if(!isset($stock[$nom])) $stock[$nom]=0;
            if(($m['jenis'] ?? '')==='masuk') $stock[$nom]+=$qty; else $stock[$nom]-=$qty;
            if($stock[$nom]<=0) unset($stock[$nom]);
        }
    }
    krsort($stock,SORT_NUMERIC);
    return $stock;
}
function summary_amp(PDO $pdo, array $session): array {
    $sid=(int)$session['id'];
    $st=$pdo->prepare("SELECT jenis, sumber, COALESCE(SUM(nominal),0) total FROM cash_movements WHERE session_id=? GROUP BY jenis,sumber");
    $st->execute([$sid]);
    $cashIn=$cashOut=$manualIn=$manualOut=0;
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        if($r['sumber']==='transaksi_cash_diterima') $cashIn += (float)$r['total'];
        elseif($r['sumber']==='kembalian_cash') $cashOut += (float)$r['total'];
        elseif($r['jenis']==='masuk') $manualIn += (float)$r['total'];
        elseif($r['jenis']==='keluar') $manualOut += (float)$r['total'];
    }
    $opening=(float)$session['opening_cash'];
    $expected=$opening+$cashIn+$manualIn-$cashOut-$manualOut;
    return compact('opening','cashIn','cashOut','manualIn','manualOut','expected');
}
ensure_amp($pdo);
$id=(int)($_GET['id'] ?? 0);
if($id>0){
    $st=$pdo->prepare("SELECT * FROM cash_sessions WHERE id=? LIMIT 1"); $st->execute([$id]);
} else {
    $st=$pdo->query("SELECT * FROM cash_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1");
}
$session=$st ? $st->fetch(PDO::FETCH_ASSOC) : false;
if(!$session){ echo 'Sesi kas tidak ditemukan.'; exit; }
$summary=summary_amp($pdo,$session);
$stock=stock_pecahan_amp($pdo,$session);
$mov=$pdo->prepare("SELECT * FROM cash_movements WHERE session_id=? ORDER BY created_at ASC, id ASC");
$mov->execute([(int)$session['id']]);
$movs=$mov->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><title>Cetak Amplop Cash</title>
<style>
@page{size:100mm 150mm;margin:5mm}body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111}.wrap{width:100%}.center{text-align:center}.title{font-size:15px;font-weight:800}.muted{color:#666}.line{border-top:1px dashed #333;margin:6px 0}.row{display:flex;justify-content:space-between;gap:8px;margin:2px 0}.bold{font-weight:800}.box{border:1px solid #222;border-radius:6px;padding:5px;margin:5px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:2px 8px}.small{font-size:10px}.table{width:100%;border-collapse:collapse}.table th,.table td{border-bottom:1px solid #ddd;padding:2px 0;vertical-align:top}.right{text-align:right}.sign{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}.sign div{text-align:center}.sign-space{height:32px}.no-print{margin-bottom:8px}@media print{.no-print{display:none}}
</style></head><body>
<div class="no-print"><button onclick="window.print()">Print</button> <button onclick="window.close()">Tutup</button></div>
<div class="wrap">
    <div class="center"><div class="title">AMPLOP CASH</div><div class="muted">Addinta Printing</div></div>
    <div class="line"></div>
    <div class="row"><span>Tanggal</span><b><?= htmlspecialchars($session['session_date']) ?></b></div>
    <div class="row"><span>Shift</span><b><?= htmlspecialchars($session['shift_label'] ?: '-') ?></b></div>
    <div class="row"><span>Dibuka</span><b><?= htmlspecialchars($session['opened_at']) ?></b></div>
    <div class="row"><span>Status</span><b><?= strtoupper(htmlspecialchars($session['status'])) ?></b></div>

    <div class="box">
        <div class="bold">RINGKASAN NOMINAL</div>
        <div class="row"><span>Cash Awal</span><b><?= rp_amp($summary['opening']) ?></b></div>
        <div class="row"><span>Cash Masuk Transaksi</span><b><?= rp_amp($summary['cashIn']) ?></b></div>
        <div class="row"><span>Cash Keluar/Kembalian</span><b><?= rp_amp($summary['cashOut']) ?></b></div>
        <div class="row"><span>Manual Masuk</span><b><?= rp_amp($summary['manualIn']) ?></b></div>
        <div class="row"><span>Manual Keluar</span><b><?= rp_amp($summary['manualOut']) ?></b></div>
        <div class="line"></div>
        <div class="row bold"><span>Perkiraan Cash</span><span><?= rp_amp($summary['expected']) ?></span></div>
        <?php if($session['status']==='closed'): ?>
        <div class="row"><span>Cash Tutup</span><b><?= rp_amp($session['closing_cash']) ?></b></div>
        <div class="row"><span>Selisih</span><b><?= rp_amp($session['difference_cash']) ?></b></div>
        <?php endif; ?>
    </div>

    <div class="box">
        <div class="bold">DETAIL PECAHAN SAAT INI</div>
        <div class="grid">
        <?php foreach([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $pec): $qty=(int)($stock[(string)$pec] ?? 0); ?>
            <div>Rp <?= number_format($pec,0,',','.') ?></div><div class="right bold">x<?= $qty ?></div>
        <?php endforeach; ?>
        </div>
        <div class="line"></div>
        <div class="row bold"><span>Total Pecahan</span><span><?= rp_amp(pecahan_total_amp($stock)) ?></span></div>
        <div class="small muted">Cash awal: <?= htmlspecialchars(pecahan_text_amp($session['opening_pecahan_json'] ?? '')) ?></div>
        <?php if(!empty($session['closing_pecahan_json'])): ?><div class="small muted">Cash tutup: <?= htmlspecialchars(pecahan_text_amp($session['closing_pecahan_json'])) ?></div><?php endif; ?>
    </div>

    <div class="box">
        <div class="bold">LOG MUTASI DETAIL</div>
        <table class="table small"><thead><tr><th>Jam</th><th>Ket</th><th class="right">Nominal</th></tr></thead><tbody>
        <?php foreach($movs as $m): ?>
        <tr><td><?= htmlspecialchars(substr($m['waktu'],0,5)) ?></td><td><?= htmlspecialchars($m['keterangan'] ?: $m['sumber']) ?><br><span class="muted"><?= htmlspecialchars(pecahan_text_amp($m['pecahan_json'] ?? '')) ?></span></td><td class="right <?= $m['jenis']==='masuk'?'':'muted' ?>"><?= $m['jenis']==='masuk'?'+':'-' ?><?= rp_amp($m['nominal']) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$movs): ?><tr><td colspan="3" class="center muted">Belum ada mutasi</td></tr><?php endif; ?>
        </tbody></table>
    </div>

    <div class="sign"><div><div>Kasir</div><div class="sign-space"></div><div>( __________ )</div></div><div><div>Checker</div><div class="sign-space"></div><div>( __________ )</div></div></div>
</div>
<script>window.onload=function(){ setTimeout(function(){ window.print(); },300); };</script>
</body></html>
