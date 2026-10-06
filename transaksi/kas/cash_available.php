<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors','0');
error_reporting(E_ALL);
if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Akses ditolak.']); exit; }
require_once '../../config/database.php';

function cashAvailableEnsureColumns(PDO $pdo): void {
    
function cashAvailFromNominal($nominal): array {
    $sisa = (int)round((float)$nominal);
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        if ($sisa <= 0) break;
        $qty = intdiv($sisa, $p);
        if ($qty > 0) { $out[(string)$p] = $qty; $sisa -= $qty * $p; }
    }
    return $out;
}
function cashAvailKeluarDariStok($nominal, array $stock): array {
    $sisa = (int)round((float)$nominal);
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        if ($sisa <= 0) break;
        $ada = max(0, (int)($stock[(string)$p] ?? 0));
        if ($ada <= 0) continue;
        $butuh = intdiv($sisa, $p);
        $qty = min($ada, $butuh);
        if ($qty > 0) { $out[(string)$p] = $qty; $sisa -= $qty * $p; }
    }
    if ($sisa > 0) {
        foreach (cashAvailFromNominal($sisa) as $nom=>$qty) {
            $out[$nom] = (int)($out[$nom] ?? 0) + (int)$qty;
        }
    }
    return $out;
}
try {
        $col = $pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'opening_pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN opening_pecahan_json TEXT NULL AFTER opening_cash");
    } catch (Throwable $e) {}
    try {
        $col = $pdo->query("SHOW COLUMNS FROM cash_movements LIKE 'pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) $pdo->exec("ALTER TABLE cash_movements ADD COLUMN pecahan_json TEXT NULL AFTER nominal");
    } catch (Throwable $e) {}
}
function cashAvailParse($json): array {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    $out=[];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}
try {
    cashAvailableEnsureColumns($pdo);
    $st = $pdo->query("SELECT * FROM cash_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1");
    $session = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$session) { echo json_encode(['status'=>'success','open'=>false,'pecahan'=>[],'message'=>'Kas belum dibuka.']); exit; }
    $pecahan = cashAvailParse($session['opening_pecahan_json'] ?? '');
    $sid = (int)$session['id'];
    $st = $pdo->prepare("SELECT id, jenis, nominal, pecahan_json FROM cash_movements WHERE session_id=? ORDER BY id ASC");
    $st->execute([$sid]);
    $upd = $pdo->prepare("UPDATE cash_movements SET pecahan_json=? WHERE id=? AND (pecahan_json IS NULL OR pecahan_json='')");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $jenis = (string)($m['jenis'] ?? '');
        $obj = cashAvailParse($m['pecahan_json'] ?? '');
        if (!$obj && (float)($m['nominal'] ?? 0) > 0) {
            // Kunci pecahan hasil deteksi pertama kali agar data realtime sama dengan log mutasi.
            $obj = ($jenis === 'masuk')
                ? cashAvailFromNominal((float)$m['nominal'])
                : cashAvailKeluarDariStok((float)$m['nominal'], $pecahan);
            if ($obj) {
                try { $upd->execute([json_encode($obj, JSON_UNESCAPED_UNICODE), (int)$m['id']]); } catch(Throwable $e) {}
            }
        }
        foreach ($obj as $nom=>$qty) {
            if (!isset($pecahan[$nom])) $pecahan[$nom] = 0;
            if ($jenis === 'masuk') $pecahan[$nom] += $qty;
            else $pecahan[$nom] -= $qty;
            if ($pecahan[$nom] <= 0) unset($pecahan[$nom]);
        }
    }
    krsort($pecahan, SORT_NUMERIC);
    echo json_encode(['status'=>'success','open'=>true,'pecahan'=>$pecahan,'session_id'=>$sid]);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
