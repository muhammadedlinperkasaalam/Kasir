<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

$script_swal = "";

// ==========================================
// 1. PROSES HAPUS DATA
// ==========================================
if (isset($_GET['hapus'])) {
    $id = $_GET['hapus'];
    
    try {
        $stmtHapus = $pdo->prepare("DELETE FROM pengeluaran_non_stok WHERE kode_pengeluaran = ?");
        $stmtHapus->execute([$id]);
        
        $script_swal = "Swal.fire({icon: 'success', title: 'Terhapus!', text: 'Data pengeluaran berhasil dihapus.', timer: 1500, showConfirmButton: false}).then(() => { window.location='riwayat.php'; });";
    } catch(Exception $e) {
        $script_swal = "Swal.fire('Gagal', 'Tidak dapat menghapus data.', 'error');";
    }
}

// ==========================================
// 2. LOGIKA FILTER TANGGAL
// ==========================================
$tgl_awal  = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');

// ==========================================
// 3. AMBIL DATA DARI DATABASE
// ==========================================
$sql = "SELECT p.*, k.nama_kategori 
        FROM pengeluaran_non_stok p
        LEFT JOIN kategori_pengeluaran k ON p.kode_kategori_pengeluaran = k.kode_kategori
        WHERE p.tanggal BETWEEN ? AND ?
        ORDER BY p.tanggal DESC, p.kode_pengeluaran DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tgl_awal, $tgl_akhir]);
$pengeluaran = $stmt->fetchAll();

// Hitung Total Nominal Periode Ini
$total_periode = 0;
foreach($pengeluaran as $p) { $total_periode += $p['jumlah']; }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Biaya Operasional | POS System</title>
    
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #1a56db;
            --danger-color: #dc3545;
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
        
        .form-control, .form-select { border-radius: 8px; border-color: var(--border-color); font-size: 0.9rem;}
        .form-control:focus, .form-select:focus { border-color: var(--danger-color); box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.15); }
        
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; background-color: #fff;}
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); border-bottom: none !important;}
        tfoot td { position: sticky; bottom: 0; background-color: #fef2f2 !important; z-index: 10; box-shadow: 0 -1px 0 var(--border-color); border-top: none !important;}
        
        .table-hover tbody tr:hover { background-color: #fef2f2 !important; }
        
        .badge-kategori { background-color: #fef2f2; color: var(--danger-color); border: 1px solid #fecaca; padding: 0.4em 0.8em; font-weight: 600;}
    </style>
</head>
<body>

<?php if(!empty($script_swal)): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        <?= $script_swal ?>
    });
</script>
<?php endif; ?>

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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Biaya Operasional</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Pengeluaran Uang Kas</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-danger fw-bold shadow-sm rounded-pill px-3" onclick="bukaModalTambah()">
                    <i class="fas fa-plus me-1"></i> <span class="d-none d-sm-inline">Catat Biaya</span>
                </button>
                
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
                    <form method="GET" action="riwayat.php" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Dari Tanggal</label>
                            <input type="date" name="tgl_awal" class="form-control bg-light" value="<?= $tgl_awal ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small text-muted text-uppercase" style="font-size:0.7rem;">Sampai Tanggal</label>
                            <input type="date" name="tgl_akhir" class="form-control bg-light" value="<?= $tgl_akhir ?>">
                        </div>
                        <div class="col-md-3">
                            <div class="d-grid gap-2 d-md-flex">
                                <button type="submit" class="btn btn-dark fw-bold flex-grow-1 shadow-sm" style="border-radius: 8px;">
                                    <i class="fas fa-filter me-1"></i> Terapkan Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted small text-uppercase fw-bold">
                            <tr>
                                <th class="ps-4 py-3 border-0">Tanggal Keluar</th>
                                <th class="py-3 border-0">Kategori Biaya</th>
                                <th class="py-3 border-0 d-none d-md-table-cell">Keterangan Spesifik</th>
                                <th class="text-end py-3 border-0 text-danger">Jumlah Nominal</th>
                                <th class="text-center pe-4 py-3 border-0" width="120">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pengeluaran as $row): ?>
                            <tr>
                                <td class="ps-4 py-3 border-bottom-0 fw-semibold text-dark" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <i class="far fa-calendar-alt text-muted opacity-50 me-2"></i><?= date('d M Y', strtotime($row['tanggal'])) ?>
                                    <div class="text-muted small d-md-none mt-1 fw-normal text-truncate" style="max-width: 200px;"><?= htmlspecialchars($row['keterangan']) ?></div>
                                </td>
                                
                                <td class="py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge badge-kategori rounded-pill">
                                        <?= htmlspecialchars($row['nama_kategori'] ?? 'Lain-lain') ?>
                                    </span>
                                </td>
                                
                                <td class="py-3 border-bottom-0 d-none d-md-table-cell text-muted" style="border-bottom: 1px solid var(--border-color) !important; max-width: 300px;">
                                    <span class="text-truncate d-inline-block w-100" title="<?= htmlspecialchars($row['keterangan']) ?>">
                                        <?= htmlspecialchars($row['keterangan']) ?: '-' ?>
                                    </span>
                                </td>
                                
                                <td class="text-end py-3 border-bottom-0 fw-bolder text-danger fs-6" style="border-bottom: 1px solid var(--border-color) !important; letter-spacing: -0.5px;">
                                    Rp <?= number_format($row['jumlah'], 0, ',', '.') ?>
                                </td>
                                
                                <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <div class="btn-group btn-group-sm shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                        <a href="edit.php?id=<?= $row['kode_pengeluaran'] ?>" class="btn btn-light border text-primary" title="Edit Data">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="#" onclick="hapusPengeluaran('<?= $row['kode_pengeluaran'] ?>')" class="btn btn-light border text-danger" title="Hapus Data">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($pengeluaran)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                            <i class="fas fa-wallet fa-4x opacity-25 mb-3 text-danger"></i>
                                            <h5 class="fw-bolder text-dark">Data Bersih!</h5>
                                            <p class="mb-0">Tidak ada pengeluaran kas tercatat pada rentang tanggal ini.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        
                        <?php if($total_periode > 0): ?>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-uppercase text-danger fw-bold pt-4 pb-3" style="font-size: 0.8rem; letter-spacing: 0.5px;">Total Pengeluaran Kas:</td>
                                <td class="text-end text-danger fw-bolder pt-4 pb-3" style="font-size: 1.25rem; letter-spacing: -0.5px;">
                                    Rp <?= number_format($total_periode, 0, ',', '.') ?>
                                </td>
                                <td class="pt-4 pb-3 pe-4"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                        
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>

<div class="modal fade" id="modalTambahPengeluaran" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalTambah">
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fungsi SweetAlert Khusus untuk tombol Hapus Data
    function hapusPengeluaran(id) {
        Swal.fire({
            title: 'Hapus Biaya Ini?',
            text: "Uang kas keluar akan direvisi jika Anda menghapusnya.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'riwayat.php?hapus=' + id;
            }
        });
    }

    function bukaModalTambah() {
        $('#kontenModalTambah').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-danger"></i><br>Menyiapkan form...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalTambahPengeluaran'));
        myModal.show();
        
        $.ajax({
            url: 'tambah.php',
            type: 'GET',
            success: function(response) {
                $('#kontenModalTambah').html(response);
            },
            error: function() {
                $('#kontenModalTambah').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form.</div>');
            }
        });
    }
</script>
</body>
</html>