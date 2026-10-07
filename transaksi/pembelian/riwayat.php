<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// Filter Tanggal
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// --- PERBAIKAN: Menggunakan 'tgl_pembelian' ---
$sql = "SELECT p.*, s.nama_supplier, u.nama_user
        FROM pembelian p
        LEFT JOIN supplier s ON p.kode_supplier = s.kode_supplier
        LEFT JOIN operator u ON p.kode_user = u.kode_user
        WHERE p.tgl_pembelian BETWEEN ? AND ?
        ORDER BY p.tgl_pembelian DESC, p.no_pembelian DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tgl_awal, $tgl_akhir]);
$pembelian = $stmt->fetchAll();

// Hitung Grand Total
$grand_total = 0;
foreach($pembelian as $r) {
    $grand_total += $r['total'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Riwayat Pembelian Stok | POS System</title>
    
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1a56db;
            --bg-color: #f8f9fa;
            --border-color: #e5e7eb;
            --text-dark: #111827;
            --text-muted: #6b7280;
        }
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-color);
            overflow: hidden; 
        }
        
        /* Layout Grid System Murni */
        .wrapper { height: 100vh; width: 100vw; display: flex; }
        .main-content { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: var(--bg-color); flex-grow: 1; }
        .header-top { height: 70px; background-color: #fff; border-bottom: 1px solid var(--border-color); flex-shrink: 0; z-index: 1020; display: flex; align-items: center; justify-content: space-between; }
        
        /* Card & Table Styling */
        .card-custom { border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; display: flex; flex-direction: column; height: 100%;}
        
        .form-control { border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;}
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.25rem rgba(26, 86, 219, 0.1); }
        
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; background-color: #fff;}
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); border-bottom: none !important;}
        tfoot td { position: sticky; bottom: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 -1px 0 var(--border-color); border-top: none !important;}
        
        .table-hover tbody tr:hover { background-color: #fef2f2 !important; }
    </style>
</head>
<body>

<div class="wrapper">
    <?php 
        $base_dir = '../../'; 
        include '../../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="header-top px-3 px-md-4 shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Riwayat Pembelian</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Laporan Transaksi Restock</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="tambah.php" class="btn btn-primary fw-bold shadow-sm rounded-pill px-3">
                    <i class="fas fa-plus me-1"></i> <span class="d-none d-sm-inline">Pembelian Baru</span>
                </a>
                
                <div class="d-none d-lg-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="d-flex flex-column ms-2 me-3 justify-content-center">
                        <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid p-3 p-md-4 d-flex flex-column flex-grow-1 overflow-hidden">
            
            <div class="card bg-white card-custom">
                
                <div class="card-header bg-white p-3 border-bottom flex-shrink-0">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Dari Tanggal</label>
                            <input type="date" name="tgl_awal" class="form-control bg-light" value="<?= $tgl_awal ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Sampai Tanggal</label>
                            <input type="date" name="tgl_akhir" class="form-control bg-light" value="<?= $tgl_akhir ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-dark fw-bold w-100 shadow-sm" style="border-radius: 8px;">
                                <i class="fas fa-filter me-2"></i> Terapkan Filter
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted small text-uppercase fw-bold">
                            <tr>
                                <th class="ps-4 py-3 border-0 text-start">Data Transaksi</th>
                                <th class="py-3 border-0 text-start">Supplier</th>
                                <th class="py-3 border-0 d-none d-md-table-cell">Keterangan</th>
                                <th class="py-3 border-0 text-end text-danger">Total Biaya</th>
                                <th class="py-3 border-0 text-center pe-4" width="100">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pembelian as $row): ?>
                            <tr>
                                <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="fw-bolder text-primary d-block mb-1" style="font-size: 0.95rem;">
                                        <i class="fas fa-receipt opacity-50 me-1"></i><?= $row['no_pembelian'] ?>
                                    </span>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="text-muted small fw-semibold"><i class="far fa-calendar-alt me-1 opacity-50"></i><?= date('d M Y', strtotime($row['tgl_pembelian'])) ?></span>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25" style="font-size: 0.65rem;">
                                            <i class="fas fa-user-edit me-1"></i><?= $row['nama_user'] ?? 'System' ?>
                                        </span>
                                    </div>
                                </td>
                                
                                <td class="py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($row['nama_supplier'] ?? 'UMUM / TANPA NAMA') ?></span>
                                </td>
                                
                                <td class="py-3 border-bottom-0 d-none d-md-table-cell text-muted" style="border-bottom: 1px solid var(--border-color) !important; max-width: 250px;">
                                    <span class="text-truncate d-inline-block w-100" title="<?= htmlspecialchars($row['keterangan']) ?>">
                                        <?= htmlspecialchars($row['keterangan']) ?: '-' ?>
                                    </span>
                                </td>
                                
                                <td class="text-end py-3 border-bottom-0 fw-bolder text-danger fs-6" style="border-bottom: 1px solid var(--border-color) !important; letter-spacing: -0.5px;">
                                    Rp <?= number_format($row['total'], 0, ',', '.') ?>
                                </td>
                                
                                <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <button type="button" class="btn btn-light text-primary border shadow-sm btn-sm fw-bold rounded-pill px-3" onclick="bukaModalDetail('<?= $row['no_pembelian'] ?>')" title="Lihat Item Barang">
                                        Detail <i class="fas fa-chevron-right ms-1" style="font-size: 0.7rem;"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($pembelian)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                            <i class="fas fa-box-open fa-4x opacity-25 mb-3"></i>
                                            <h5 class="fw-bolder text-dark">Data Kosong</h5>
                                            <p class="mb-0">Tidak ada riwayat pembelian restock pada rentang tanggal ini.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        
                        <?php if($grand_total > 0): ?>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-uppercase text-muted fw-bold pt-4 pb-3" style="font-size: 0.8rem; letter-spacing: 0.5px;">Total Pengeluaran Restock:</td>
                                <td class="text-end text-danger fw-bolder pt-4 pb-3" style="font-size: 1.25rem; letter-spacing: -0.5px;">
                                    Rp <?= number_format($grand_total, 0, ',', '.') ?>
                                </td>
                                <td class="pt-4 pb-3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                        
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<div class="modal fade" id="modalDetailPembelian" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalDetail">
            </div>
    </div>
</div>

<script>
    function bukaModalDetail(noPembelian) {
        // Tampilkan animasi loading sementara menunggu data dari server
        $('#kontenModalDetail').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Mengambil detail barang...</div>');
        
        // Munculkan Modal
        var myModal = new bootstrap.Modal(document.getElementById('modalDetailPembelian'));
        myModal.show();
        
        // Panggil PHP via AJAX
        $.ajax({
            url: 'detail.php?no=' + noPembelian,
            type: 'GET',
            success: function(response) {
                // Suntikkan hasil HTML ke dalam modal
                $('#kontenModalDetail').html(response);
            },
            error: function() {
                $('#kontenModalDetail').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat data detail.</div>');
            }
        });
    }
</script>
</body>
</html>