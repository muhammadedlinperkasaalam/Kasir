<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Belum login']);
    exit;
}
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

$pecahanOrder = [100000,50000,20000,10000,5000,2000,1000,500,200,100];

function cash_stock_clean_json($json, $pecahanOrder){
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ($pecahanOrder as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}

function cash_stock_from_nominal($nominal, $pecahanOrder){
    $sisa = (int)round((float)$nominal);
    $out = [];
    foreach ($pecahanOrder as $p) {
        if ($sisa <= 0) break;
        $qty = intdiv($sisa, $p);
        if ($qty > 0) { $out[(string)$p] = $qty; $sisa -= $qty * $p; }
    }
    return $out;
}
function cash_stock_keluar_dari_stok($nominal, $stock, $pecahanOrder){
    $sisa = (int)round((float)$nominal);
    $out = [];
    foreach ($pecahanOrder as $p) {
        if ($sisa <= 0) break;
        $ada = max(0, (int)($stock[(string)$p] ?? 0));
        if ($ada <= 0) continue;
        $butuh = intdiv($sisa, $p);
        $qty = min($ada, $butuh);
        if ($qty > 0) { $out[(string)$p] = $qty; $sisa -= $qty * $p; }
    }
    if ($sisa > 0) {
        foreach (cash_stock_from_nominal($sisa, $pecahanOrder) as $nom=>$qty) {
            $out[$nom] = (int)($out[$nom] ?? 0) + (int)$qty;
        }
    }
    return $out;
}
function cash_stock_total($stock, $pecahanOrder){
    $total = 0;
    foreach ($pecahanOrder as $p) $total += $p * (int)($stock[(string)$p] ?? 0);
    return $total;
}

try {
    $session = $pdo->query("SELECT * FROM cash_sessions WHERE status='open' ORDER BY opened_at DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        echo json_encode([
            'success'=>true,
            'has_session'=>false,
            'message'=>'Belum ada sesi kas aktif',
            'stock'=>[],
            'total'=>0,
            'pecahan_order'=>$pecahanOrder,
            'updated_at'=>date('H:i:s')
        ]);
        exit;
    }

    $stock = cash_stock_clean_json($session['opening_pecahan_json'] ?? '', $pecahanOrder);
    $sid = (int)$session['id'];

    $stmt = $pdo->prepare("SELECT id, jenis, nominal, pecahan_json FROM cash_movements WHERE session_id=? ORDER BY id ASC");
    $stmt->execute([$sid]);
    $upd = $pdo->prepare("UPDATE cash_movements SET pecahan_json=? WHERE id=? AND (pecahan_json IS NULL OR pecahan_json='')");
    while ($m = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $jenis = (string)($m['jenis'] ?? '');
        $items = cash_stock_clean_json($m['pecahan_json'] ?? '', $pecahanOrder);
        if (!$items && (float)($m['nominal'] ?? 0) > 0) {
            // Simpan pecahan hasil deteksi pertama kali agar stok tidak berubah di refresh berikutnya.
            $items = ($jenis === 'masuk')
                ? cash_stock_from_nominal((float)$m['nominal'], $pecahanOrder)
                : cash_stock_keluar_dari_stok((float)$m['nominal'], $stock, $pecahanOrder);
            if ($items) {
                try { $upd->execute([json_encode($items, JSON_UNESCAPED_UNICODE), (int)$m['id']]); } catch(Throwable $e) {}
            }
        }
        foreach ($items as $nom => $qty) {
            if (!isset($stock[$nom])) $stock[$nom] = 0;
            if ($jenis === 'masuk') $stock[$nom] += (int)$qty;
            else $stock[$nom] -= (int)$qty;
            if ($stock[$nom] <= 0) unset($stock[$nom]);
        }
    }

    // Pastikan semua pecahan ada di response agar grid tidak berubah posisi.
    $fullStock = [];
    foreach ($pecahanOrder as $p) $fullStock[(string)$p] = max(0, (int)($stock[(string)$p] ?? 0));

    echo json_encode([
        'success'=>true,
        'has_session'=>true,
        'session_id'=>$sid,
        'stock'=>$fullStock,
        'total'=>cash_stock_total($fullStock, $pecahanOrder),
        'pecahan_order'=>$pecahanOrder,
        'updated_at'=>date('H:i:s')
    ]);
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage(),'updated_at'=>date('H:i:s')]);
}
