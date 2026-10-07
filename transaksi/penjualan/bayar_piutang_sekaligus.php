<?php
ob_start();
$__piutangIsPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
if ($__piutangIsPost) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
session_start();
require_once '../../config/database.php';

function piutangJsonResponse(array $payload): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function piutangSafeBeginTransaction(PDO $pdo): bool {
    if ($pdo->inTransaction()) return false;
    return $pdo->beginTransaction();
}
function piutangSafeCommitTransaction(PDO $pdo, bool $started): void {
    if ($started && $pdo->inTransaction()) {
        $pdo->commit();
    }
}
function piutangSafeRollbackTransaction(PDO $pdo, bool $started): void {
    if ($started && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

if ($__piutangIsPost) {
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            piutangJsonResponse(['status' => 'error', 'message' => 'Fatal PHP: ' . basename((string)$err['file']) . ':' . (int)$err['line'] . ' - ' . (string)$err['message']]);
        }
    });
}

if (!isset($_SESSION['user_id'])) { piutangJsonResponse(['status' => 'error', 'message' => 'Akses ditolak.']); }

function ambilNotaPiutangPelanggan(PDO $pdo, $kode_pelanggan) {
    $sql = "SELECT p.*, pl.nama_pelanggan,
            CASE 
                WHEN p.total_omzet > 0 THEN p.total_omzet
                ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan)
            END as total_tagihan_fix
            FROM penjualan p
            JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
            WHERE p.pelunasan = 'N' AND p.kode_pelanggan = ?
            ORDER BY p.tgl_penjualan ASC, p.no_penjualan ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$kode_pelanggan]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $total = (float)$row['total_tagihan_fix'];
        $bayar = (float)$row['uang_bayar'];
        $depo  = (float)($row['nominal_deposit'] ?? 0);
        $sisa  = $total - $bayar - $depo;
        if ($sisa >= 100) {
            $row['sisa_utang_fix'] = $sisa;
            $rows[] = $row;
        }
    }
    return $rows;
}

function gabungMetodePembayaran($metode_lama, $metode_bayar) {
    $metode_lama = trim((string)$metode_lama);
    if (empty($metode_lama) || strtolower($metode_lama) == 'utang' || strtolower($metode_lama) == 'piutang') {
        return $metode_bayar;
    }
    if (stripos($metode_lama, $metode_bayar) === false) {
        return substr($metode_lama . ', ' . $metode_bayar, 0, 50);
    }
    return $metode_lama;
}

function tanggalBayarPiutangInput($input = null) {
    $raw = trim((string)($input ?? date('Y-m-d')));
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    $err = DateTime::getLastErrors();
    if (!$dt || ($err && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0))) {
        $dt = new DateTime();
    }
    return $dt->format('Y-m-d');
}


function piutangPecahanDariNominal($nominal): array {
    $nominal = (int)floor((float)$nominal);
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = intdiv($nominal, $p);
        if ($qty > 0) { $out[(string)$p] = $qty; $nominal -= $qty * $p; }
    }
    return $out;
}
function piutangParsePecahanJson($raw): array {
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ([100000,50000,20000,10000,5000,2000,1000,500,200,100] as $p) {
        $qty = isset($data[$p]) ? (int)$data[$p] : (isset($data[(string)$p]) ? (int)$data[(string)$p] : 0);
        if ($qty > 0) $out[(string)$p] = $qty;
    }
    return $out;
}
function piutangCashEnsureTables(PDO $pdo): void {
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
    try { $col=$pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'opening_pecahan_json'")->fetch(PDO::FETCH_ASSOC); if(!$col) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN opening_pecahan_json TEXT NULL AFTER opening_cash"); } catch(Throwable $e) {}
    try { $col=$pdo->query("SHOW COLUMNS FROM cash_sessions LIKE 'closing_pecahan_json'")->fetch(PDO::FETCH_ASSOC); if(!$col) $pdo->exec("ALTER TABLE cash_sessions ADD COLUMN closing_pecahan_json TEXT NULL AFTER closing_cash"); } catch(Throwable $e) {}
    try { $col=$pdo->query("SHOW COLUMNS FROM cash_movements LIKE 'pecahan_json'")->fetch(PDO::FETCH_ASSOC); if(!$col) $pdo->exec("ALTER TABLE cash_movements ADD COLUMN pecahan_json TEXT NULL AFTER nominal"); } catch(Throwable $e) {}
}
function piutangLogDepositEnsure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS log_deposit (
        id int(11) NOT NULL AUTO_INCREMENT,
        tgl_deposit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        kode_pelanggan varchar(20) NOT NULL,
        nominal double NOT NULL,
        keterangan text,
        kode_user varchar(10) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function piutangTambahDepositDariKelebihan(PDO $pdo, string $kode_pelanggan, float $nominal, string $tanggal, string $ref, string $kode_user): void {
    $kode_pelanggan = trim($kode_pelanggan);
    if ($nominal <= 0 || $kode_pelanggan === '' || strtoupper($kode_pelanggan) === 'UMUM') return;
    if (!$pdo->inTransaction()) { piutangLogDepositEnsure($pdo); }
    $pdo->prepare("UPDATE pelanggan SET saldo_deposit = COALESCE(saldo_deposit,0) + ? WHERE kode_pelanggan = ?")
        ->execute([$nominal, $kode_pelanggan]);
    $tglLog = $tanggal . ' ' . date('H:i:s');
    $pdo->prepare("INSERT INTO log_deposit (tgl_deposit, kode_pelanggan, nominal, keterangan, kode_user) VALUES (?, ?, ?, ?, ?)")
        ->execute([$tglLog, $kode_pelanggan, $nominal, "Kelebihan bayar utang $ref", $kode_user]);
    try {
        $pdo->prepare("INSERT INTO arus_kas (tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user, no_penjualan, metode_pembayaran) VALUES (?, 'Pemasukan', ?, ?, 0, ?, ?, 'Cash')")
            ->execute([$tanggal, "Deposit kelebihan bayar utang $ref", $nominal, $kode_user, $ref]);
    } catch (Throwable $e) {}
}

function piutangCatatCashMovement(PDO $pdo, string $tanggal, string $ref, float $nominalDiterima, string $metode, string $kode_user, array $pecahanInput = [], float $nominalUntukUtang = 0, float $nominalKembalian = 0): void {
    if (strtolower(trim($metode)) !== 'cash' || $nominalDiterima <= 0) return;
    try {
        if (!$pdo->inTransaction()) { piutangCashEnsureTables($pdo); }
        $st=$pdo->query("SELECT id FROM cash_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1");
        $sid=$st ? (int)$st->fetchColumn() : 0;
        if ($sid <= 0) return;
        $nominalUntukUtang = $nominalUntukUtang > 0 ? $nominalUntukUtang : $nominalDiterima;
        $ket = 'Bayar utang cash ' . $ref;
        $cek=$pdo->prepare("SELECT COUNT(*) FROM cash_movements WHERE session_id=? AND sumber='bayar_utang_cash' AND keterangan LIKE ?");
        $cek->execute([$sid, $ket . '%']);
        if ((int)$cek->fetchColumn() > 0) return;
        $pecahan = $pecahanInput ?: piutangPecahanDariNominal($nominalDiterima);
        $txt=[]; foreach($pecahan as $p=>$q){ $txt[]=$q.'x Rp '.number_format((int)$p,0,',','.'); }
        $ketMasuk = $ket . ' | diterima Rp ' . number_format($nominalDiterima,0,',','.') . ' | untuk utang Rp ' . number_format($nominalUntukUtang,0,',','.');
        if ($txt) $ketMasuk .= ' | ' . implode(', ', $txt);
        $ins=$pdo->prepare("INSERT INTO cash_movements (session_id,tanggal,waktu,jenis,sumber,keterangan,nominal,pecahan_json,kode_user) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([$sid,$tanggal,date('H:i:s'),'masuk','bayar_utang_cash',$ketMasuk,$nominalDiterima,json_encode($pecahan,JSON_UNESCAPED_UNICODE),$kode_user]);

        if ($nominalKembalian > 0) {
            $pecahanKeluar = piutangPecahanDariNominal($nominalKembalian);
            $txtKeluar=[]; foreach($pecahanKeluar as $p=>$q){ $txtKeluar[]=$q.'x Rp '.number_format((int)$p,0,',','.'); }
            $ketKeluar = 'Kembalian bayar utang cash ' . $ref;
            if ($txtKeluar) $ketKeluar .= ' | ' . implode(', ', $txtKeluar);
            $ins->execute([$sid,$tanggal,date('H:i:s'),'keluar','bayar_utang_kembalian',$ketKeluar,$nominalKembalian,json_encode($pecahanKeluar,JSON_UNESCAPED_UNICODE),$kode_user]);
        }
    } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $kode_pelanggan = $_POST['kode_pelanggan'] ?? '';
    $metode_bayar = $_POST['metode'] ?? 'Cash';
    $pecahan_bayar = piutangParsePecahanJson($_POST['pecahan_bayar_json'] ?? '');
    $nominal_diterima = (float)($_POST['nominal'] ?? 0);
    $aksi_kelebihan = $_POST['overpay_action'] ?? 'kembalian';
    $is_cash_bayar = (strtolower(trim($metode_bayar)) === 'cash');

    if (empty($kode_pelanggan)) {
        piutangJsonResponse(['status' => 'error', 'message' => 'Data pelanggan tidak valid.']);
    }
    if ($nominal_diterima <= 0) {
        piutangJsonResponse(['status' => 'error', 'message' => 'Nominal pembayaran harus lebih dari 0.']);
    }

    $nota_piutang = ambilNotaPiutangPelanggan($pdo, $kode_pelanggan);
    $total_sisa = array_sum(array_column($nota_piutang, 'sisa_utang_fix'));

    if (count($nota_piutang) === 0 || $total_sisa < 100) {
        piutangJsonResponse(['status' => 'error', 'message' => 'Tidak ada utang aktif untuk pelanggan ini.']);
    }
    if (!$is_cash_bayar && $nominal_diterima > ($total_sisa + 500)) {
        piutangJsonResponse(['status' => 'error', 'message' => 'Pembayaran non-tunai tidak boleh melebihi total sisa utang pelanggan.']);
    }
    if ($nominal_diterima > ($total_sisa + 500) && !$is_cash_bayar) {
        piutangJsonResponse(['status' => 'error', 'message' => 'Kelebihan bayar hanya didukung untuk metode tunai/cash.']);
    }
    if ($aksi_kelebihan === 'deposit' && (empty($kode_pelanggan) || strtoupper($kode_pelanggan) === 'UMUM')) {
        $aksi_kelebihan = 'kembalian';
    }

    $sisa_alokasi = min($nominal_diterima, max(0, $total_sisa));
    $kelebihan_bayar = max(0, $nominal_diterima - $sisa_alokasi);
    $tgl_input = tanggalBayarPiutangInput($_POST['tanggal_bayar'] ?? null);
    $tgl_display = date('d/m', strtotime($tgl_input));
    $jumlah_nota_terbayar = 0;
    $total_teralokasi = 0;

    // Hindari error "there is no active transaction": semua CREATE/ALTER table wajib di luar transaction.
    if ($is_cash_bayar) { piutangCashEnsureTables($pdo); }
    if ($kelebihan_bayar > 0 && $aksi_kelebihan === 'deposit') { piutangLogDepositEnsure($pdo); }

    try {
        // Pastikan DDL/ALTER table sudah selesai sebelum transaction supaya MySQL tidak auto-commit.
        $txStarted = piutangSafeBeginTransaction($pdo);

        foreach ($nota_piutang as $nota) {
            if ($sisa_alokasi <= 0) break;

            $sisa_nota = (float)$nota['sisa_utang_fix'];
            $bayar_nota = min($sisa_nota, $sisa_alokasi);
            if ($bayar_nota <= 0) continue;

            $uang_bayar_baru = (float)$nota['uang_bayar'] + $bayar_nota;
            $nominal_depo = (float)($nota['nominal_deposit'] ?? 0);
            $lunas = (round($uang_bayar_baru + $nominal_depo) >= round((float)$nota['total_tagihan_fix'])) ? 'Y' : 'N';

            $log_display = " | $tgl_display Bayar " . strtoupper($metode_bayar) . ": " . number_format($bayar_nota, 0, ',', '.');
            if ($lunas === 'Y') $log_display .= " [LUNAS]";
            $keterangan_baru = trim(($nota['keterangan'] ?? '') . $log_display);
            $metode_update = gabungMetodePembayaran($nota['metode_pembayaran'] ?? '', $metode_bayar);

            $pdo->prepare("UPDATE penjualan SET uang_bayar=?, pelunasan=?, keterangan=?, metode_pembayaran=? WHERE no_penjualan=?")
                ->execute([$uang_bayar_baru, $lunas, $keterangan_baru, $metode_update, $nota['no_penjualan']]);

            $ket_arus = "Pembayaran Piutang Sekaligus Faktur " . $nota['no_penjualan'];
            $pdo->prepare("INSERT INTO arus_kas (tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user, no_penjualan, metode_pembayaran) VALUES (?, 'Pemasukan', ?, ?, 0, ?, ?, ?)")
                ->execute([$tgl_input, $ket_arus, $bayar_nota, $_SESSION['user_id'], $nota['no_penjualan'], $metode_bayar]);
            $pdo->prepare("UPDATE penjualan_item SET pelunasan=? WHERE no_penjualan=?")
                ->execute([$lunas, $nota['no_penjualan']]);

            if ((float)$nota['total_omzet'] <= 0) {
                $pdo->prepare("UPDATE penjualan SET total_omzet=? WHERE no_penjualan=?")
                    ->execute([(float)$nota['total_tagihan_fix'], $nota['no_penjualan']]);
            }

            $sisa_alokasi -= $bayar_nota;
            $total_teralokasi += $bayar_nota;
            $jumlah_nota_terbayar++;
        }

        if ($total_teralokasi > 0) {
            if ($kelebihan_bayar > 0 && $aksi_kelebihan === 'deposit') {
                piutangTambahDepositDariKelebihan($pdo, $kode_pelanggan, $kelebihan_bayar, $tgl_input, 'SEKALIGUS-' . $kode_pelanggan, (string)$_SESSION['user_id']);
            }
            $nominal_kembalian_cash = ($kelebihan_bayar > 0 && $aksi_kelebihan !== 'deposit') ? $kelebihan_bayar : 0;
            piutangCatatCashMovement($pdo, $tgl_input, 'SEKALIGUS-' . $kode_pelanggan, $nominal_diterima, $metode_bayar, (string)$_SESSION['user_id'], $pecahan_bayar, $total_teralokasi, $nominal_kembalian_cash);
        }

        piutangSafeCommitTransaction($pdo, $txStarted);

        $sisa_setelah = array_sum(array_column(ambilNotaPiutangPelanggan($pdo, $kode_pelanggan), 'sisa_utang_fix'));
        try {
            $pdo->prepare("UPDATE pelanggan SET sisa_utang=? WHERE kode_pelanggan=?")
                ->execute([$sisa_setelah, $kode_pelanggan]);
        } catch (Throwable $e) {}
        piutangJsonResponse([
            'status' => 'success',
            'message' => 'Pembayaran sekaligus berhasil disimpan untuk ' . $jumlah_nota_terbayar . ' nota.' . ($kelebihan_bayar > 0 ? ' Kelebihan Rp ' . number_format($kelebihan_bayar,0,',','.') . ' diproses sebagai ' . ($aksi_kelebihan === 'deposit' ? 'deposit pelanggan.' : 'kembalian.') : ''),
            'kode_pelanggan' => $kode_pelanggan,
            'sisa_customer' => $sisa_setelah,
            'total_bayar' => $total_teralokasi,
            'jumlah_nota' => $jumlah_nota_terbayar
        ]);
    } catch (Throwable $e) {
        piutangSafeRollbackTransaction($pdo, $txStarted ?? false);
        piutangJsonResponse(['status' => 'error', 'message' => 'Gagal: ' . $e->getMessage()]);
    }
    exit;
}

$kode_pelanggan = $_GET['kode'] ?? '';
if (empty($kode_pelanggan)) exit("<div class='p-4 text-center text-danger'>Data pelanggan tidak valid.</div>");

$nota_piutang = ambilNotaPiutangPelanggan($pdo, $kode_pelanggan);
if (count($nota_piutang) === 0) exit("<div class='p-4 text-center text-success'>Semua utang pelanggan ini sudah lunas.</div>");

$info_pelanggan = $nota_piutang[0];
$total_sisa = array_sum(array_column($nota_piutang, 'sisa_utang_fix'));
?>

<div class="modal-header bg-success text-white border-0 px-4 py-3">
    <div>
        <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-layer-group me-2"></i>Bayar Utang Sekaligus</h5>
        <small class="text-white-50 fw-semibold"><?= htmlspecialchars($info_pelanggan['nama_pelanggan']) ?> · <?= count($nota_piutang) ?> nota aktif</small>
    </div>
    <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-4 bg-light">
    <div class="alert alert-danger text-center py-3 mb-3 shadow-sm border-danger border-opacity-25" style="border-radius: 12px;">
        <small class="text-uppercase fw-bold text-danger opacity-75 d-block mb-1" style="font-size: 0.7rem; letter-spacing: 1px;">Total Sisa Utang Pelanggan</small>
        <h3 class="fw-bolder text-danger mb-0">Rp <?= number_format($total_sisa, 0, ',', '.') ?></h3>
    </div>

    <div class="bg-white border rounded-3 mb-3" style="max-height: 170px; overflow-y:auto;">
        <?php foreach ($nota_piutang as $nota): ?>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom small">
            <div>
                <div class="fw-bold">#<?= htmlspecialchars($nota['no_penjualan']) ?></div>
                <div class="text-muted"><?= date('d M Y', strtotime($nota['tgl_penjualan'])) ?></div>
            </div>
            <div class="fw-bolder text-danger">Rp <?= number_format($nota['sisa_utang_fix'], 0, ',', '.') ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <form id="formBayarSekaligus" onsubmit="event.preventDefault(); simpanPiutangSekaligusAjax();">
        <input type="hidden" name="kode_pelanggan" value="<?= htmlspecialchars($kode_pelanggan) ?>">

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Tanggal Bayar</label>
            <input type="date" name="tanggal_bayar" class="form-control form-control-lg border-0 shadow-sm rounded-3 fw-bold" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
            <small class="text-muted">Default mengikuti tanggal sistem. Ubah jika pembayaran perlu masuk tanggal lain.</small>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold small text-muted">Metode Pembayaran</label>
            <select name="metode" class="form-select form-select-lg border-0 shadow-sm rounded-3">
                <option value="Cash">💵 Tunai (Cash)</option>
                <option value="Transfer">🏦 Transfer Bank</option>
                <option value="QRIS">📱 QRIS / E-Wallet</option>
                <option value="Debit">💳 Debit Card</option>
            </select>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold small text-muted">Nominal Bayar Sekaligus (Rp)</label>
            <div class="input-group input-group-lg shadow-sm" style="border-radius: 8px; overflow:hidden;">
                <span class="input-group-text bg-white text-muted border-0"><i class="fas fa-money-bill-wave"></i></span>
                <input type="number" name="nominal" id="inputNominalSekaligus" class="form-control border-0 fw-bold text-success" placeholder="0" min="1" required>
                <button class="btn btn-warning fw-bold text-dark px-3 border-0" type="button" onclick="setNominalPiutangCash('inputNominalSekaligus', <?= $total_sisa ?>)">LUNAS SEMUA</button>
            </div>
            <small class="text-muted">Nominal akan dialokasikan otomatis dari nota paling lama ke nota berikutnya.</small>

            <input type="hidden" name="pecahan_bayar_json" id="pecahanBayarPiutangJson" value="{}">
            <div id="boxPecahanPiutang" class="mt-2 p-2 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3" style="display:none; font-size:0.72rem;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="fw-bold text-success"><i class="fas fa-coins me-1"></i>Sugest pecahan uang tunai</div>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="resetPecahanPiutang()">Reset</button>
                </div>
                <div id="pecahanPiutangButtons" class="d-flex flex-wrap gap-1 mb-1"></div>
                <div id="pecahanPiutangSummary" class="small text-muted">Tap pecahan uang yang diterima, atau isi nominal manual.</div>
            </div>
            <div id="boxKelebihanPiutang" class="mt-2 p-2 bg-warning bg-opacity-10 border border-warning border-opacity-50 rounded-3" style="display:none; font-size:0.78rem;">
                <div class="fw-bold text-warning-emphasis mb-1"><i class="fas fa-exclamation-circle me-1"></i>Pembayaran tunai lebih</div>
                <div id="kelebihanPiutangText" class="small text-muted mb-2"></div>
                <div class="d-flex flex-column gap-1">
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="overpay_action" value="kembalian" checked> <span class="form-check-label">Berikan sebagai kembalian</span></label>
                    <label class="form-check m-0"><input class="form-check-input" type="radio" name="overpay_action" value="deposit"> <span class="form-check-label">Simpan ke deposit pelanggan</span></label>
                </div>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" id="btnSimpanSekaligus" class="btn btn-success btn-lg fw-bold shadow-sm" style="border-radius: 8px;">
                <i class="fas fa-save me-2"></i> Simpan Pembayaran Sekaligus
            </button>
            <button type="button" class="btn btn-light text-muted fw-bold border shadow-sm" data-bs-dismiss="modal" style="border-radius: 8px;">Batal</button>
        </div>
    </form>
</div>

<script>

const PECAHAN_PIUTANG = [100000,50000,20000,10000,5000,2000,1000,500,200,100];
let pecahanPiutangDipilih = {};
function formatRpPiutang(n){ return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.max(0, Math.floor(Number(n)||0))); }
function pecahanObjFromNominalPiutang(nominal){
    let sisa=Math.floor(Number(nominal)||0), obj={};
    PECAHAN_PIUTANG.forEach(p=>{ let q=Math.floor(sisa/p); if(q>0){ obj[p]=q; sisa-=q*p; } });
    return obj;
}
function pecahanTextPiutang(obj){
    obj=obj||{}; let rows=[];
    PECAHAN_PIUTANG.forEach(p=>{ let q=parseInt(obj[p]||obj[String(p)]||0); if(q>0) rows.push(q+'× '+formatRpPiutang(p)); });
    return rows.join(' • ');
}
function totalPecahanPiutang(obj){
    let total=0; obj=obj||{};
    PECAHAN_PIUTANG.forEach(p=>{ total += p*(parseInt(obj[p]||obj[String(p)]||0)||0); });
    return total;
}
function inputNominalPiutang(){ return document.getElementById('inputNominalCicilan') || document.getElementById('inputNominalSekaligus'); }
function metodePiutang(){ const el=document.querySelector('select[name="metode"]'); return el ? el.value : 'Cash'; }
const SISA_PIUTANG_AKTIF = <?= (float)$total_sisa ?>;
function renderKelebihanPiutang(){
    const box=document.getElementById('boxKelebihanPiutang');
    const txt=document.getElementById('kelebihanPiutangText');
    const inp=inputNominalPiutang();
    if(!box||!txt||!inp) return;
    const nominal=parseFloat(inp.value||0)||0;
    const lebih=Math.max(0, nominal - SISA_PIUTANG_AKTIF);
    if(metodePiutang()==='Cash' && lebih>0){
        box.style.display='block';
        txt.innerHTML='Total sisa utang '+formatRpPiutang(SISA_PIUTANG_AKTIF)+' · uang diterima '+formatRpPiutang(nominal)+' · lebih <b>'+formatRpPiutang(lebih)+'</b>';
    } else {
        box.style.display='none';
        const rb=box.querySelector('input[value="kembalian"]'); if(rb) rb.checked=true;
    }
}
function renderPecahanPiutang(){
    const box=document.getElementById('boxPecahanPiutang'); const btns=document.getElementById('pecahanPiutangButtons'); const sum=document.getElementById('pecahanPiutangSummary'); const hidden=document.getElementById('pecahanBayarPiutangJson');
    if(!box||!btns||!sum||!hidden) return;
    if(metodePiutang()!=='Cash'){ box.style.display='none'; hidden.value='{}'; renderKelebihanPiutang(); return; }
    box.style.display='block';
    btns.innerHTML=PECAHAN_PIUTANG.map(p=>{ const q=parseInt(pecahanPiutangDipilih[p]||0); return '<button type="button" class="btn btn-sm '+(q>0?'btn-success':'btn-outline-success')+' py-1 px-2" style="font-size:0.68rem" onclick="tambahPecahanPiutang('+p+')">'+formatRpPiutang(p).replace('Rp ','')+(q>0?' <b>×'+q+'</b>':'')+'</button>'; }).join('');
    const total=totalPecahanPiutang(pecahanPiutangDipilih);
    if(total>0){ sum.innerHTML='<b>Total tunai:</b> '+formatRpPiutang(total)+'<br><span class="text-muted">'+pecahanTextPiutang(pecahanPiutangDipilih)+'</span>'; hidden.value=JSON.stringify(pecahanPiutangDipilih); }
    else { const inp=inputNominalPiutang(); const manual=inp ? parseFloat(inp.value||0) : 0; const auto=pecahanObjFromNominalPiutang(manual); sum.innerHTML=manual>0?'<span class="text-muted">Terdeteksi: '+pecahanTextPiutang(auto)+'</span>':'Tap pecahan uang yang diterima, atau isi nominal manual.'; hidden.value=JSON.stringify(auto); }
    renderKelebihanPiutang();
}
function tambahPecahanPiutang(nominal){
    pecahanPiutangDipilih[nominal]=(parseInt(pecahanPiutangDipilih[nominal]||0)||0)+1;
    const inp=inputNominalPiutang(); if(inp) inp.value=totalPecahanPiutang(pecahanPiutangDipilih);
    renderPecahanPiutang();
}
function resetPecahanPiutang(){ pecahanPiutangDipilih={}; const inp=inputNominalPiutang(); if(inp) inp.value=''; renderPecahanPiutang(); }
function setNominalPiutangCash(inputId, val){ const inp=document.getElementById(inputId); if(inp) inp.value=val; pecahanPiutangDipilih={}; renderPecahanPiutang(); }
document.querySelector('select[name="metode"]')?.addEventListener('change', renderPecahanPiutang);
document.getElementById('inputNominalCicilan')?.addEventListener('input', function(){ if(totalPecahanPiutang(pecahanPiutangDipilih)!==parseFloat(this.value||0)) pecahanPiutangDipilih={}; renderPecahanPiutang(); });
document.getElementById('inputNominalSekaligus')?.addEventListener('input', function(){ if(totalPecahanPiutang(pecahanPiutangDipilih)!==parseFloat(this.value||0)) pecahanPiutangDipilih={}; renderPecahanPiutang(); });
renderPecahanPiutang();

function simpanPiutangSekaligusAjax() {
    let form = document.getElementById('formBayarSekaligus');
    if (!form.reportValidity()) return;

    let formData = new FormData(form);
    let btn = document.getElementById('btnSimpanSekaligus');
    let oriText = btn.innerHTML;

    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Memproses...';
    btn.disabled = true;

    fetch('bayar_piutang_sekaligus.php', { method: 'POST', body: formData })
    .then(res => res.text())
    .then(text => {
        let data;
        try {
            let jsonStart = text.indexOf('{'); let jsonEnd = text.lastIndexOf('}');
            data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
        } catch(e) {
            console.error('Respon Server:', text);
            Swal.fire('Error', 'Format data dari server rusak.', 'error');
            btn.innerHTML = oriText; btn.disabled = false;
            return;
        }

        if (data.status === 'success') {
            Swal.fire({
                icon: 'success', title: 'Berhasil!', text: data.message,
                timer: 1000, showConfirmButton: false, allowOutsideClick: false
            }).then(() => {
                let modalEl = document.getElementById('modalBayarCicilan');
                let modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();

                if (data.sisa_customer && parseFloat(data.sisa_customer) >= 100 && data.kode_pelanggan) {
                    $('#kontenModalRincian').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Memperbarui rincian piutang...</div>');
                    $.get('rincian_piutang.php?kode=' + encodeURIComponent(data.kode_pelanggan), function(html) {
                        $('#kontenModalRincian').html(html);
                    }).fail(function() {
                        Swal.fire('Error', 'Gagal memperbarui rincian piutang.', 'error');
                    });
                } else {
                    window.location.reload();
                }
            });
        } else {
            Swal.fire('Gagal', data.message, 'error');
            btn.innerHTML = oriText; btn.disabled = false;
        }
    })
    .catch(err => {
        Swal.fire('Koneksi Gagal', 'Tidak bisa menghubungi server.', 'error');
        btn.innerHTML = oriText; btn.disabled = false;
    });
}
</script>
