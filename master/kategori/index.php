<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

$script_swal = "";

// --- PROSES HAPUS KATEGORI ---
if (isset($_GET['hapus'])) {
    $id = $_GET['hapus'];

    try {
        // 1. Cek Keamanan: Apakah kategori ini dipakai oleh barang?
        $stmt_cek = $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kode_kategori = ?");
        $stmt_cek->execute([$id]);
        $jumlah_dipakai = $stmt_cek->fetchColumn();

        if ($jumlah_dipakai > 0) {
            // Jika dipakai, tolak hapus
            $script_swal = "Swal.fire({
                icon: 'error',
                title: 'Gagal Menghapus!',
                text: 'Kategori ini sedang digunakan oleh $jumlah_dipakai barang. Silakan ganti dulu kategori pada barang tersebut.',
                confirmButtonColor: '#1a56db'
            }).then(() => { window.location='index.php'; });";
        } else {
            // Jika aman, hapus
            $stmt = $pdo->prepare("DELETE FROM kategori WHERE kode_kategori = ?");
            $stmt->execute([$id]);
            
            $script_swal = "Swal.fire({
                icon: 'success',
                title: 'Terhapus!',
                text: 'Data Kategori berhasil dihapus.',
                timer: 1500,
                showConfirmButton: false
            }).then(() => { window.location='index.php'; });";
        }
    } catch(Exception $e) {
        $script_swal = "Swal.fire('Error', 'Terjadi kesalahan saat menghapus data.', 'error');";
    }
}

// Ambil Semua Data Kategori
$stmt = $pdo->query("SELECT * FROM kategori ORDER BY kode_kategori DESC");
$kategori = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Master Kategori | POS System</title>
    
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
        
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; background-color: #fff;}
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); border-bottom: none !important;}
        
        .table-hover tbody tr:hover { background-color: #f8f9fa !important; }
        
        .badge-kategori { background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 0.5em 1em; font-weight: 600; font-size: 0.85rem;}
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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Master Kategori</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Pengelompokan Jenis Barang</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-primary fw-bold shadow-sm rounded-pill px-3" onclick="bukaModalTambah()">
                    <i class="fas fa-plus me-1"></i> <span class="d-none d-sm-inline">Kategori Baru</span>
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
            
            <div class="row h-100">
                <div class="col-lg-8 col-xl-7 mx-auto h-100 d-flex flex-column">
                    <div class="card bg-white card-custom">
                        
                        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-shrink-0">
                            <h6 class="mb-0 text-primary fw-bolder"><i class="fas fa-tags text-primary opacity-75 me-2"></i>Daftar Kategori Tersedia</h6>
                            <span class="badge bg-light text-muted border border-secondary border-opacity-25 px-3 py-2 rounded-pill">
                                Total: <?= count($kategori) ?> Kategori
                            </span>
                        </div>

                        <div class="card-body p-0 table-responsive-custom">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="text-muted small text-uppercase fw-bold">
                                    <tr>
                                        <th class="text-center py-3 border-0" width="80">No</th>
                                        <th class="ps-3 py-3 border-0">Nama Kategori</th>
                                        <th class="text-center pe-4 py-3 border-0" width="140">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $no=1; foreach($kategori as $row): ?>
                                    <tr>
                                        <td class="text-center py-3 border-bottom-0 text-muted fw-semibold" style="border-bottom: 1px solid var(--border-color) !important;">
                                            <?= $no++ ?>
                                        </td>
                                        
                                        <td class="ps-3 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                            <span class="badge badge-kategori rounded-pill">
                                                <?= htmlspecialchars($row['nama_kategori']) ?>
                                            </span>
                                        </td>
                                        
                                        <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                            <div class="btn-group btn-group-sm shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                                <a href="#" onclick="bukaModalEdit('<?= $row['kode_kategori'] ?>')" class="btn btn-light border text-warning" title="Edit Data">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="#" onclick="hapusKategori('<?= $row['kode_kategori'] ?>', '<?= htmlspecialchars($row['nama_kategori'], ENT_QUOTES) ?>')" class="btn btn-light border text-danger" title="Hapus Data">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if(empty($kategori)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-5">
                                                <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                                    <i class="fas fa-tags fa-4x opacity-25 mb-3 text-primary"></i>
                                                    <h5 class="fw-bolder text-dark">Kategori Kosong</h5>
                                                    <p class="mb-0">Tambahkan kategori untuk mulai mengelompokkan barang Anda.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<div class="modal fade" id="modalTambahKategori" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalTambah">
            </div>
    </div>
</div>

<div class="modal fade" id="modalEditKategori" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalEdit">
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fungsi SweetAlert Khusus untuk tombol Hapus Kategori
    function hapusKategori(id, nama) {
        Swal.fire({
            title: 'Hapus Kategori?',
            html: `Anda akan menghapus kategori <b>${nama}</b>.<br><small class="text-danger">Aksi ini akan ditolak jika ada barang yang masih memakai kategori ini.</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'index.php?hapus=' + id;
            }
        });
    }

    function bukaModalTambah() {
        // Efek loading sebelum data di-render
        $('#kontenModalTambah').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Menyiapkan form...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalTambahKategori'));
        myModal.show();
        
        $.ajax({
            url: 'tambah.php',
            type: 'GET',
            success: function(response) {
                $('#kontenModalTambah').html(response);
            },
            error: function() {
                $('#kontenModalTambah').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form kategori.</div>');
            }
        });
    }

    // Fungsi untuk membuka Modal Edit
    function bukaModalEdit(idKategori) {
        // Efek loading
        $('#kontenModalEdit').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-warning"></i><br>Mengambil data...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEditKategori'));
        myModal.show();
        
        // Panggil PHP via AJAX dengan parameter ID
        $.ajax({
            url: 'edit.php?id=' + idKategori,
            type: 'GET',
            success: function(response) {
                $('#kontenModalEdit').html(response);
            },
            error: function() {
                $('#kontenModalEdit').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat data kategori.</div>');
            }
        });
    }
</script>
</body>
</html>