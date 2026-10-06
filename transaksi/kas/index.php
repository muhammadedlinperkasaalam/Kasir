<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../auth/login.php'); exit; }
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

function rupiah_cash($v){ return 'Rp ' . number_format((float)$v,0,',','.'); }
function cash_post($key,$default=''){ return $_POST[$key] ?? $default; }
function cash_denom_total(array $denomInput): float {
    $pecahan = [100000,50000,20000,10000,5000,2000,1000,500,200,100];
    $total = 0;
    foreach ($pecahan as $p) {
        $qty = isset($denomInput[$p]) ? (int)$denomInput[$p] : 0;
        if ($qty > 0) $total += $p * $qty;
    }
    return (float)$total;
}
function cash_denom_clean(array $denomInput): array {
    $pecahan = [100000,50000,20000,10000,5000,2000,1000,500,200,100];
    $out = [];
    foreach ($pecahan as $p) {
        $qty = isset($denomInput[$p]) ? (int)$denomInput[$p] : 0;
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}


function cash_pecahan_text($json): string {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return '';
    $parts=[];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $parts[] = $qty.'× Rp '.number_format($p,0,',','.');
    }
    return implode(' • ', $parts);
}

function cash_pecahan_array($json): array {
    $data = json_decode((string)$json, true);
    if (!is_array($data)) return [];
    $out=[];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}

function cash_pecahan_from_nominal(float $nominal): array {
    $sisa = (int)round($nominal);
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        if ($sisa <= 0) break;
        $qty = intdiv($sisa, $p);
        if ($qty > 0) {
            $out[(string)$p] = $qty;
            $sisa -= $qty * $p;
        }
    }
    return $out;
}
function cash_pecahan_keluar_dari_stok(float $nominal, array $stock): array {
    $sisa = (int)round($nominal);
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        if ($sisa <= 0) break;
        $ada = max(0, (int)($stock[(string)$p] ?? 0));
        if ($ada <= 0) continue;
        $butuh = intdiv($sisa, $p);
        $qty = min($ada, $butuh);
        if ($qty > 0) {
            $out[(string)$p] = $qty;
            $sisa -= $qty * $p;
        }
    }
    if ($sisa > 0) {
        // Fallback agar nominal keluar tetap memengaruhi stok walaupun pecahan tidak cukup/tercatat.
        foreach (cash_pecahan_from_nominal($sisa) as $nom => $qty) {
            $out[$nom] = (int)($out[$nom] ?? 0) + (int)$qty;
        }
    }
    return $out;
}
function cash_pecahan_total_array(array $arr): float {
    $total=0;
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $total += $p * (int)($arr[(string)$p] ?? 0);
    }
    return (float)$total;
}
function cash_pecahan_stock(PDO $pdo, array $session): array {
    $stock = cash_pecahan_array($session['opening_pecahan_json'] ?? '');
    $sid=(int)$session['id'];
    try{
        $st=$pdo->prepare("SELECT id, jenis, nominal, pecahan_json FROM cash_movements WHERE session_id=? ORDER BY id ASC");
        $st->execute([$sid]);
        $up=$pdo->prepare("UPDATE cash_movements SET pecahan_json=? WHERE id=? AND (pecahan_json IS NULL OR pecahan_json='')");
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $m){
            $jenis = (string)($m['jenis'] ?? '');
            $obj = cash_pecahan_array($m['pecahan_json'] ?? '');
            if (!$obj && (float)($m['nominal'] ?? 0) > 0) {
                // Kunci rincian pecahan yang belum punya data agar tidak berubah-ubah setiap refresh.
                // Cash masuk dibuat dari nominal yang diterima; cash keluar diambil dari stok saat itu.
                $obj = ($jenis === 'masuk')
                    ? cash_pecahan_from_nominal((float)$m['nominal'])
                    : cash_pecahan_keluar_dari_stok((float)$m['nominal'], $stock);
                if ($obj) {
                    try { $up->execute([json_encode($obj, JSON_UNESCAPED_UNICODE), (int)$m['id']]); } catch(Throwable $e) {}
                }
            }
            foreach($obj as $nom=>$qty){
                if(!isset($stock[$nom])) $stock[$nom]=0;
                if($jenis==='masuk') $stock[$nom]+=$qty; else $stock[$nom]-=$qty;
                if($stock[$nom]<=0) unset($stock[$nom]);
            }
        }
    }catch(Exception $e){}
    krsort($stock, SORT_NUMERIC);
    return $stock;
}
function cash_sumber_label($sumber): string {
    $map = [
        'transaksi_cash_diterima'=>'Cash diterima transaksi',
        'kembalian_cash'=>'Kembalian transaksi',
        'manual'=>'Manual',
        'bayar_utang_cash'=>'Bayar utang cash'
    ];
    return $map[$sumber] ?? (string)$sumber;
}
function ensure_cash_tables(PDO $pdo){
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_date DATE NOT NULL,
        shift_label VARCHAR(50) NULL,
        kode_user VARCHAR(50) NOT NULL,
        opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
        opening_pecahan_json TEXT NULL,
        closing_cash DECIMAL(15,2) NULL,
        closing_pecahan_json TEXT NULL,
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
        $col2 = $pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'closing_pecahan_json'")->fetch(PDO::FETCH_ASSOC);
        if (!$col2) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN closing_pecahan_json TEXT NULL AFTER closing_cash");
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
ensure_cash_tables($pdo);

$kode_user = (string)($_SESSION['user_id'] ?? '');
$today = date('Y-m-d');

function active_cash_session(PDO $pdo){
    $st=$pdo->query("SELECT * FROM cash_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1");
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cash_summary(PDO $pdo, array $session){
    $sid=(int)$session['id'];
    $date=$session['session_date'];

    // Jika kasir sudah mencatat uang diterima + kembalian ke cash_movements,
    // gunakan data fisik itu agar cash masuk mengikuti jumlah uang bayar.
    $txIn=0; $txOut=0; $txTracked=0;
    try{
        $st=$pdo->prepare("SELECT sumber, jenis, COALESCE(SUM(nominal),0) total, COUNT(*) cnt
                           FROM cash_movements
                           WHERE session_id=? AND sumber IN ('transaksi_cash_diterima','kembalian_cash')
                           GROUP BY sumber, jenis");
        $st->execute([$sid]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
            $txTracked += (int)$r['cnt'];
            if($r['sumber']==='transaksi_cash_diterima') $txIn += (float)$r['total'];
            if($r['sumber']==='kembalian_cash') $txOut += (float)$r['total'];
        }
    }catch(Exception $e){}

    $cashIn=0; $cashOut=0;
    if($txTracked > 0){
        $cashIn=$txIn;
        $cashOut=$txOut;
    } else {
        // Fallback untuk transaksi lama sebelum fitur ini dipasang.
        try{
            $st=$pdo->prepare("SELECT COALESCE(SUM(jumlah_masuk),0) masuk, COALESCE(SUM(jumlah_keluar),0) keluar
                               FROM arus_kas
                               WHERE tanggal >= ? AND LOWER(COALESCE(metode_pembayaran,''))='cash'");
            $st->execute([$date]);
            $r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
            $cashIn=(float)($r['masuk'] ?? 0);
            $cashOut=(float)($r['keluar'] ?? 0);
        }catch(Exception $e){}
    }

    $manualIn=0; $manualOut=0;
    $st=$pdo->prepare("SELECT jenis, COALESCE(SUM(nominal),0) total FROM cash_movements WHERE session_id=? AND sumber NOT IN ('transaksi_cash_diterima','kembalian_cash') GROUP BY jenis");
    $st->execute([$sid]);
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
        if($r['jenis']==='masuk') $manualIn=(float)$r['total'];
        if($r['jenis']==='keluar') $manualOut=(float)$r['total'];
    }

    $opening=(float)$session['opening_cash'];
    $expected=$opening+$cashIn+$manualIn-$cashOut-$manualOut;
    return compact('opening','cashIn','cashOut','manualIn','manualOut','expected','txTracked');
}

$flash=''; $flashType='success';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    try{
        if($action==='open_cash'){
            if(active_cash_session($pdo)) throw new Exception('Masih ada sesi kas yang terbuka. Tutup dulu sebelum buka kas baru.');
            $denomClean = isset($_POST['denom']) && is_array($_POST['denom']) ? cash_denom_clean($_POST['denom']) : [];
            $denomTotal = $denomClean ? cash_denom_total($denomClean) : 0;
            $openingManual = max(0,(float)cash_post('opening_cash',0));
            $opening = $denomTotal > 0 ? $denomTotal : $openingManual;
            $date=cash_post('session_date',date('Y-m-d'));
            $dt=DateTime::createFromFormat('Y-m-d',$date); if(!$dt) $date=date('Y-m-d'); else $date=$dt->format('Y-m-d');
            $shift=trim((string)cash_post('shift_label',''));
            $pdo->prepare("INSERT INTO cash_sessions (session_date, shift_label, kode_user, opening_cash, opening_pecahan_json, status, opened_at) VALUES (?,?,?,?,?, 'open', NOW())")
                ->execute([$date,$shift,$GLOBALS['kode_user'],$opening,json_encode($denomClean,JSON_UNESCAPED_UNICODE)]);
            $flash='Kas berhasil dibuka.';
        } elseif($action==='add_movement'){
            $session=active_cash_session($pdo); if(!$session) throw new Exception('Belum ada sesi kas terbuka.');
            $jenis=cash_post('jenis','keluar'); if(!in_array($jenis,['masuk','keluar'],true)) $jenis='keluar';
            $denomClean = isset($_POST['denom_manual']) && is_array($_POST['denom_manual']) ? cash_denom_clean($_POST['denom_manual']) : [];
            $denomTotal = $denomClean ? cash_denom_total($denomClean) : 0;
            $nom=max(0,(float)cash_post('nominal',0));
            if($denomTotal > 0) $nom = $denomTotal;
            if($nom<=0) throw new Exception('Nominal harus lebih dari 0.');

            // Jika operator hanya mengisi nominal tanpa detail pecahan,
            // tetap simpan rincian pecahan otomatis agar mutasi dan stok cash detail.
            if (!$denomClean) {
                if ($jenis === 'masuk') {
                    $denomClean = cash_pecahan_from_nominal($nom);
                } else {
                    $denomClean = cash_pecahan_keluar_dari_stok($nom, cash_pecahan_stock($pdo, $session));
                }
            }

            $ket=trim((string)cash_post('keterangan','Mutasi manual'));
            $txtPecahan = cash_pecahan_text(json_encode($denomClean, JSON_UNESCAPED_UNICODE));
            if ($txtPecahan !== '' && strpos($ket, $txtPecahan) === false) $ket .= ' | ' . $txtPecahan;
            $pdo->prepare("INSERT INTO cash_movements (session_id,tanggal,waktu,jenis,sumber,keterangan,nominal,pecahan_json,kode_user) VALUES (?,CURDATE(),CURTIME(),?,'manual',?,?,?,?)")
                ->execute([(int)$session['id'],$jenis,$ket,$nom,json_encode($denomClean,JSON_UNESCAPED_UNICODE),$GLOBALS['kode_user']]);
            $flash='Mutasi kas berhasil disimpan.';
        } elseif($action==='revise_movement_pecahan'){
            $session=active_cash_session($pdo); if(!$session) throw new Exception('Belum ada sesi kas terbuka.');
            $movementId=(int)cash_post('movement_id',0);
            if($movementId<=0) throw new Exception('ID mutasi tidak valid.');
            $st=$pdo->prepare("SELECT * FROM cash_movements WHERE id=? AND session_id=? LIMIT 1");
            $st->execute([$movementId,(int)$session['id']]);
            $mov=$st->fetch(PDO::FETCH_ASSOC);
            if(!$mov) throw new Exception('Mutasi tidak ditemukan pada sesi kas aktif.');

            $denomRev = isset($_POST['denom_revisi']) && is_array($_POST['denom_revisi']) ? cash_denom_clean($_POST['denom_revisi']) : [];
            $totalRev = $denomRev ? cash_denom_total($denomRev) : 0;
            $nominalMov = (float)($mov['nominal'] ?? 0);
            if($totalRev <= 0) throw new Exception('Rincian pecahan revisi belum diisi.');
            if(abs($totalRev - $nominalMov) > 0.01){
                throw new Exception('Total pecahan revisi '.rupiah_cash($totalRev).' harus sama dengan nominal mutasi '.rupiah_cash($nominalMov).'.');
            }

            $ket = (string)($mov['keterangan'] ?? '');
            if(stripos($ket, 'pecahan direvisi') === false){
                $ket = trim($ket . ' | pecahan direvisi');
            }
            $pdo->prepare("UPDATE cash_movements SET pecahan_json=?, keterangan=? WHERE id=? AND session_id=?")
                ->execute([json_encode($denomRev,JSON_UNESCAPED_UNICODE), $ket, $movementId, (int)$session['id']]);
            $flash='Rincian pecahan mutasi berhasil direvisi.';
        } elseif($action==='close_cash'){
            $session=active_cash_session($pdo); if(!$session) throw new Exception('Belum ada sesi kas terbuka.');
            $sum=cash_summary($pdo,$session);
            $denomClose = isset($_POST['denom_close']) && is_array($_POST['denom_close']) ? cash_denom_clean($_POST['denom_close']) : [];
            $denomCloseTotal = $denomClose ? cash_denom_total($denomClose) : 0;
            $closing=max(0,(float)cash_post('closing_cash',0));
            if($denomCloseTotal > 0) $closing = $denomCloseTotal;
            $diff=$closing-(float)$sum['expected'];
            $note=trim((string)cash_post('note',''));
            $pdo->prepare("UPDATE cash_sessions SET closing_cash=?, closing_pecahan_json=?, expected_cash=?, difference_cash=?, note=?, status='closed', closed_at=NOW() WHERE id=?")
                ->execute([$closing,json_encode($denomClose,JSON_UNESCAPED_UNICODE),$sum['expected'],$diff,$note,(int)$session['id']]);
            $flash='Kas berhasil ditutup. Selisih: '.rupiah_cash($diff);
        }
    }catch(Exception $e){ $flash=$e->getMessage(); $flashType='danger'; }
}

$session=active_cash_session($pdo);
$summary=$session ? cash_summary($pdo,$session) : null;
$stockPecahan=$session ? cash_pecahan_stock($pdo,$session) : [];
$recent=[];
if($session){
    $st=$pdo->prepare("SELECT * FROM cash_movements WHERE session_id=? ORDER BY created_at DESC LIMIT 100");
    $st->execute([(int)$session['id']]);
    $recent=$st->fetchAll(PDO::FETCH_ASSOC);
}
$recentSessions=$pdo->query("SELECT * FROM cash_sessions ORDER BY opened_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manajemen Cash</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body{background:#f3f6fb}.wrapper{display:flex;min-height:100vh}.main-content{flex:1}.cash-card{border:0;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.money-big{font-size:1.7rem;font-weight:800;letter-spacing:-.5px}.denom-pill{display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;background:#fff;border-radius:12px;padding:9px 12px;margin-bottom:7px}.denom-pill b{font-size:.95rem}.stock-pecahan{border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;background:#fff;display:flex;justify-content:space-between;gap:10px}.stock-pecahan .qty{font-weight:800;color:#0f172a}@media(max-width:768px){.wrapper{display:block}.main-content{padding-bottom:70px}.money-big{font-size:1.35rem}}
</style>
</head>
<body>
<div class="wrapper">
<?php include '../../sidebar.php'; ?>
<div class="main-content p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h4 class="fw-bolder mb-0"><i class="fas fa-cash-register text-success me-2"></i>Manajemen Cash</h4>
            <div class="text-muted small">Buka kas, pantau cash masuk/keluar, dan hitung sugesti pecahan kembalian.</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if($session): ?><a href="cetak_amplop.php?id=<?= (int)$session['id'] ?>" target="_blank" class="btn btn-sm btn-dark fw-bold"><i class="fas fa-print me-1"></i>Cetak Amplop Cash</a><?php endif; ?>
            <span class="badge <?= $session?'bg-success':'bg-secondary' ?> rounded-pill px-3 py-2"><?= $session?'Kas Terbuka':'Belum Buka Kas' ?></span>
        </div>
    </div>

    <?php if($flash): ?><div class="alert alert-<?= $flashType ?> shadow-sm border-0 rounded-4"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <?php if(!$session): ?>
    <div class="card cash-card mb-3"><div class="card-body p-4">
        <h5 class="fw-bold mb-3">Buka Kas Awal</h5>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="open_cash">
            <div class="col-md-4"><label class="form-label fw-bold small text-muted">Tanggal Kas</label><input type="date" name="session_date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-4"><label class="form-label fw-bold small text-muted">Shift / Catatan</label><input type="text" name="shift_label" class="form-control form-control-lg" placeholder="Pagi / Siang / Malam"></div>
            <div class="col-md-4">
                <label class="form-label fw-bold small text-muted">Total Cash Awal</label>
                <input type="number" name="opening_cash" id="openingCashTotal" class="form-control form-control-lg fw-bold text-success" min="0" value="0" required>
                <small class="text-muted">Bisa isi manual, atau isi jumlah pecahan di bawah.</small>
            </div>
            <div class="col-12">
                <div class="bg-light border rounded-4 p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-bold"><i class="fas fa-coins text-warning me-2"></i>Hitung Cash Awal dari Pecahan</div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetDenomAwal()">Reset</button>
                    </div>
                    <div class="row g-2 denom-awal">
                        <?php foreach([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $pec): ?>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label small text-muted mb-1">Rp <?= number_format($pec,0,',','.') ?></label>
                            <input type="number" name="denom[<?= $pec ?>]" class="form-control form-control-sm text-center denom-input" data-nominal="<?= $pec ?>" min="0" value="0" oninput="hitungCashAwalPecahan()">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-2 small text-muted">Contoh: Rp 5.000 isi <b>4</b>, Rp 1.000 isi <b>5</b>, koin Rp 500 isi <b>10</b>. Total otomatis masuk ke Cash Awal.</div>
                </div>
            </div>
            <div class="col-12"><button class="btn btn-success btn-lg fw-bold px-4"><i class="fas fa-unlock me-2"></i>Buka Kas</button></div>
        </form>
    </div></div>
    <?php else: ?>
    <?php
        $totalCashMasuk = (float)$summary['cashIn'] + (float)$summary['manualIn'];
        $totalCashKeluar = (float)$summary['cashOut'] + (float)$summary['manualOut'];
        $uangCashSaatIni = (float)$summary['opening'] + $totalCashMasuk - $totalCashKeluar;
    ?>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card cash-card"><div class="card-body"><div class="text-muted small fw-bold">Cash Awal</div><div class="money-big"><?= rupiah_cash($summary['opening']) ?></div><?php if(!empty($session['opening_pecahan_json'])): ?><small class="text-primary"><?= htmlspecialchars(cash_pecahan_text($session['opening_pecahan_json'])) ?></small><?php endif; ?></div></div></div>
        <div class="col-md-3"><div class="card cash-card"><div class="card-body"><div class="text-muted small fw-bold">Uang Masuk</div><div class="money-big text-success"><?= rupiah_cash($totalCashMasuk) ?></div><small class="text-muted">Transaksi cash + bayar utang + manual masuk</small></div></div></div>
        <div class="col-md-3"><div class="card cash-card"><div class="card-body"><div class="text-muted small fw-bold">Uang Keluar</div><div class="money-big text-danger"><?= rupiah_cash($totalCashKeluar) ?></div><small class="text-muted">Kembalian + manual keluar</small></div></div></div>
        <div class="col-md-3"><div class="card cash-card border-primary"><div class="card-body"><div class="text-muted small fw-bold">Uang Cash Saat Ini</div><div class="money-big text-primary"><?= rupiah_cash($uangCashSaatIni) ?></div><small class="text-muted">Cash awal + uang masuk - uang keluar</small></div></div></div>
    </div>
    <div class="card cash-card mb-3"><div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <div class="fw-bold"><i class="fas fa-calculator text-primary me-2"></i>Rumus Cash Saat Ini</div>
                <div class="small text-muted">Perhitungan nominal kas aktif berdasarkan uang masuk dikurangi uang keluar.</div>
            </div>
            <div class="text-end fw-bold">
                <?= rupiah_cash($summary['opening']) ?> + <?= rupiah_cash($totalCashMasuk) ?> - <?= rupiah_cash($totalCashKeluar) ?> = <span class="text-primary"><?= rupiah_cash($uangCashSaatIni) ?></span>
            </div>
        </div>
    </div></div>

    <div class="card cash-card mb-3" id="stokPecahanCard"><div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0"><i class="fas fa-money-bill-wave text-success me-2"></i>Stok Pecahan Cash Saat Ini</h6>
                <div class="small text-muted" id="stokPecahanUpdated">Realtime: aktif</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-primary rounded-pill" id="stokPecahanTotal">Total pecahan: <?= rupiah_cash(cash_pecahan_total_array($stockPecahan)) ?></span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="refreshStokPecahan(true)"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
            </div>
        </div>
        <div class="row g-2" id="stokPecahanGrid">
            <?php foreach([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $pec): $qty=(int)($stockPecahan[(string)$pec] ?? 0); ?>
            <div class="col-6 col-md-3 col-lg-2"><div class="stock-pecahan"><span>Rp <?= number_format($pec,0,',','.') ?></span><span class="qty">×<?= $qty ?></span></div></div>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-2">Stok pecahan dihitung realtime dari cash awal + cash diterima - kembalian/manual keluar/bayar utang. Ini yang dipakai untuk sugesti kembalian di kasir.</div>
    </div></div>

    <div class="row g-3">
        <div class="col-lg-5"><div class="card cash-card h-100"><div class="card-body p-4">
            <h5 class="fw-bold mb-3">Tambah Mutasi Manual</h5>
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="add_movement">
                <div class="col-5"><select name="jenis" class="form-select form-select-lg"><option value="masuk">Cash Masuk</option><option value="keluar">Cash Keluar</option></select></div>
                <div class="col-7"><input type="number" name="nominal" id="manualNominal" class="form-control form-control-lg fw-bold" placeholder="Nominal" min="1" required></div>
                <div class="col-12"><input type="text" name="keterangan" class="form-control" placeholder="Keterangan, contoh: beli plastik / tambah modal pecahan"></div>
                <div class="col-12">
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-2" data-bs-toggle="collapse" data-bs-target="#pecahanManualBox"><i class="fas fa-coins me-1"></i>Input pecahan mutasi</button>
                    <div id="pecahanManualBox" class="collapse bg-light border rounded-4 p-2">
                        <div class="row g-1">
                            <?php foreach([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $pec): ?>
                            <div class="col-6 col-md-4"><label class="small text-muted">Rp <?= number_format($pec,0,',','.') ?></label><input type="number" name="denom_manual[<?= $pec ?>]" class="form-control form-control-sm text-center denom-manual" data-nominal="<?= $pec ?>" min="0" value="0" oninput="hitungManualPecahan()"></div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Jika pecahan diisi, nominal mutasi otomatis mengikuti total pecahan.</small>
                    </div>
                </div>
                <div class="col-12"><button class="btn btn-primary fw-bold w-100"><i class="fas fa-save me-2"></i>Simpan Mutasi</button></div>
            </form>
            <hr>
            <h6 class="fw-bold">Log Cash Detail Terbaru</h6>
            <div class="list-group small">
                <?php if(!$recent): ?><div class="text-muted">Belum ada mutasi manual.</div><?php endif; ?>
                <?php foreach($recent as $m): ?>
                    <?php
                        $pecahanText = cash_pecahan_text($m['pecahan_json'] ?? '');
                        if ($pecahanText === '' && (float)($m['nominal'] ?? 0) > 0) {
                            $fallbackPecahan = cash_pecahan_from_nominal((float)$m['nominal']);
                            $pecahanText = cash_pecahan_text(json_encode($fallbackPecahan, JSON_UNESCAPED_UNICODE));
                        }
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                            <span>
                                <span class="badge <?= $m['jenis']==='masuk'?'bg-success':'bg-danger' ?> me-1"><?= htmlspecialchars(cash_sumber_label($m['sumber'] ?? 'manual')) ?></span>
                                <?= htmlspecialchars($m['keterangan'] ?: '-') ?><br>
                                <small class="text-muted"><?= $m['tanggal'].' '.$m['waktu'] ?> • User: <?= htmlspecialchars($m['kode_user'] ?? '-') ?></small>
                                <?php if($pecahanText): ?><br><small class="text-primary"><i class="fas fa-money-bill-wave me-1"></i><?= htmlspecialchars($pecahanText) ?></small><?php endif; ?>
                                <br><button type="button" class="btn btn-sm btn-outline-warning mt-2" data-bs-toggle="collapse" data-bs-target="#revisiPecahan<?= (int)$m['id'] ?>"><i class="fas fa-edit me-1"></i>Revisi Pecahan</button>
                            </span>
                            <b class="<?= $m['jenis']==='masuk'?'text-success':'text-danger' ?>"><?= $m['jenis']==='masuk'?'+':'-' ?><?= rupiah_cash($m['nominal']) ?></b>
                        </div>
                        <?php
                            $pecahanEdit = cash_pecahan_array($m['pecahan_json'] ?? '');
                            if(!$pecahanEdit && (float)($m['nominal'] ?? 0) > 0) $pecahanEdit = cash_pecahan_from_nominal((float)$m['nominal']);
                        ?>
                        <div class="collapse mt-2" id="revisiPecahan<?= (int)$m['id'] ?>">
                            <form method="post" class="border rounded-4 bg-light p-2">
                                <input type="hidden" name="action" value="revise_movement_pecahan">
                                <input type="hidden" name="movement_id" value="<?= (int)$m['id'] ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <b class="small">Revisi rincian pecahan</b>
                                    <span class="badge bg-secondary">Total harus <?= rupiah_cash($m['nominal']) ?></span>
                                </div>
                                <div class="row g-1">
                                    <?php foreach([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $pec): ?>
                                    <div class="col-6 col-md-4"><label class="small text-muted">Rp <?= number_format($pec,0,',','.') ?></label><input type="number" name="denom_revisi[<?= $pec ?>]" class="form-control form-control-sm text-center" min="0" value="<?= (int)($pecahanEdit[(string)$pec] ?? 0) ?>"></div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted d-block mt-1">Revisi ini mengubah stok pecahan realtime karena stok dihitung dari rincian yang tersimpan.</small>
                                <button class="btn btn-warning btn-sm fw-bold mt-2 w-100"><i class="fas fa-save me-1"></i>Simpan Revisi Pecahan</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div></div>

        <div class="col-lg-7"><div class="card cash-card h-100"><div class="card-body p-4">
            <h5 class="fw-bold mb-3">Sugesti Pecahan Kembalian</h5>
            <div class="input-group input-group-lg mb-2"><span class="input-group-text bg-white">Rp</span><input type="number" id="inputKembalian" class="form-control fw-bold" placeholder="Masukkan nominal kembalian" min="0"><button class="btn btn-success fw-bold" type="button" onclick="hitungPecahan()">Hitung</button></div>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-sm btn-outline-secondary" onclick="quickKembali(5000)">5.000</button><button class="btn btn-sm btn-outline-secondary" onclick="quickKembali(10000)">10.000</button><button class="btn btn-sm btn-outline-secondary" onclick="quickKembali(20000)">20.000</button><button class="btn btn-sm btn-outline-secondary" onclick="quickKembali(50000)">50.000</button>
            </div>
            <div id="hasilPecahan" class="bg-light border rounded-4 p-3 text-muted">Masukkan nominal kembalian, nanti sistem memberi saran ambil pecahan uang apa saja.</div>
            <hr>
            <form method="post" onsubmit="return confirm('Tutup kas sekarang?');">
                <input type="hidden" name="action" value="close_cash">
                <label class="form-label fw-bold small text-muted">Cash Fisik Saat Tutup</label>
                <div class="input-group input-group-lg mb-2"><span class="input-group-text bg-white">Rp</span><input type="number" name="closing_cash" id="closingCashInput" class="form-control fw-bold" min="0" value="<?= (int)$summary['expected'] ?>" required></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-2" data-bs-toggle="collapse" data-bs-target="#pecahanCloseBox"><i class="fas fa-coins me-1"></i>Hitung cash tutup dari pecahan</button>
                <div id="pecahanCloseBox" class="collapse bg-light border rounded-4 p-2 mb-2">
                    <div class="row g-1">
                        <?php foreach([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $pec): ?>
                        <div class="col-6 col-md-4"><label class="small text-muted">Rp <?= number_format($pec,0,',','.') ?></label><input type="number" name="denom_close[<?= $pec ?>]" class="form-control form-control-sm text-center denom-close" data-nominal="<?= $pec ?>" min="0" value="0" oninput="hitungClosePecahan()"></div>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted">Jika diisi, cash fisik tutup otomatis mengikuti total pecahan.</small>
                </div>
                <textarea name="note" class="form-control mb-2" placeholder="Catatan tutup kas / selisih jika ada"></textarea>
                <button class="btn btn-dark fw-bold w-100"><i class="fas fa-lock me-2"></i>Tutup Kas</button>
            </form>
        </div></div></div>
    </div>
    <?php endif; ?>

    <div class="card cash-card mt-3"><div class="card-body p-4">
        <h5 class="fw-bold mb-3">Riwayat Sesi Kas</h5>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Tanggal</th><th>Status</th><th>Cash Awal</th><th>Perkiraan</th><th>Cash Tutup</th><th>Selisih</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach($recentSessions as $cs): ?><tr><td><?= htmlspecialchars($cs['session_date']) ?><br><small class="text-muted"><?= htmlspecialchars($cs['shift_label'] ?: '-') ?></small></td><td><span class="badge <?= $cs['status']==='open'?'bg-success':'bg-secondary' ?>"><?= htmlspecialchars($cs['status']) ?></span></td><td><?= rupiah_cash($cs['opening_cash']) ?></td><td><?= $cs['expected_cash']!==null?rupiah_cash($cs['expected_cash']):'-' ?></td><td><?= $cs['closing_cash']!==null?rupiah_cash($cs['closing_cash']):'-' ?></td><td class="fw-bold <?= ((float)$cs['difference_cash'])<0?'text-danger':'text-success' ?>"><?= $cs['difference_cash']!==null?rupiah_cash($cs['difference_cash']):'-' ?></td><td><a href="cetak_amplop.php?id=<?= (int)$cs['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-print"></i></a></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function formatRp(n){ return new Intl.NumberFormat('id-ID').format(Math.max(0, Math.floor(Number(n)||0))); }
function hitungCashAwalPecahan(){
    let total = 0;
    document.querySelectorAll('.denom-input').forEach(function(inp){
        const nominal = Number(inp.dataset.nominal || 0);
        const qty = Math.max(0, Math.floor(Number(inp.value || 0)));
        total += nominal * qty;
    });
    const el = document.getElementById('openingCashTotal');
    if (el && total > 0) el.value = total;
}
function resetDenomAwal(){
    document.querySelectorAll('.denom-input').forEach(function(inp){ inp.value = 0; });
    const el = document.getElementById('openingCashTotal');
    if (el) el.value = 0;
}

function hitungPecahanBySelector(selector, targetId){
    let total = 0;
    document.querySelectorAll(selector).forEach(function(inp){
        const nominal = Number(inp.dataset.nominal || 0);
        const qty = Math.max(0, Math.floor(Number(inp.value || 0)));
        total += nominal * qty;
    });
    const el = document.getElementById(targetId);
    if (el && total > 0) el.value = total;
}
function hitungManualPecahan(){ hitungPecahanBySelector('.denom-manual', 'manualNominal'); }
function hitungClosePecahan(){ hitungPecahanBySelector('.denom-close', 'closingCashInput'); }
function quickKembali(n){ document.getElementById('inputKembalian').value=n; hitungPecahan(); }
function hitungPecahan(){
    let nominal=Math.floor(Number(document.getElementById('inputKembalian').value)||0);
    const pecahan=[100000,50000,20000,10000,5000,2000,1000,500,200,100];
    let html=''; let sisa=nominal;
    pecahan.forEach(p=>{ let j=Math.floor(sisa/p); if(j>0){ html += `<div class="denom-pill"><span><b>Rp ${formatRp(p)}</b></span><span>${j} lembar/koin</span></div>`; sisa -= j*p; } });
    if(!nominal){ html='<div class="text-muted">Masukkan nominal kembalian dulu.</div>'; }
    else if(!html){ html='<div class="text-muted">Nominal terlalu kecil untuk pecahan yang disiapkan.</div>'; }
    if(sisa>0) html += `<div class="text-danger small mt-2">Sisa tidak terbagi: Rp ${formatRp(sisa)}</div>`;
    document.getElementById('hasilPecahan').innerHTML=html;
}
let stokPecahanTimer = null;
function renderStokPecahan(data){
    const grid = document.getElementById('stokPecahanGrid');
    const total = document.getElementById('stokPecahanTotal');
    const updated = document.getElementById('stokPecahanUpdated');
    if (!grid || !data || !data.success) return;
    const pecahan = data.pecahan_order || [100000,50000,20000,10000,5000,2000,1000,500,200,100];
    const stok = data.stock || {};
    grid.innerHTML = pecahan.map(function(p){
        const qty = Number(stok[String(p)] || 0);
        const cls = qty <= 0 ? ' text-muted opacity-75' : '';
        return `<div class="col-6 col-md-3 col-lg-2"><div class="stock-pecahan${cls}"><span>Rp ${formatRp(p)}</span><span class="qty">×${qty}</span></div></div>`;
    }).join('');
    if (total) total.textContent = 'Total pecahan: Rp ' + formatRp(data.total || 0);
    if (updated) updated.textContent = 'Update terakhir: ' + (data.updated_at || '-');
}
function refreshStokPecahan(manual){
    const updated = document.getElementById('stokPecahanUpdated');
    if (manual && updated) updated.textContent = 'Memuat stok realtime...';
    fetch('cash_stock_realtime.php?_=' + Date.now(), {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(renderStokPecahan)
        .catch(function(){ if (updated) updated.textContent = 'Realtime gagal dimuat, klik Refresh.'; });
}
if (document.getElementById('stokPecahanGrid')) {
    refreshStokPecahan(false);
    stokPecahanTimer = setInterval(function(){ refreshStokPecahan(false); }, 5000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) refreshStokPecahan(false); });
}

</script>
</body></html>
