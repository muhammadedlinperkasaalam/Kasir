<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

function rupiah($angka) {
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function hitungRangeTanggal($periode, $custom_awal = '', $custom_akhir = '') {
    $today = date('Y-m-d');
    switch ($periode) {
        case 'kemarin':
            $awal = date('Y-m-d', strtotime('-1 day'));
            $akhir = $awal;
            $label = 'Transaksi Kemarin';
            break;
        case '7hari':
            $awal = date('Y-m-d', strtotime('-6 days'));
            $akhir = $today;
            $label = '7 Hari Terakhir';
            break;
        case 'bulan_ini':
            $awal = date('Y-m-01');
            $akhir = $today;
            $label = 'Bulan Ini';
            break;
        case 'custom':
            $awal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_awal) ? $custom_awal : $today;
            $akhir = preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_akhir) ? $custom_akhir : $today;
            if (strtotime($awal) > strtotime($akhir)) {
                $tmp = $awal; $awal = $akhir; $akhir = $tmp;
            }
            $label = 'Custom Periode';
            break;
        default:
            $awal = date('Y-m-01');
            $akhir = $today;
            $label = 'Bulan Ini';
            $periode = 'bulan_ini';
            break;
    }
    return [$periode, $awal, $akhir, $label];
}

$periode = $_GET['periode'] ?? 'bulan_ini';
$custom_awal = $_GET['tgl_awal'] ?? '';
$custom_akhir = $_GET['tgl_akhir'] ?? '';
$search = trim($_GET['q'] ?? '');
[$periode, $tgl_awal, $tgl_akhir, $label_periode] = hitungRangeTanggal($periode, $custom_awal, $custom_akhir);

$where = "WHERE p.tgl_penjualan BETWEEN :awal AND :akhir";
$params = [':awal' => $tgl_awal, ':akhir' => $tgl_akhir];
if ($search !== '') {
    $where .= " AND (CONVERT(p.no_penjualan USING utf8mb4) LIKE :q_nota OR CONVERT(pl.nama_pelanggan USING utf8mb4) LIKE :q_nama OR CONVERT(pl.no_telepon USING utf8mb4) LIKE :q_telp)";
    $keyword = '%' . $search . '%';
    $params[':q_nota'] = $keyword;
    $params[':q_nama'] = $keyword;
    $params[':q_telp'] = $keyword;
}

$sql = "SELECT 
            p.no_penjualan,
            p.tgl_penjualan,
            p.jam,
            p.kode_pelanggan,
            COALESCE(CONVERT(pl.nama_pelanggan USING utf8mb4), 'Tanpa Nama') AS nama_pelanggan,
            COALESCE(CONVERT(pl.no_telepon USING utf8mb4), '-') AS no_telepon,
            p.metode_pembayaran,
            p.pelunasan,
            COALESCE(p.uang_bayar, 0) AS uang_bayar,
            COALESCE(p.total_omzet, 0) AS total_omzet,
            COALESCE(p.diskon_global, 0) AS diskon_global,
            COALESCE(p.ongkir, 0) AS ongkir,
            COALESCE(p.nominal_deposit, 0) AS nominal_deposit,
            COUNT(pi.kode_barang) AS jumlah_item,
            COALESCE(SUM(COALESCE(pi.subtotal, ((COALESCE(pi.harga_jual,0) - COALESCE(pi.diskon,0)) * COALESCE(pi.jumlah,0)))), 0) AS subtotal_item,
            GROUP_CONCAT(CONCAT(CONVERT(COALESCE(b.nama_barang, pi.kode_barang, '-') USING utf8mb4) COLLATE utf8mb4_general_ci, CONVERT(' x' USING utf8mb4) COLLATE utf8mb4_general_ci, CONVERT(TRIM(TRAILING '.00' FROM FORMAT(COALESCE(pi.jumlah,0), 2)) USING utf8mb4) COLLATE utf8mb4_general_ci) ORDER BY pi.kode_barang SEPARATOR ', ') AS ringkasan_item
        FROM penjualan p
        LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
        LEFT JOIN penjualan_item pi ON p.no_penjualan = pi.no_penjualan
        LEFT JOIN barang b ON pi.kode_barang = b.kode_barang
        $where
        GROUP BY p.no_penjualan, p.tgl_penjualan, p.jam, p.kode_pelanggan, pl.nama_pelanggan, pl.no_telepon,
                 p.metode_pembayaran, p.pelunasan, p.uang_bayar, p.total_omzet, p.diskon_global, p.ongkir, p.nominal_deposit
        ORDER BY nama_pelanggan ASC, p.tgl_penjualan DESC, p.jam DESC, p.no_penjualan DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$customers = [];
$total_nota = 0;
$total_omzet = 0;
foreach ($rows as $row) {
    $kode = $row['kode_pelanggan'] ?: 'TANPA_KODE';
    $grand = (float)$row['total_omzet'];
    if ($grand <= 0) {
        $grand = max(0, (float)$row['subtotal_item'] - (float)$row['diskon_global'] + (float)$row['ongkir']);
    }
    $row['grand_total_hitung'] = $grand;
    if (!isset($customers[$kode])) {
        $customers[$kode] = [
            'kode_pelanggan' => $kode,
            'nama_pelanggan' => $row['nama_pelanggan'],
            'no_telepon' => $row['no_telepon'],
            'jumlah_nota' => 0,
            'total_belanja' => 0,
            'total_bayar' => 0,
            'nota' => []
        ];
    }
    $customers[$kode]['jumlah_nota']++;
    $customers[$kode]['total_belanja'] += $grand;
    $customers[$kode]['total_bayar'] += (float)$row['uang_bayar'];
    $customers[$kode]['nota'][] = $row;
    $total_nota++;
    $total_omzet += $grand;
}

uasort($customers, function($a, $b) {
    if ($a['total_belanja'] == $b['total_belanja']) return $b['jumlah_nota'] <=> $a['jumlah_nota'];
    return $b['total_belanja'] <=> $a['total_belanja'];
});
$total_customer = count($customers);
$range_text = date('d/m/Y', strtotime($tgl_awal)) . ' s/d ' . date('d/m/Y', strtotime($tgl_akhir));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Customer & Nota Pembelian | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        :root { --primary-color: #0d6efd; }
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }
        .main-content { flex-grow: 1; padding: 24px; width: 100%; overflow-x: hidden; }
        .card-soft { background:#fff; border:1px solid #eef2f7; border-radius:18px; box-shadow:0 8px 22px rgba(15,23,42,.04); }
        .summary-card { border-radius:18px; padding:18px; background:white; border:1px solid #eef2f7; box-shadow:0 6px 18px rgba(15,23,42,.035); height:100%; }
        .summary-icon { width:42px; height:42px; border-radius:14px; display:flex; align-items:center; justify-content:center; }
        .customer-header { cursor:pointer; transition:.15s; }
        .customer-header:hover { background:#f8fafc; }
        .table th { font-size:.75rem; text-transform:uppercase; color:#64748b; letter-spacing:.02em; background:#f8fafc; }
        .table td { vertical-align:middle; font-size:.86rem; }
        .badge-soft-success { background:#dcfce7; color:#166534; }
        .badge-soft-warning { background:#fef3c7; color:#92400e; }
        .badge-soft-primary { background:#dbeafe; color:#1d4ed8; }
        .badge-soft-danger { background:#fee2e2; color:#991b1b; }
        .item-summary { max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .filter-box .form-control, .filter-box .form-select { border-radius:12px; }
        @media (max-width: 768px) {
            body { display:block; }
            .main-content { padding:16px; }
            .filter-box .btn, .filter-box .form-control, .filter-box .form-select { width:100%; }
            .item-summary { max-width:220px; }
        }
        @media print {
            .no-print, .sidebar-container { display:none !important; }
            body { display:block; background:white; }
            .main-content { padding:0; }
            .card-soft, .summary-card { box-shadow:none; border:1px solid #ddd; }
            .collapse { display:block !important; height:auto !important; visibility:visible !important; }
            .customer-header { background:#f8f9fa !important; }
        }
    </style>
</head>
<body>
<?php include '../sidebar.php'; ?>

<main class="main-content">
    <div class="container-fluid px-0">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="fas fa-users text-primary me-2"></i>Laporan Customer & Nota Pembelian</h4>
                <div class="text-muted small">Menampilkan customer beserta daftar nota transaksi pada periode <strong><?= h($range_text) ?></strong>.</div>
            </div>
            <button onclick="window.print()" class="btn btn-dark rounded-pill fw-bold px-4 no-print"><i class="fas fa-print me-2"></i>Cetak</button>
        </div>

        <div class="card-soft p-3 mb-4 no-print filter-box">
            <form method="GET" class="row g-2 align-items-end" id="filterForm">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label small fw-bold text-muted">Menu Tanggal</label>
                    <select name="periode" id="periode" class="form-select" onchange="toggleCustomPeriode(); this.form.submit();">
                        <option value="kemarin" <?= $periode === 'kemarin' ? 'selected' : '' ?>>Transaksi Kemarin</option>
                        <option value="7hari" <?= $periode === '7hari' ? 'selected' : '' ?>>7 Hari Terakhir</option>
                        <option value="bulan_ini" <?= $periode === 'bulan_ini' ? 'selected' : '' ?>>Bulan Ini</option>
                        <option value="custom" <?= $periode === 'custom' ? 'selected' : '' ?>>Custom Periode</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6 custom-periode">
                    <label class="form-label small fw-bold text-muted">Tanggal Awal</label>
                    <input type="date" name="tgl_awal" class="form-control" value="<?= h($tgl_awal) ?>">
                </div>
                <div class="col-lg-2 col-md-6 custom-periode">
                    <label class="form-label small fw-bold text-muted">Tanggal Akhir</label>
                    <input type="date" name="tgl_akhir" class="form-control" value="<?= h($tgl_akhir) ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label small fw-bold text-muted">Cari Customer / No Nota</label>
                    <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Nama customer / nota / telepon">
                </div>
                <div class="col-lg-2 col-md-12 d-grid">
                    <button type="submit" class="btn btn-primary rounded-pill fw-bold"><i class="fas fa-filter me-2"></i>Tampilkan</button>
                </div>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-bold">Total Customer</div>
                            <div class="h4 fw-bold mb-0"><?= number_format($total_customer, 0, ',', '.') ?></div>
                        </div>
                        <div class="summary-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-user-friends"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-bold">Total Nota</div>
                            <div class="h4 fw-bold mb-0"><?= number_format($total_nota, 0, ',', '.') ?></div>
                        </div>
                        <div class="summary-icon bg-success bg-opacity-10 text-success"><i class="fas fa-receipt"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small fw-bold">Total Pembelian</div>
                            <div class="h4 fw-bold mb-0"><?= rupiah($total_omzet) ?></div>
                        </div>
                        <div class="summary-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-coins"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-soft overflow-hidden">
            <div class="p-3 border-bottom bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="fw-bold"><i class="fas fa-list me-2 text-primary"></i>Data Customer → Nota Pembelian</div>
                <span class="badge badge-soft-primary px-3 py-2"><?= h($label_periode) ?>: <?= h($range_text) ?></span>
            </div>

            <?php if (empty($customers)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-search fa-2x mb-3 opacity-50"></i>
                    <div class="fw-bold">Tidak ada transaksi pada periode ini.</div>
                    <div class="small">Coba ubah filter tanggal atau pencarian.</div>
                </div>
            <?php else: ?>
                <div class="accordion accordion-flush" id="accordionCustomerNota">
                    <?php $i = 0; foreach ($customers as $cust): $i++; $collapseId = 'custNota' . $i; ?>
                    <div class="accordion-item border-0 border-bottom">
                        <div class="customer-header p-3" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>" aria-expanded="<?= $i === 1 ? 'true' : 'false' ?>">
                            <div class="row g-2 align-items-center">
                                <div class="col-lg-5">
                                    <div class="fw-bold text-dark"><i class="fas fa-user-circle text-primary me-2"></i><?= h($cust['nama_pelanggan']) ?></div>
                                    <div class="small text-muted ms-4"><?= h($cust['kode_pelanggan']) ?> · <?= h($cust['no_telepon']) ?></div>
                                </div>
                                <div class="col-lg-2 col-6">
                                    <span class="badge badge-soft-primary px-3 py-2"><i class="fas fa-receipt me-1"></i><?= number_format($cust['jumlah_nota'], 0, ',', '.') ?> nota</span>
                                </div>
                                <div class="col-lg-3 col-6 text-lg-end">
                                    <div class="small text-muted">Total Pembelian</div>
                                    <div class="fw-bold text-success"><?= rupiah($cust['total_belanja']) ?></div>
                                </div>
                                <div class="col-lg-2 text-lg-end no-print">
                                    <button class="btn btn-sm btn-light border rounded-pill px-3" type="button">Lihat Nota <i class="fas fa-chevron-down ms-1 small"></i></button>
                                </div>
                            </div>
                        </div>
                        <div id="<?= $collapseId ?>" class="accordion-collapse collapse <?= $i === 1 ? 'show' : '' ?>" data-bs-parent="#accordionCustomerNota">
                            <div class="table-responsive px-3 pb-3">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>No Nota</th>
                                            <th>Metode</th>
                                            <th>Status</th>
                                            <th class="text-center">Item</th>
                                            <th>Ringkasan Barang</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Dibayar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cust['nota'] as $nota): 
                                            $lunas = strtoupper((string)$nota['pelunasan']) === 'Y';
                                            $dibayar = (float)$nota['uang_bayar'] + (float)$nota['nominal_deposit'];
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= date('d/m/Y', strtotime($nota['tgl_penjualan'])) ?></div>
                                                <div class="small text-muted"><?= h($nota['jam']) ?></div>
                                            </td>
                                            <td class="fw-bold text-primary"><?= h($nota['no_penjualan']) ?></td>
                                            <td><?= h($nota['metode_pembayaran'] ?: '-') ?></td>
                                            <td>
                                                <?php if ($lunas): ?>
                                                    <span class="badge badge-soft-success">Lunas</span>
                                                <?php else: ?>
                                                    <span class="badge badge-soft-warning">Belum Lunas</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?= number_format((float)$nota['jumlah_item'], 0, ',', '.') ?></td>
                                            <td><div class="item-summary" title="<?= h($nota['ringkasan_item'] ?: '-') ?>"><?= h($nota['ringkasan_item'] ?: '-') ?></div></td>
                                            <td class="text-end fw-bold"><?= rupiah($nota['grand_total_hitung']) ?></td>
                                            <td class="text-end"><?= rupiah($dibayar) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleCustomPeriode() {
    const periode = document.getElementById('periode').value;
    document.querySelectorAll('.custom-periode').forEach(function(el) {
        el.style.display = (periode === 'custom') ? '' : 'none';
    });
}
document.addEventListener('DOMContentLoaded', toggleCustomPeriode);
</script>
</body>
</html>
