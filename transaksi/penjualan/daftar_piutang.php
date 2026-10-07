<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';
require_once __DIR__ . '/piutang_helper.php';

// --- QUERY HYBRID (OPTIMIZED + DEPOSIT LOGIC) ---
// Rumus sisa per-nota diambil dari piutangSisaNotaExpr() di piutang_helper.php (SUMBER TUNGGAL),
// supaya sama persis dengan yang dipakai di rincian_piutang.php & bayar_cicilan.php.
// Nota dengan sisa di bawah PIUTANG_AMBANG_MINIMAL dianggap noise, tidak ikut dihitung.
$sisaExpr = piutangSisaNotaExpr('p');
$sql = "SELECT 
            pl.kode_pelanggan, 
            pl.nama_pelanggan, 
            pl.no_telepon,
            pl.saldo_deposit,
            COUNT(p.no_penjualan) as jumlah_nota,
            SUM(CASE WHEN $sisaExpr >= " . PIUTANG_AMBANG_MINIMAL . " THEN $sisaExpr ELSE 0 END) as total_sisa_utang,
            MAX(CASE WHEN p.tgl_penjualan = CURDATE() AND $sisaExpr >= " . PIUTANG_AMBANG_MINIMAL . " THEN 1 ELSE 0 END) as ada_piutang_hari_ini
        FROM penjualan p
        JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
        WHERE p.pelunasan = 'N' 
        GROUP BY pl.kode_pelanggan, pl.nama_pelanggan, pl.no_telepon, pl.saldo_deposit
        HAVING total_sisa_utang > 0 
        ORDER BY pl.nama_pelanggan ASC";

$stmt = $pdo->query($sql);
$data_pelanggan = $stmt->fetchAll();

// Pisahkan jadi 2 kelompok: pelanggan yang punya piutang BARU dari transaksi HARI INI,
// dan yang piutangnya cuma sisa dari hari-hari sebelumnya. "Hari ini" ditentukan dari
// ada/tidaknya nota bertanggal hari ini yang sisa tagihannya masih di atas ambang batas noise.
$piutang_hari_ini = [];
$piutang_sebelumnya = [];
foreach ($data_pelanggan as $row) {
    if ((int)$row['ada_piutang_hari_ini'] === 1) {
        $piutang_hari_ini[] = $row;
    } else {
        $piutang_sebelumnya[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manajemen Piutang | POS System</title>
    
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
            --danger-color: #dc3545;
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
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; }
        
        .badge-deposit { font-size: 0.7rem; padding: 0.4em 0.8em; background-color: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; font-weight: 600;}
        .badge-nota { background-color: #f3f4f6; color: #4b5563; border: 1px solid var(--border-color); font-weight: 600; padding: 0.4em 0.8em;}
        
        .table-hover tbody tr:hover { background-color: #fef2f2 !important; }
        
        /* Sticky Element */
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); }
        tfoot td { position: sticky; bottom: 0; background-color: #fff !important; z-index: 10; box-shadow: 0 -1px 0 var(--border-color); }
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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Manajemen Piutang</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Rekapitulasi Penagihan</small>
                </div>
            </div>
            
            <div class="d-none d-sm-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                <div class="d-flex flex-column ms-2 me-3 justify-content-center">
                    <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                    <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                </div>
            </div>
        </header>

        <div class="container-fluid p-3 p-md-4 d-flex flex-column flex-grow-1 overflow-hidden">
            
            <div class="card bg-white card-custom">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="mb-0 text-danger fw-bolder"><i class="fas fa-users text-danger opacity-75 me-2"></i>Daftar Penunggak</h6>
                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 rounded-pill">
                        <i class="fas fa-exclamation-circle me-1"></i> <?= count($data_pelanggan) ?> Pelanggan
                    </span>
                </div>
                
                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted">
                            <tr>
                                <th class="ps-4 py-3 border-0 fw-bold">Profil Pelanggan</th>
                                <th class="d-none d-md-table-cell py-3 border-0 fw-bold">Kontak</th>
                                <th class="text-center py-3 border-0 fw-bold">Jml Nota</th>
                                <th class="text-end py-3 border-0 fw-bold text-danger">Total Sisa Utang</th>
                                <th class="text-center pe-4 py-3 border-0 fw-bold" width="120">Aksi</th>
                            </tr>
                        </thead>
                        <?php 
                        // Render 1 baris tabel piutang. Dibuat fungsi supaya HTML-nya tidak
                        // perlu ditulis dua kali (sekali untuk grup "Hari Ini", sekali untuk grup "Sebelumnya").
                        function renderBarisPiutang($row) {
                            $saldo_depo = (float)$row['saldo_deposit'];
                            ?>
                            <tr>
                                <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="fw-bolder d-block text-dark" style="font-size: 0.95rem; letter-spacing: -0.2px;"><?= htmlspecialchars($row['nama_pelanggan']) ?></span>
                                    
                                    <?php if($saldo_depo > 0): ?>
                                        <span class="badge rounded-pill badge-deposit mt-2">
                                            <i class="fas fa-wallet me-1"></i> Deposit: Rp <?= number_format($saldo_depo, 0, ',', '.') ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <small class="text-muted fw-semibold d-md-none d-block mt-1"><i class="fab fa-whatsapp me-1 opacity-50"></i><?= htmlspecialchars($row['no_telepon']) ?></small>
                                </td>
                                
                                <td class="d-none d-md-table-cell border-bottom-0 text-muted fw-semibold" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <?= htmlspecialchars($row['no_telepon']) ?>
                                </td>
                                
                                <td class="text-center border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge badge-nota rounded-pill"><?= $row['jumlah_nota'] ?> Nota</span>
                                </td>
                                
                                <td class="text-end fw-bolder text-danger fs-6 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important; letter-spacing: -0.5px;">
                                    Rp <?= number_format($row['total_sisa_utang'], 0, ',', '.') ?>
                                </td>
                                
                                <td class="text-center pe-4 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <button type="button" class="btn btn-light text-primary border shadow-sm btn-sm fw-bold px-3 rounded-pill" onclick="bukaModalRincian('<?= $row['kode_pelanggan'] ?>')" style="transition: 0.2s;">
                                        Rincian <i class="fas fa-chevron-right ms-1" style="font-size: 0.7rem;"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php
                        }

                        $grand_total_utang = 0;
                        foreach ($data_pelanggan as $row) { $grand_total_utang += $row['total_sisa_utang']; }
                        $total_hari_ini = array_sum(array_column($piutang_hari_ini, 'total_sisa_utang'));
                        $total_sebelumnya = array_sum(array_column($piutang_sebelumnya, 'total_sisa_utang'));
                        ?>

                        <?php if ($piutang_hari_ini): ?>
                        <tbody>
                            <tr class="group-header-today">
                                <td colspan="5" class="px-4 py-2 fw-bolder text-white" style="background: linear-gradient(90deg, #dc3545, #c0392b); font-size: 0.8rem; letter-spacing: 0.5px;">
                                    <i class="fas fa-bolt me-2"></i>PIUTANG HARI INI
                                    <span class="float-end">Rp <?= number_format($total_hari_ini, 0, ',', '.') ?></span>
                                </td>
                            </tr>
                            <?php foreach ($piutang_hari_ini as $row) { renderBarisPiutang($row); } ?>
                        </tbody>
                        <?php endif; ?>

                        <?php if ($piutang_sebelumnya): ?>
                        <tbody>
                            <tr class="group-header-prev">
                                <td colspan="5" class="px-4 py-2 fw-bolder text-muted" style="background: #f1f3f5; font-size: 0.8rem; letter-spacing: 0.5px;">
                                    <i class="fas fa-history me-2"></i>PIUTANG SEBELUMNYA
                                    <span class="float-end">Rp <?= number_format($total_sebelumnya, 0, ',', '.') ?></span>
                                </td>
                            </tr>
                            <?php foreach ($piutang_sebelumnya as $row) { renderBarisPiutang($row); } ?>
                        </tbody>
                        <?php endif; ?>

                        <tbody>
                            <?php if(count($data_pelanggan) == 0): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                        <i class="fas fa-shield-check fa-4x text-success opacity-25 mb-3"></i>
                                        <h5 class="fw-bolder text-dark">Luar Biasa!</h5>
                                        <p class="mb-0">Saat ini tidak ada pelanggan yang menunggak utang.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        
                        <?php if($grand_total_utang > 0): ?>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-uppercase text-muted fw-bold pt-4 pb-3" style="font-size: 0.8rem; letter-spacing: 0.5px;">Total Piutang Toko:</td>
                                <td class="text-end text-danger fw-bolder pt-4 pb-3" style="font-size: 1.2rem; letter-spacing: -0.5px;">Rp <?= number_format($grand_total_utang, 0, ',', '.') ?></td>
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

<div class="modal fade" id="modalRincianPiutang" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalRincian">
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function bukaModalRincian(kodePelanggan) {
        // Tampilkan loading di dalam modal
        $('#kontenModalRincian').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Mengambil data rincian nota...</div>');
        
        // Tampilkan Modal
        var myModal = new bootstrap.Modal(document.getElementById('modalRincianPiutang'));
        myModal.show();
        
        // Panggil PHP via AJAX
        $.ajax({
            url: 'rincian_piutang.php?kode=' + kodePelanggan,
            type: 'GET',
            success: function(response) {
                // Suntikkan hasil HTML dari rincian_piutang.php ke dalam modal
                $('#kontenModalRincian').html(response);
            },
            error: function() {
                $('#kontenModalRincian').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat data. Periksa koneksi internet Anda.</div>');
            }
        });
    }
</script>
</body>
</html>