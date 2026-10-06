<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// ====================================================================================
// FUNGSI AJAX UPDATE STATUS PRINT
// ====================================================================================
if (isset($_POST['action']) && $_POST['action'] == 'update_log_print') {
    ob_clean();
    header('Content-Type: application/json');
    
    $tgl_laporan = $_POST['tgl'];
    $kode_user = $_POST['user'];
    $waktu_sekarang = date('Y-m-d H:i:s');

    try {
        $cek_log = $pdo->prepare("SELECT COUNT(*) FROM log_setoran WHERE tgl_laporan = ? AND kode_user = ?");
        $cek_log->execute([$tgl_laporan, $kode_user]);
        $ada_log = $cek_log->fetchColumn();

        if ($ada_log > 0) {
            $upd = $pdo->prepare("UPDATE log_setoran SET waktu_cetak = ? WHERE tgl_laporan = ? AND kode_user = ?");
            $upd->execute([$waktu_sekarang, $tgl_laporan, $kode_user]);
        } else {
            $ins = $pdo->prepare("INSERT INTO log_setoran (tgl_laporan, kode_user, waktu_cetak) VALUES (?, ?, ?)");
            $ins->execute([$tgl_laporan, $kode_user, $waktu_sekarang]);
        }
        
        echo json_encode(['status' => 'success']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$default_awal  = date('Y-m-d', strtotime('-30 days')); 
$default_akhir = date('Y-m-d');
$tgl_awal  = $_GET['tgl_awal'] ?? $default_awal;
$tgl_akhir = $_GET['tgl_akhir'] ?? $default_akhir;

// --- HELPERS ---
function cleanNum($str) {
    $str = preg_replace('/[^0-9,.]/', '', $str);
    if (strpos($str, '.') !== false && strpos($str, ',') !== false) {
        $str = str_replace('.', '', $str); $str = str_replace(',', '.', $str);
    } elseif (strpos($str, '.') !== false) {
        if (substr_count($str, '.') > 1 || strlen(explode('.', $str)[1]) == 3) $str = str_replace('.', '', $str);
    }
    return (float)$str;
}

function getMetode($teks) {
    $t = strtolower($teks); 
    if (preg_match('/(qris|transfer|trf|tf|bca|bri|bni|mandiri|bsi|qr|gesek|debit|dana|gopay|ovo)/', $t)) return 'NON_TUNAI';
    return 'CASH'; 
}

// ====================================================================================
// 1. QUERY DATA KOMPREHENSIF (PENJUALAN + ARUS KAS + PENGELUARAN + LOG SETOR)
// ====================================================================================

// Ambil Status Print (Log Setoran)
$sql_log = "SELECT tgl_laporan, kode_user, waktu_cetak FROM log_setoran WHERE tgl_laporan BETWEEN ? AND ?";
$stmt_log = $pdo->prepare($sql_log);
$stmt_log->execute([$tgl_awal, $tgl_akhir]);
$logs_print = [];
foreach($stmt_log->fetchAll(PDO::FETCH_ASSOC) as $lg) {
    $logs_print[$lg['tgl_laporan'] . '_' . $lg['kode_user']] = $lg['waktu_cetak'];
}

// Ambil Transaksi Penjualan Utama (Untuk Omzet & Pembayaran Awal/DP)
$sql = "SELECT p.no_penjualan, p.tgl_penjualan, p.kode_user, p.uang_bayar, p.keterangan, p.metode_pembayaran, p.is_verif_qris, p.tgl_verif_qris,
               CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END as nilai_real
        FROM penjualan p
        WHERE (p.tgl_penjualan BETWEEN ? AND ?) 
           OR (p.is_verif_qris = 'Y' AND p.tgl_verif_qris BETWEEN ? AND ?)
           OR (p.keterangan LIKE '%/%') -- Fallback regex
        ORDER BY p.tgl_penjualan DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$tgl_awal, $tgl_akhir, $tgl_awal, $tgl_akhir]);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil Cicilan dari Arus Kas (Struktur Baru)
$sql_arus = "SELECT tanggal, kode_user, jumlah_masuk, metode_pembayaran 
             FROM arus_kas 
             WHERE jenis = 'Pemasukan' AND no_penjualan IS NOT NULL AND tanggal BETWEEN ? AND ?";
$stmt_arus = $pdo->prepare($sql_arus);
$stmt_arus->execute([$tgl_awal, $tgl_akhir]);
$raw_arus_kas = $stmt_arus->fetchAll(PDO::FETCH_ASSOC);

// Ambil Pengeluaran (Untuk Potongan Setoran Amplop)
$sql_out = "SELECT * FROM pengeluaran_non_stok WHERE tanggal BETWEEN ? AND ?";
$stmt_out = $pdo->prepare($sql_out);
$stmt_out->execute([$tgl_awal, $tgl_akhir]);
$raw_pengeluaran = $stmt_out->fetchAll(PDO::FETCH_ASSOC);

// ====================================================================================
// 2. PROSES LOGIKA PENGGABUNGAN DATA
// ====================================================================================
$laporan = [];

function tambahLaporan(&$arr, $tgl, $user, $omzet, $cash_in, $non_tunai) {
    global $logs_print;
    $key = $tgl . '_' . $user;
    if (!isset($arr[$key])) {
        $arr[$key] = [
            'tgl' => $tgl, 
            'user' => $user, 
            'omzet' => 0, 
            'cash_in' => 0, 
            'non_tunai' => 0, 
            'keluar_tunai' => 0,
            'waktu_cetak' => $logs_print[$key] ?? null
        ];
    }
    $arr[$key]['omzet'] += $omzet;
    $arr[$key]['cash_in'] += $cash_in;
    $arr[$key]['non_tunai'] += $non_tunai;
}

// A. Proses Tabel Penjualan
foreach ($raw_data as $row) {
    $tgl_nota = date('Y-m-d', strtotime($row['tgl_penjualan']));
    $user = $row['kode_user'];
    
    if ($tgl_nota >= $tgl_awal && $tgl_nota <= $tgl_akhir) {
        tambahLaporan($laporan, $tgl_nota, $user, $row['nilai_real'], 0, 0);
    }

    $ket_bersih = preg_replace('/\b202[0-9]\b/', '', $row['keterangan'] ?? '');
    $uang_bayar_db = (float)$row['uang_bayar']; 
    $total_pindah_tgl = 0;

    $pattern = '/(\d{1,2}[\/\-]\d{1,2}).*?(QRIS|Transfer|TRF|TF|BCA|BRI|DEBIT|CASH|TUNAI).*?[:\s]+([0-9.,]{4,})/i';
    if (preg_match_all($pattern, $ket_bersih, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $parts = explode('/', str_replace('-', '/', $m[1]));
            $tgl_c = date('Y', strtotime($tgl_nota)) . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $metode_c = getMetode($m[2]);
            $nominal_c = cleanNum($m[3]);
            
            if ($nominal_c >= 100) {
                if ($tgl_c >= $tgl_awal && $tgl_c <= $tgl_akhir) {
                    if ($metode_c == 'CASH') tambahLaporan($laporan, $tgl_c, $user, 0, $nominal_c, 0);
                    else tambahLaporan($laporan, $tgl_c, $user, 0, 0, $nominal_c);
                }
                $total_pindah_tgl += $nominal_c;
            }
        }
    }

    $dp_murni = $uang_bayar_db - $total_pindah_tgl;
    if ($dp_murni > 100) {
        $is_qris_verif = ($row['is_verif_qris'] == 'Y');
        $tgl_bayar = ($is_qris_verif && !empty($row['tgl_verif_qris'])) ? $row['tgl_verif_qris'] : $tgl_nota;
        
        if ($tgl_bayar >= $tgl_awal && $tgl_bayar <= $tgl_akhir) {
            $metode_dp = !empty($row['metode_pembayaran']) ? $row['metode_pembayaran'] : getMetode(preg_replace($pattern, '', $ket_bersih));
            
            $is_non_tunai = (getMetode($metode_dp) == 'NON_TUNAI') || $is_qris_verif;
            
            if ($is_non_tunai) tambahLaporan($laporan, $tgl_bayar, $user, 0, 0, $dp_murni);
            else tambahLaporan($laporan, $tgl_bayar, $user, 0, $dp_murni, 0);
        }
    }
}

// B. Proses Tabel Arus Kas (Cicilan Baru)
foreach ($raw_arus_kas as $ak) {
    $tgl_ak = date('Y-m-d', strtotime($ak['tanggal']));
    $user_ak = $ak['kode_user'];
    $nominal_ak = (float)$ak['jumlah_masuk'];
    $metode_ak = $ak['metode_pembayaran'] ?? 'Cash';
    
    if ($nominal_ak > 0) {
        if (getMetode($metode_ak) == 'NON_TUNAI') {
            tambahLaporan($laporan, $tgl_ak, $user_ak, 0, 0, $nominal_ak);
        } else {
            tambahLaporan($laporan, $tgl_ak, $user_ak, 0, $nominal_ak, 0);
        }
    }
}

// C. Proses Tabel Pengeluaran (Memotong Setoran Tunai)
foreach ($raw_pengeluaran as $p) {
    if (!preg_match('/(transfer|trf|qris|bank|non|debit)/i', $p['metode_bayar'].$p['keterangan'])) {
        foreach ($laporan as $k => $v) {
            if ($v['tgl'] == $p['tanggal']) { 
                $laporan[$k]['keluar_tunai'] += $p['jumlah']; 
                break; 
            }
        }
    }
}

// Urutkan laporan dari tanggal terbaru
krsort($laporan);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Setoran Kasir | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }
        
        /* Sidebar Styling (Kesesuaian dengan Dashboard) */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; }
        .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        
        /* Cards & Tables */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); background: white; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        .table-custom th { border-bottom: 2px solid #e9ecef; color: #6c757d; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; padding-top: 15px; padding-bottom: 15px; }
        .table-custom td { vertical-align: middle; border-bottom: 1px solid #f1f3f5; padding-top: 15px; padding-bottom: 15px; }
        
        /* Specific Status Styling */
        .row-printed { background-color: #f9fdfa !important; }
        .badge-print { font-size: 0.7rem; background: #e6f4ea; color: #1e8e3e; border: 1px solid #cce8d6; padding: 5px 8px; border-radius: 6px; }
        .badge-unprint { font-size: 0.7rem; background: #fef0cd; color: #b06000; border: 1px solid #fce3a1; padding: 5px 8px; border-radius: 6px; }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="header-section">
            <div>
                <h4 class="fw-bold mb-0">Rekap Setoran Kasir</h4>
                <p class="text-muted small mb-0">Data cetak amplop dan riwayat setoran tunai harian.</p>
            </div>
            
            <div class="bg-white p-2 rounded-3 shadow-sm d-flex align-items-center">
                <form class="d-flex gap-2 mb-0" action="" method="GET">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-calendar-alt text-muted"></i></span>
                        <input type="date" name="tgl_awal" class="form-control border-start-0" value="<?= $tgl_awal ?>">
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0">s/d</span>
                        <input type="date" name="tgl_akhir" class="form-control border-start-0" value="<?= $tgl_akhir ?>">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-bold rounded-2">Filter</button>
                </form>
            </div>
        </div>

        <div class="card-custom p-4">
            <div class="table-responsive">
                <table class="table table-custom table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Nama Kasir</th>
                            <th class="text-end">Total Omzet</th>
                            <th class="text-end">Setor Tunai (Cash)</th>
                            <th class="text-center">Status Cetak</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($laporan as $row): 
                            $is_printed = !empty($row['waktu_cetak']);
                            $total_setor_tunai = $row['cash_in'] - $row['keluar_tunai'];
                        ?>
                        <tr class="<?= $is_printed ? 'row-printed' : '' ?>">
                            <td><b class="text-dark"><?= date('d M Y', strtotime($row['tgl'])) ?></b></td>
                            <td>
                                <span class="badge bg-light text-dark border"><i class="fas fa-user-circle text-primary me-1"></i> <?= $row['user'] ?></span>
                            </td>
                            <td class="text-end fw-bold text-dark">Rp <?= number_format($row['omzet']) ?></td>
                            <td class="text-end text-success fw-bold">Rp <?= number_format($total_setor_tunai > 0 ? $total_setor_tunai : 0) ?></td>
                            <td class="text-center td-status">
                                <?php if($is_printed): ?>
                                    <span class="badge-print"><i class="fas fa-check-double me-1"></i> Selesai</span>
                                    <div class="text-muted mt-1" style="font-size:10px"><?= date('H:i', strtotime($row['waktu_cetak'])) ?> WIB</div>
                                <?php else: ?>
                                    <span class="badge-unprint"><i class="fas fa-clock me-1"></i> Belum Print</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center td-aksi">
                                <button class="btn btn-sm <?= $is_printed ? 'btn-outline-primary' : 'btn-primary' ?> rounded-3 px-3 py-1 fw-medium" 
                                        onclick="prosesCetak('<?= $row['tgl'] ?>', '<?= $row['user'] ?>', this)">
                                    <i class="fas fa-print me-1"></i> <?= $is_printed ? 'Print Ulang' : 'Cetak Amplop' ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if(empty($laporan)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3 text-light"></i><br>
                                    Belum ada data setoran pada periode tanggal yang dipilih.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function prosesCetak(tgl, user, btnElement) {
            let originalHTML = $(btnElement).html();
            $(btnElement).html('<i class="fas fa-spinner fa-spin me-1"></i> Menyimpan...');
            $(btnElement).prop('disabled', true);

            // Buka tab print amplopnya (Tab Baru)
            window.open('cetak_amplop.php?tanggal=' + tgl + '&user=' + user, '_blank');
            
            // Simpan ke database via AJAX
            $.ajax({
                url: 'list_setor.php', // Mengarah ke file ini sendiri
                type: 'POST',
                data: { action: 'update_log_print', tgl: tgl, user: user },
                dataType: 'json',
                success: function(response) {
                    if(response.status === 'success') {
                        let $tr = $(btnElement).closest('tr');
                        $tr.addClass('row-printed');
                        
                        let jamSekarang = new Date().toLocaleTimeString('id-ID', {hour: '2-digit', minute:'2-digit'});
                        $tr.find('.td-status').html('<span class="badge-print"><i class="fas fa-check-double me-1"></i> Selesai</span><div class="text-muted mt-1" style="font-size:10px">' + jamSekarang + ' WIB</div>');
                        
                        $(btnElement).removeClass('btn-primary').addClass('btn-outline-primary').html('<i class="fas fa-print me-1"></i> Print Ulang');
                    } else {
                        alert("Gagal Update Status di Database: " + response.message);
                        $(btnElement).html(originalHTML);
                    }
                },
                error: function(xhr) {
                    alert("Sistem Error! Pesan sistem: " + xhr.responseText);
                    $(btnElement).html(originalHTML);
                },
                complete: function() {
                    $(btnElement).prop('disabled', false);
                }
            });
        }
    </script>
</body>
</html>