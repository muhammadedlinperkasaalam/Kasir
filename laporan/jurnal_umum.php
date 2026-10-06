<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- 1. SETTING ---
$tgl_awal  = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$tgl_akhir = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');
$limit = 50; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// --- 2. HITUNG GRAND TOTAL (KESELURUHAN) ---
// Kita hitung total untuk ditampilkan di Halaman Terakhir saja
$sql_summary = "SELECT 
                    (SELECT SUM(total_omzet) FROM penjualan WHERE tgl_penjualan BETWEEN :a1 AND :b1) as tot_omzet,
                    (SELECT SUM(total_laba) FROM penjualan WHERE tgl_penjualan BETWEEN :a2 AND :b2) as tot_laba,
                    (SELECT SUM(jumlah) FROM pengeluaran_non_stok WHERE tanggal BETWEEN :a3 AND :b3) as tot_beban";

$stmt_sum = $pdo->prepare($sql_summary);
$stmt_sum->execute(['a1'=>$tgl_awal, 'b1'=>$tgl_akhir, 'a2'=>$tgl_awal, 'b2'=>$tgl_akhir, 'a3'=>$tgl_awal, 'b3'=>$tgl_akhir]);
$sum_data = $stmt_sum->fetch(PDO::FETCH_ASSOC);

// Hitung Grand Total Debit/Kredit untuk Jurnal
// Debit = HPP + Kas Masuk (Omzet) + Beban
// HPP = Omzet - Laba
$val_omzet = $sum_data['tot_omzet'] ?? 0;
$val_hpp   = ($sum_data['tot_omzet'] ?? 0) - ($sum_data['tot_laba'] ?? 0);
$val_beban = $sum_data['tot_beban'] ?? 0;

$grand_total_jurnal = $val_omzet + $val_hpp + $val_beban;


// --- 3. QUERY DATA TABEL (PAGINATED) ---
$sql_union = "
    SELECT SQL_CALC_FOUND_ROWS * FROM (
        -- PENJUALAN
        SELECT 
            p.tgl_penjualan as tgl, 
            p.no_penjualan as ref, 
            p.keterangan as ket, 
            'SALES' as sumber,
            p.pelunasan,
            p.total_omzet as nominal_1,        
            (p.total_omzet - p.total_laba) as nominal_2, 
            '' as kategori_biaya
        FROM penjualan p
        WHERE p.tgl_penjualan BETWEEN :awal1 AND :akhir1

        UNION ALL

        -- PENGELUARAN
        SELECT 
            e.tanggal as tgl, 
            e.kode_pengeluaran as ref, 
            e.keterangan as ket, 
            'EXPENSE' as sumber,
            '-' as pelunasan,
            e.jumlah as nominal_1,
            0 as nominal_2,
            k.nama_kategori as kategori_biaya
        FROM pengeluaran_non_stok e
        LEFT JOIN kategori_pengeluaran k ON e.kode_kategori_pengeluaran = k.kode_kategori
        WHERE e.tanggal BETWEEN :awal2 AND :akhir2
    ) AS gabungan
    ORDER BY tgl DESC, ref DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql_union);
$stmt->bindValue(':awal1', $tgl_awal);
$stmt->bindValue(':akhir1', $tgl_akhir);
$stmt->bindValue(':awal2', $tgl_awal);
$stmt->bindValue(':akhir2', $tgl_akhir);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung Halaman
$total_rows = $pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
$total_pages = ceil($total_rows / $limit);
if ($total_pages == 0) $total_pages = 1; // Cegah error jika data kosong

// --- 4. SUSUN ARRAY JURNAL ---
$journal_entries = [];

foreach ($raw_data as $row) {
    if ($row['sumber'] == 'SALES') {
        $omzet = $row['nominal_1'];
        $hpp   = $row['nominal_2'];
        
        // HPP
        $journal_entries[] = ['tgl'=>$row['tgl'], 'ref'=>$row['ref'], 'akun'=>'Harga Pokok Penjualan', 'ket'=>'HPP Penjualan', 'debit'=>$hpp, 'kredit'=>0, 'tipe'=>'dr', 'group'=>$row['ref']];
        $journal_entries[] = ['tgl'=>$row['tgl'], 'ref'=>$row['ref'], 'akun'=>'Persediaan Barang', 'ket'=>'Pengurangan Stok', 'debit'=>0, 'kredit'=>$hpp, 'tipe'=>'cr', 'group'=>$row['ref']];

        // SALES
        $akun_debit = 'Kas Toko';
        if ($row['pelunasan'] == 'N') $akun_debit = 'Piutang Usaha';
        elseif (preg_match('/\{\{(.*?):/', $row['ket'], $matches)) $akun_debit = 'Bank / ' . strtoupper($matches[1]);

        $journal_entries[] = ['tgl'=>$row['tgl'], 'ref'=>$row['ref'], 'akun'=>$akun_debit, 'ket'=>'Penjualan Barang', 'debit'=>$omzet, 'kredit'=>0, 'tipe'=>'dr', 'group'=>$row['ref']];
        $journal_entries[] = ['tgl'=>$row['tgl'], 'ref'=>$row['ref'], 'akun'=>'Pendapatan Penjualan', 'ket'=>'Omzet Penjualan', 'debit'=>0, 'kredit'=>$omzet, 'tipe'=>'cr', 'group'=>$row['ref']];

    } else {
        $nominal = $row['nominal_1'];
        $kat = $row['kategori_biaya'] ?? 'Umum';

        $journal_entries[] = ['tgl'=>$row['tgl'], 'ref'=>$row['ref'], 'akun'=>'Beban '.$kat, 'ket'=>$row['ket'], 'debit'=>$nominal, 'kredit'=>0, 'tipe'=>'dr', 'group'=>$row['ref']];
        $journal_entries[] = ['tgl'=>$row['tgl'], 'ref'=>$row['ref'], 'akun'=>'Kas Toko', 'ket'=>'Keluar Kas', 'debit'=>0, 'kredit'=>$nominal, 'tipe'=>'cr', 'group'=>$row['ref']];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jurnal Umum | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap'); /* For Numbers */
        
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Card & Table Custom */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: white; overflow: hidden; }
        .card-header-custom { padding: 18px 25px; border-bottom: 1px solid #f1f5f9; background: white; }
        
        .table-custom th { border-bottom: 2px solid #e9ecef; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; padding: 15px; background-color: #f8fafc; }
        .table-custom td { vertical-align: middle; padding: 10px 15px; font-size: 0.85rem; }
        
        /* Journal Specific Styling */
        .num { text-align: right; font-family: 'JetBrains Mono', monospace; letter-spacing: 0.5px; }
        .kredit-row td:nth-child(3) { padding-left: 2.5rem; } /* Indentasi Akun Kredit */
        
        /* Grup Transaksi (Belang-belang per Transaksi, bukan per baris) */
        .group-bg-1 { background-color: #ffffff; }
        .group-bg-2 { background-color: #f8fafc; }
        .group-row td { border-bottom: none; }
        .group-row:last-child td { border-bottom: 1px dashed #e2e8f0; }

        /* Pagination Custom */
        .pagination { flex-wrap: wrap; justify-content: center; margin-bottom: 0; }
        .page-item .page-link { border: none; color: #6c757d; font-weight: 500; margin: 0 3px; border-radius: 8px; }
        .page-item.active .page-link { background-color: #0d6efd; color: white; box-shadow: 0 2px 5px rgba(13,110,253,0.3); }

        @media print { 
            .no-print { display: none !important; } 
            .sidebar { display: none !important; }
            body { font-size: 11px; -webkit-print-color-adjust: exact; print-color-adjust: exact; background: white; }
            .main-content { padding: 0; width: 100%; }
            .card-custom { box-shadow: none; border: none; }
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-book-open text-primary me-2"></i> Jurnal Umum (Ledger)</h4>
                <p class="text-muted small mb-0 mt-1">Pencatatan riwayat kronologis seluruh transaksi (Debit & Kredit).</p>
            </div>
            
            <div class="bg-white p-2 rounded-pill shadow-sm border border-light d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0">
                    <div class="d-flex align-items-center bg-light rounded-pill px-3 py-1 me-2 border">
                        <i class="fas fa-calendar-alt text-muted me-2 small"></i>
                        <input type="date" name="start" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_awal ?>" onchange="this.form.submit()">
                        <span class="mx-2 text-muted small">s/d</span>
                        <input type="date" name="end" class="form-control form-control-sm border-0 bg-transparent shadow-none p-0 fw-medium text-dark" value="<?= $tgl_akhir ?>" onchange="this.form.submit()">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3 me-1"><i class="fas fa-filter me-1"></i> Filter</button>
                    <div class="vr mx-2 opacity-25"></div>
                    <button type="button" onclick="window.print()" class="btn btn-dark btn-sm rounded-pill fw-bold px-3"><i class="fas fa-print me-1"></i> Cetak</button>
                </form>
            </div>
        </div>

        <div class="card card-custom mb-4">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">Rincian Jurnal (<?= date('d M Y', strtotime($tgl_awal)) ?> - <?= date('d M Y', strtotime($tgl_akhir)) ?>)</h6>
                <span class="badge bg-light text-dark border shadow-sm px-2 py-1">Hal <?= $page ?> dari <?= $total_pages ?></span>
            </div>
            
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th class="text-center" width="12%">Tanggal</th>
                                <th class="text-center" width="15%">No. Ref</th>
                                <th>Keterangan / Akun</th>
                                <th class="text-end" width="18%">Debit (Rp)</th>
                                <th class="text-end" width="18%">Kredit (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prev_group = '';
                            $bg_class = 'group-bg-1';

                            foreach($journal_entries as $index => $row): 
                                // Ganti warna background PER KELOMPOK transaksi (ref yang sama)
                                if ($prev_group != $row['group']) {
                                    $prev_group = $row['group'];
                                    $bg_class = ($bg_class == 'group-bg-1') ? 'group-bg-2' : 'group-bg-1';
                                }
                                
                                // Menentukan apakah ini baris terakhir dalam grupnya untuk border-bottom
                                $is_last_in_group = false;
                                if (!isset($journal_entries[$index + 1]) || $journal_entries[$index + 1]['group'] != $row['group']) {
                                    $is_last_in_group = true;
                                }
                            ?>
                            <tr class="<?= $bg_class ?> group-row <?= $is_last_in_group ? 'border-bottom' : '' ?> <?= $row['tipe'] == 'cr' ? 'kredit-row' : '' ?>">
                                <td class="text-center text-muted"><?= date('d M Y', strtotime($row['tgl'])) ?></td>
                                <td class="text-center fw-medium font-monospace text-secondary small"><?= $row['ref'] ?></td>
                                <td>
                                    <?php if($row['tipe'] == 'dr'): ?>
                                        <span class="fw-bold text-dark"><?= $row['akun'] ?></span>
                                        <div class="text-muted" style="font-size:0.75rem; margin-top:2px;">(<?= htmlspecialchars($row['ket']) ?>)</div>
                                    <?php else: ?>
                                        <span class="text-primary fst-italic"><?= $row['akun'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="num text-dark">
                                    <?= $row['debit'] > 0 ? number_format($row['debit'], 0, ',', '.') : '-' ?>
                                </td>
                                <td class="num text-success">
                                    <?= $row['kredit'] > 0 ? number_format($row['kredit'], 0, ',', '.') : '-' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($journal_entries)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3 text-light"></i><br>
                                    Tidak ada data jurnal pada periode ini.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        
                        <?php if ($page == $total_pages && !empty($journal_entries)): ?>
                        <tfoot class="bg-dark text-white">
                            <tr>
                                <td colspan="3" class="text-end fw-bold text-uppercase py-3" style="letter-spacing: 1px;">
                                    Grand Total (Keseluruhan)
                                </td>
                                <td class="num fw-bold py-3 text-warning">
                                    <?= number_format($grand_total_jurnal, 0, ',', '.') ?>
                                </td>
                                <td class="num fw-bold py-3 text-warning">
                                    <?= number_format($grand_total_jurnal, 0, ',', '.') ?>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>

                    </table>
                </div>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white no-print py-3 border-0">
                <nav>
                    <ul class="pagination">
                        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link shadow-sm border bg-light" href="?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&page=<?= $page - 1 ?>"><i class="fas fa-chevron-left"></i></a>
                        </li>

                        <?php 
                        // Smart Pagination Logic
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>';
                        
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                                <a class="page-link shadow-sm <?= ($i != $page) ? 'bg-light border' : '' ?>" href="?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>'; ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link shadow-sm border bg-light" href="?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&page=<?= $page + 1 ?>"><i class="fas fa-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
            
        </div>
        
    </div>

</body>
</html>
