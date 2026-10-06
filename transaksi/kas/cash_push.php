<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors','0');
error_reporting(E_ALL);
if (!isset($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Akses ditolak.']); exit; }
require_once '../../config/database.php';

function cashPushEnsureTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_date DATE NOT NULL,
        shift_label VARCHAR(50) NULL,
        kode_user VARCHAR(50) NOT NULL,
        opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        opening_pecahan_json TEXT NULL,
        closing_cash DECIMAL(15,2) NULL,
        expected_cash DECIMAL(15,2) NULL,
        difference_cash DECIMAL(15,2) NULL,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        note TEXT NULL,
        opened_at DATETIME NOT NULL,
        closed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cash_status_date (status, session_date),
        INDEX idx_cash_opened (opened_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $col = $pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'opening_pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN opening_pecahan_json TEXT NULL AFTER opening_cash");
    } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        tanggal DATE NOT NULL,
        waktu TIME NOT NULL,
        jenis ENUM('masuk','keluar') NOT NULL,
        sumber VARCHAR(50) NOT NULL DEFAULT 'manual',
        keterangan VARCHAR(255) NULL,
        nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
        pecahan_json TEXT NULL,
        kode_user VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cash_mov_session (session_id),
        INDEX idx_cash_mov_date (tanggal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $col = $pdo->query("SHOW COLUMNS FROM cash_movements LIKE 'pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) $pdo->exec("ALTER TABLE cash_movements ADD COLUMN pecahan_json TEXT NULL AFTER nominal");
    } catch (Throwable $e) {}
}
function cashPushDate($raw): string {
    $raw = trim((string)$raw);
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    $err = DateTime::getLastErrors();
    if (!$dt || ($err && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0))) return date('Y-m-d');
    return $dt->format('Y-m-d');
}

function cashPushParsePecahan($raw): array {
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return [];
    $allowed = [100000,50000,20000,10000,5000,2000,1000,500,200,100];
    $out = [];
    foreach ($allowed as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}

function cashPushPecahanFromNominal($nominal): array {
    $sisa = (int)round((float)$nominal);
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        if ($sisa <= 0) break;
        $qty = intdiv($sisa, $p);
        if ($qty > 0) { $out[(string)$p] = $qty; $sisa -= $qty * $p; }
    }
    return $out;
}
function cashPushPecahanKeluarDariStok($nominal, array $stock): array {
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
        foreach (cashPushPecahanFromNominal($sisa) as $nom=>$qty) {
            $out[$nom] = (int)($out[$nom] ?? 0) + (int)$qty;
        }
    }
    return $out;
}
function cashPushStockNow(PDO $pdo, array $session): array {
    $stock = cashPushParsePecahan($session['opening_pecahan_json'] ?? '');
    $sid = (int)$session['id'];
    $st = $pdo->prepare("SELECT id, jenis, nominal, pecahan_json FROM cash_movements WHERE session_id=? ORDER BY id ASC");
    $st->execute([$sid]);
    $upd = $pdo->prepare("UPDATE cash_movements SET pecahan_json=? WHERE id=? AND (pecahan_json IS NULL OR pecahan_json='')");
    while ($m = $st->fetch(PDO::FETCH_ASSOC)) {
        $jenis = (string)($m['jenis'] ?? '');
        $items = cashPushParsePecahan($m['pecahan_json'] ?? '');
        if (!$items && (float)($m['nominal'] ?? 0) > 0) {
            $items = ($jenis === 'masuk') ? cashPushPecahanFromNominal((float)$m['nominal']) : cashPushPecahanKeluarDariStok((float)$m['nominal'], $stock);
            if ($items) {
                try { $upd->execute([json_encode($items, JSON_UNESCAPED_UNICODE), (int)$m['id']]); } catch(Throwable $e) {}
            }
        }
        foreach ($items as $nom=>$qty) {
            if (!isset($stock[$nom])) $stock[$nom] = 0;
            if ($jenis === 'masuk') $stock[$nom] += (int)$qty; else $stock[$nom] -= (int)$qty;
            if ($stock[$nom] <= 0) unset($stock[$nom]);
        }
    }
    return $stock;
}
function cashPushPecahanText(array $pecahan): string {
    $parts = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = (int)($pecahan[(string)$p] ?? 0);
        if ($qty > 0) $parts[] = $qty . 'x Rp ' . number_format($p, 0, ',', '.');
    }
    return implode(', ', $parts);
}
try {
    cashPushEnsureTables($pdo);
    $no = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['no_penjualan'] ?? ''));
    $tanggal = cashPushDate($_POST['tanggal'] ?? date('Y-m-d'));
    $uang = max(0, (float)($_POST['uang_diterima'] ?? 0));
    $kembali = max(0, (float)($_POST['kembalian'] ?? 0));
    $pecahanDiterima = cashPushParsePecahan($_POST['pecahan_diterima'] ?? '');
    $pecahanKembalian = cashPushParsePecahan($_POST['pecahan_kembalian'] ?? '');
    if ($no === '' || $uang <= 0) { echo json_encode(['status'=>'success','saved'=>false,'message'=>'Tidak ada cash yang dicatat.']); exit; }

    $st = $pdo->query("SELECT * FROM cash_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1");
    $session = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$session) { echo json_encode(['status'=>'success','saved'=>false,'message'=>'Kas belum dibuka.']); exit; }
    $sid = (int)$session['id'];

    // Jika kasir tidak mengirim detail pecahan, tetap bentuk pecahan otomatis dari nominal
    // agar stok pecahan realtime tetap bergerak.
    if (!$pecahanDiterima && $uang > 0) {
        $pecahanDiterima = cashPushPecahanFromNominal($uang);
    }
    if (!$pecahanKembalian && $kembali > 0) {
        $stockNow = cashPushStockNow($pdo, $session);
        foreach ($pecahanDiterima as $nom=>$qty) {
            $stockNow[$nom] = (int)($stockNow[$nom] ?? 0) + (int)$qty;
        }
        $pecahanKembalian = cashPushPecahanKeluarDariStok($kembali, $stockNow);
    }

    // Hindari dobel jika user menekan simpan/fetch ulang.
    $cek = $pdo->prepare("SELECT COUNT(*) FROM cash_movements WHERE session_id=? AND sumber='transaksi_cash_diterima' AND keterangan LIKE ?");
    $ketMasuk = 'Cash diterima ' . $no;
    $cek->execute([$sid, $ketMasuk . '%']);
    if ((int)$cek->fetchColumn() > 0) { echo json_encode(['status'=>'success','saved'=>false,'message'=>'Cash transaksi sudah pernah dicatat.']); exit; }

    $ins = $pdo->prepare("INSERT INTO cash_movements (session_id,tanggal,waktu,jenis,sumber,keterangan,nominal,pecahan_json,kode_user) VALUES (?,?,?,?,?,?,?,?,?)");
    $kode_user = (string)($_SESSION['user_id'] ?? '');
    $ketMasukFull = $ketMasuk;
    $txtMasuk = cashPushPecahanText($pecahanDiterima);
    if ($txtMasuk !== '') $ketMasukFull .= ' | ' . $txtMasuk;
    $ins->execute([$sid, $tanggal, date('H:i:s'), 'masuk', 'transaksi_cash_diterima', $ketMasukFull, $uang, json_encode($pecahanDiterima, JSON_UNESCAPED_UNICODE), $kode_user]);
    if ($kembali > 0) {
        $ketKeluar = 'Kembalian ' . $no;
        $txtKeluar = cashPushPecahanText($pecahanKembalian);
        if ($txtKeluar !== '') $ketKeluar .= ' | ' . $txtKeluar;
        $ins->execute([$sid, $tanggal, date('H:i:s'), 'keluar', 'kembalian_cash', $ketKeluar, $kembali, json_encode($pecahanKembalian, JSON_UNESCAPED_UNICODE), $kode_user]);
    }
    echo json_encode(['status'=>'success','saved'=>true,'message'=>'Cash transaksi dicatat.']);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
