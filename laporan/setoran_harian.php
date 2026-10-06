<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- 1. SETTING TANGGAL ---
$tanggal = isset($_GET['tanggal']) ? $_GET['tanggal'] : date('Y-m-d');
$tahun_pilih = date('Y', strtotime($tanggal));

// --- 2. QUERY KEUANGAN HARIAN (OMZET HARI INI) ---
$sql = "SELECT 
            p.no_penjualan, p.keterangan, p.pelunasan, p.kode_pelanggan,
            COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) as total_jual,
            COALESCE(SUM(pi.harga_beli_bersih * pi.jumlah), 0) as total_modal
        FROM penjualan_item pi
        JOIN penjualan p ON pi.no_penjualan = p.no_penjualan
        WHERE DATE(p.tgl_penjualan) = ? 
        GROUP BY p.no_penjualan";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal]);
$transaksi_harian = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 3. QUERY PENGELUARAN HARIAN ---
$sql_out = "SELECT * FROM pengeluaran_non_stok WHERE tanggal = ?";
$stmt_out = $pdo->prepare($sql_out);
$stmt_out->execute([$tanggal]);
$pengeluaran = $stmt_out->fetchAll(PDO::FETCH_ASSOC);

// --- 4. QUERY KHUSUS DAFTAR UTANG (SEMUA YG BELUM LUNAS TAHUN INI) ---
$sql_piutang = "SELECT 
                    p.no_penjualan, 
                    p.tgl_penjualan,
                    pl.nama_pelanggan,
                    p.kode_pelanggan,
                    (SUM((pi.harga_jual * pi.jumlah) - pi.diskon) - p.uang_bayar) as sisa_utang
                FROM penjualan p
                JOIN penjualan_item pi ON p.no_penjualan = pi.no_penjualan
                LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                WHERE YEAR(p.tgl_penjualan) = ? 
                  AND p.pelunasan = 'N' 
                GROUP BY p.no_penjualan
                ORDER BY p.tgl_penjualan DESC";

$stmt_piutang = $pdo->prepare($sql_piutang);
$stmt_piutang->execute([$tahun_pilih]);
$list_piutang_akumulasi = $stmt_piutang->fetchAll(PDO::FETCH_ASSOC);

// --- 5. HITUNG TOTAL KEUANGAN HARIAN ---
$total_omzet    = 0;
$total_modal    = 0;
$total_cash_in  = 0;
$total_qris     = 0;
$total_debit    = 0;
$total_transfer = 0;

foreach($transaksi_harian as $row) {
    $val   = (float) $row['total_jual'];
    $modal = (float) $row['total_modal'];
    $ket   = $row['keterangan'] ?? '';

    // JIKA UTANG HARI INI -> TIDAK MASUK OMZET CASH FLOW
    if ($row['pelunasan'] == 'N') {
        // Skip omzet real, cuma dicatat transaksinya
    } 
    else {
        // JIKA LUNAS -> MASUK OMZET
        $total_omzet += $val;
        $total_modal += $modal;

        if (preg_match('/\{\{(.*?):/', $ket, $matches)) {
            $jenis = strtoupper($matches[1]);
            if ($jenis == 'QRIS') { $total_qris += $val; } 
            elseif ($jenis == 'DEBIT') { $total_debit += $val; } 
            elseif ($jenis == 'TRANSFER') { $total_transfer += $val; } 
            else { $total_cash_in += $val; }
        } else {
            $total_cash_in += $val;
        }
    }
}

// Hitung Total Piutang Akumulasi
$total_piutang_pending = 0;
foreach($list_piutang_akumulasi as $p) {
    $total_piutang_pending += $p['sisa_utang'];
}

$total_laba = $total_omzet - $total_modal;

$total_pengeluaran = 0;
foreach($pengeluaran as $p) {
    $total_pengeluaran += $p['jumlah'];
}

$sisa_kas_fisik = $total_cash_in - $total_pengeluaran;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Amplop Setoran | POS System</title>
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1a56db;
            --bg-color: #e5e7eb;
        }
        
        body { background: var(--bg-color); font-family: 'Inter', sans-serif; margin: 0; padding-bottom: 2rem; }
        
        /* Toolbar Melayang di Layar (Tidak Tercetak) */
        .print-toolbar {
            background-color: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 15px 20px;
            position: sticky;
            top: 0;
            z-index: 1050;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Container Kertas A4 */
        .envelope-container {
            margin: 30px auto;
            display: flex;
            justify-content: center;
        }

        /* Desain Kertas Cetak (A4) */
        .envelope {
            background: #fff;
            width: 210mm; 
            height: 296mm; /* A4 minus sedikit toleransi margin */
            position: relative;
            box-sizing: border-box;
            overflow: hidden; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        /* Garis Lipatan (Panduan Gunting/Lipat) */
        .fold-line-1 { position: absolute; top: 99mm; left: 0; width: 100%; border-top: 1px dashed #cbd5e1; z-index: 10; }
        .fold-line-2 { position: absolute; top: 198mm; left: 0; width: 100%; border-top: 1px dashed #cbd5e1; z-index: 10; }
        .cut-label { position: absolute; right: 5mm; top: -10px; font-size: 10px; color: #64748b; background: #fff; padding: 0 5px; font-weight: 600;}

        /* Header Bagian Atas Amplop */
        .header-dark {
            background: #111827; color: #fff; padding: 15px; text-align: center;
            border-bottom: 4px solid #1a56db; 
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        /* Layout Grid per Bagian */
        .section-1 { height: 99mm; padding: 0; box-sizing: border-box; }
        .section-1-content { padding: 8px 15mm; }
        
        .section-2 {
            height: 99mm; padding: 10mm 15mm; box-sizing: border-box;
            display: flex; flex-direction: column; justify-content: center; background-color: #f8fafc;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        .section-3 { height: 99mm; padding: 10mm 15mm; box-sizing: border-box; }

        /* Kotak Uang Fisik */
        .money-box {
            border: 3px dashed #cbd5e1; border-radius: 12px; flex-grow: 1;
            display: flex; align-items: center; justify-content: center; flex-direction: column;
            color: #94a3b8; margin: 10px 0; background-color: #fff;
        }

        .table-xs td, .table-xs th { padding: 4px 6px; font-size: 11px; }
        .big-txt { font-size: 1.8rem; font-weight: 800; letter-spacing: -0.5px;}
        
        /* Setingan Mesin Cetak (Printer) */
        @media print {
            @page { size: A4; margin: 0; }
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .envelope-container { margin: 0; }
            .envelope { border: none; box-shadow: none; width: 100%; height: 100%; }
            .money-box { background-color: #fff !important; -webkit-print-color-adjust: exact; }
            .section-2 { background-color: #f8fafc !important; }
        }
    </style>
</head>
<body>

    <div class="print-toolbar no-print">
        <div class="d-flex align-items-center">
            <a href="../index.php" class="btn btn-light border text-dark fw-bold me-3 shadow-sm rounded-pill px-3">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
            <h5 class="mb-0 fw-bolder text-dark d-none d-md-block"><i class="fas fa-envelope-open-text text-primary me-2"></i>Form Amplop Setoran</h5>
        </div>
        
        <div class="d-flex align-items-center">
            <form class="d-flex align-items-center me-3" method="GET">
                <label class="small fw-bold text-muted me-2 d-none d-md-block">Tgl Laporan:</label>
                <input type="date" name="tanggal" value="<?= $tanggal ?>" class="form-control bg-light border-0 shadow-sm" onchange="this.form.submit()" style="border-radius: 8px;">
            </form>
            
            <button onclick="window.print()" class="btn btn-primary fw-bold shadow-sm rounded-pill px-4">
                <i class="fas fa-print me-1"></i> Cetak A4
            </button>
        </div>
    </div>

    <div class="envelope-container">
        <div class="envelope">
            
            <div class="fold-line-1"><span class="cut-label">✂ Lipat Area 1</span></div>
            <div class="fold-line-2"><span class="cut-label">✂ Lipat Area 2</span></div>

            <div class="section-1">
                <div class="header-dark">
                    <h4 class="m-0 fw-bolder text-uppercase" style="letter-spacing: 1px;">Amplop Setoran Kasir</h4>
                    <div class="small mt-1 opacity-75 fw-bold" style="letter-spacing: 0.5px;">Tanggal: <?= date('d F Y', strtotime($tanggal)) ?> | Kasir: <?= $_SESSION['nama_lengkap'] ?? 'Staff' ?></div>
                </div>

                <div class="section-1-content mt-2">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="border border-secondary border-opacity-25 rounded-3 p-2 text-center bg-light">
                                <small class="text-muted fw-bolder text-uppercase" style="font-size:9px; letter-spacing:0.5px;">Total Omzet (Lunas)</small>
                                <div class="fw-bolder text-dark" style="font-size: 1.1rem; letter-spacing: -0.5px;">Rp <?= number_format($total_omzet,0,',','.') ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border border-success border-opacity-25 rounded-3 p-2 text-center bg-success bg-opacity-10">
                                <small class="text-success fw-bolder text-uppercase" style="font-size:9px; letter-spacing:0.5px;">Estimasi Laba Kotor</small>
                                <div class="fw-bolder text-success" style="font-size: 1.1rem; letter-spacing: -0.5px;">Rp <?= number_format($total_laba,0,',','.') ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="fw-bolder small border-bottom border-dark border-2 pb-1 mb-2 text-uppercase">Sumber Penerimaan</div>
                            <table class="table table-xs table-borderless mb-0">
                                <tr><td class="text-muted fw-semibold">Uang Tunai (Cash)</td><td class="text-end fw-bold text-dark">Rp <?= number_format($total_cash_in) ?></td></tr>
                                <tr><td class="text-muted fw-semibold">Saldo QRIS</td><td class="text-end text-muted">Rp <?= number_format($total_qris) ?></td></tr>
                                <tr><td class="text-muted fw-semibold">Transfer Bank</td><td class="text-end text-muted">Rp <?= number_format($total_transfer) ?></td></tr>
                                <tr><td class="text-muted fw-semibold">Kartu Debit/Kredit</td><td class="text-end text-muted">Rp <?= number_format($total_debit) ?></td></tr>
                                <tr><td colspan="2" class="p-0 border-top mt-1"></td></tr>
                                <tr>
                                    <td class="fw-bolder text-dark">Total Dana Masuk</td>
                                    <td class="text-end fw-bolder text-dark">Rp <?= number_format($total_omzet) ?></td>
                                </tr>
                            </table>
                        </div>
                        
                        <div class="col-6">
                            <div class="fw-bolder small border-bottom border-danger border-2 pb-1 mb-2 text-uppercase text-danger">Biaya & Pengeluaran Toko</div>
                            <table class="table table-xs table-bordered border-secondary border-opacity-25 mb-0">
                                <?php foreach($pengeluaran as $p): ?>
                                <tr>
                                    <td class="text-muted fw-semibold"><?= substr($p['keterangan'],0,18) ?></td>
                                    <td class="text-end text-danger fw-bold">- <?= number_format($p['jumlah']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($pengeluaran)): ?>
                                <tr><td colspan="2" class="text-center text-muted fst-italic py-2">- Tidak ada pengeluaran hari ini -</td></tr>
                                <?php endif; ?>
                                <tr class="bg-light">
                                    <td class="fw-bolder text-dark">Total Keluar</td>
                                    <td class="text-end fw-bolder text-danger">Rp <?= number_format($total_pengeluaran) ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-2">
                <div class="text-center mb-3">
                    <div class="badge bg-dark text-white rounded-pill px-3 py-2 mb-2" style="font-size: 0.75rem; letter-spacing: 1px;">WAJIB SETOR (UANG FISIK KASIR)</div>
                    <div class="big-txt text-primary">
                        Rp <?= number_format($sisa_kas_fisik, 0, ',', '.') ?>
                    </div>
                    <div class="text-muted small fw-semibold">Total Tunai dikurangi Total Pengeluaran</div>
                </div>

                <div class="money-box">
                    <i class="fas fa-wallet fa-3x mb-2 opacity-25 text-primary"></i>
                    <div class="fw-bolder text-dark opacity-50" style="letter-spacing: 1px;">LETAKKAN UANG FISIK DI SINI</div>
                    <small class="opacity-50">(Lipat uang beserta nota ini dengan rapi)</small>
                </div>
                
                <div class="text-center text-danger fw-semibold" style="font-size: 11px;">
                    <i class="fas fa-exclamation-triangle me-1"></i> Pastikan jumlah lembaran uang di laci sama persis dengan nominal di atas.
                </div>
            </div>

            <div class="section-3">
                <div class="row h-100">
                    <div class="col-8 pe-4">
                        <div class="d-flex justify-content-between align-items-center border-bottom border-2 pb-1 mb-2">
                            <strong class="fw-bolder text-dark text-uppercase" style="font-size: 0.8rem;"><i class="fas fa-book-open me-2 text-warning"></i>Daftar Piutang (Belum Lunas)</strong>
                            <span class="badge bg-warning text-dark border border-warning px-2 rounded-pill" style="font-size: 0.65rem;"><?= count($list_piutang_akumulasi) ?> Nota Gantung</span>
                        </div>
                        
                        <?php if(count($list_piutang_akumulasi) > 0): ?>
                            <div style="max-height: 75mm; overflow: hidden;">
                                <table class="table table-xs table-striped mb-0 border">
                                    <thead class="table-dark">
                                        <tr>
                                            <th class="py-1">Tgl Nota</th>
                                            <th class="py-1">Nama Pelanggan</th>
                                            <th class="py-1 text-end">Sisa Utang</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Batasi tampilan agar tidak tumpah memotong kertas
                                        $batas_tampil = array_slice($list_piutang_akumulasi, 0, 10); 
                                        foreach($batas_tampil as $u): 
                                            $nama = !empty($u['nama_pelanggan']) ? $u['nama_pelanggan'] : ($u['kode_pelanggan'] ?? 'UMUM');
                                        ?>
                                        <tr>
                                            <td class="text-muted"><?= date('d/m', strtotime($u['tgl_penjualan'])) ?></td>
                                            <td class="fw-bold text-dark text-truncate" style="max-width: 120px;"><?= $nama ?></td>
                                            <td class="text-end fw-bolder text-danger">Rp <?= number_format($u['sisa_utang']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if(count($list_piutang_akumulasi) > 10): ?>
                                        <tr><td colspan="3" class="text-center text-muted small fst-italic py-1">...dan <?= count($list_piutang_akumulasi) - 10 ?> nota lainnya.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-bolder bg-light border-top border-2">
                                            <td colspan="2" class="text-uppercase py-2" style="font-size:0.7rem;">Total Uang Tertahan</td>
                                            <td class="text-end text-danger fs-6">Rp <?= number_format($total_piutang_pending) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted border border-dashed rounded-3 bg-light h-75 d-flex flex-column justify-content-center">
                                <i class="fas fa-check-circle text-success fa-2x mb-2 opacity-50"></i>
                                <span class="fw-bold">Buku Piutang Bersih!</span>
                                <small>Tidak ada tagihan tertunggak di tahun <?= $tahun_pilih ?>.</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-4 d-flex flex-column justify-content-between text-center border-start ps-3 py-2">
                        <div>
                            <div class="fw-bolder text-dark mb-2 text-start small border-bottom pb-1">Validasi Setoran</div>
                            <table class="table table-bordered table-xs mb-2 text-start">
                                <tr><td class="bg-light fw-bold text-muted" width="40%">Fisik Laci</td><td class="text-end">Rp ...................</td></tr>
                                <tr><td class="bg-light fw-bold text-muted">Selisih</td><td class="text-end">Rp ...................</td></tr>
                            </table>
                        </div>
                        
                        <div class="mt-4 pt-3">
                            <small class="d-block mb-4 fw-bold text-muted">Petugas Kasir,</small>
                            <br>
                            <span class="text-decoration-underline fw-bolder text-dark" style="font-size: 1.1rem;"><?= $_SESSION['nama_lengkap'] ?? '................' ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

</body>
</html>