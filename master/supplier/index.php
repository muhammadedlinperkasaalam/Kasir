<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// Ambil Data Supplier
$stmt = $pdo->query("SELECT * FROM supplier ORDER BY kode_supplier ASC");
$suppliers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Master Supplier | POS System</title>
    
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
        
        .badge-kode { background-color: #f3f4f6; color: #4b5563; border: 1px solid var(--border-color); font-weight: 600; padding: 0.3em 0.6em; font-size: 0.75rem;}
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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Master Supplier</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Data Pemasok Barang</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-primary fw-bold shadow-sm rounded-pill px-3" onclick="bukaModalTambah()">
                    <i class="fas fa-plus me-1"></i> <span class="d-none d-sm-inline">Tambah Supplier</span>
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
                
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-shrink-0">
                    <h6 class="mb-0 text-primary fw-bolder"><i class="fas fa-truck text-primary opacity-75 me-2"></i>Daftar Relasi Pemasok</h6>
                    <span class="badge bg-light text-muted border border-secondary border-opacity-25 px-3 py-2 rounded-pill">
                        Total: <?= count($suppliers) ?> Supplier
                    </span>
                </div>

                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted small text-uppercase fw-bold">
                            <tr>
                                <th class="ps-4 py-3 border-0" width="120">Kode</th>
                                <th class="py-3 border-0">Nama Supplier / PT</th>
                                <th class="py-3 border-0">No. Telepon / WA</th>
                                <th class="py-3 border-0 d-none d-md-table-cell">Alamat</th>
                                <th class="text-center pe-4 py-3 border-0" width="120">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($suppliers as $row): ?>
                            <tr>
                                <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge badge-kode rounded-pill"><?= $row['kode_supplier'] ?></span>
                                </td>
                                
                                <td class="py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($row['nama_supplier']) ?></div>
                                    <div class="text-muted small d-md-none text-truncate" style="max-width: 200px;">
                                        <i class="fas fa-map-marker-alt opacity-50 me-1"></i><?= htmlspecialchars($row['alamat']) ?>
                                    </div>
                                </td>
                                
                                <td class="py-3 border-bottom-0 fw-semibold text-primary" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <i class="fas fa-phone-alt opacity-50 me-1 text-muted"></i><?= htmlspecialchars($row['no_telepon']) ?>
                                </td>
                                
                                <td class="py-3 border-bottom-0 d-none d-md-table-cell text-muted" style="border-bottom: 1px solid var(--border-color) !important; max-width: 300px;">
                                    <span class="text-truncate d-inline-block w-100" title="<?= htmlspecialchars($row['alamat']) ?>">
                                        <?= htmlspecialchars($row['alamat']) ?: '-' ?>
                                    </span>
                                </td>
                                
                                <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <div class="btn-group btn-group-sm shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                        <a href="#" onclick="bukaModalEdit('<?= $row['kode_supplier'] ?>')" class="btn btn-light border text-warning" title="Edit Data">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button onclick="hapusData('<?= $row['kode_supplier'] ?>')" class="btn btn-light border text-danger" title="Hapus Data">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($suppliers)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                            <i class="fas fa-building fa-4x opacity-25 mb-3 text-primary"></i>
                                            <h5 class="fw-bolder text-dark">Data Kosong</h5>
                                            <p class="mb-0">Anda belum mendaftarkan supplier pemasok barang satupun.</p>
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

<div class="modal fade" id="modalTambahSupplier" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalTambah">
            </div>
    </div>
</div>

<div class="modal fade" id="modalEditSupplier" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalEdit">
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Penangkapan Session Sukses/Error dari file proses lain (tambah/edit)
    <?php if(isset($_SESSION['success'])): ?>
        Swal.fire({ icon: 'success', title: 'Berhasil!', text: '<?= $_SESSION['success'] ?>', timer: 2000, showConfirmButton: false });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
        Swal.fire({ icon: 'error', title: 'Gagal!', text: '<?= $_SESSION['error'] ?>' });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    // Fungsi Hapus Data
    function hapusData(id) {
        Swal.fire({
            title: 'Hapus Supplier?',
            text: "Data supplier ini akan dihapus dari sistem.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'hapus.php?id=' + id;
            }
        })
    }


    function bukaModalTambah() {
        // Efek loading sebelum data di-render
        $('#kontenModalTambah').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Menyiapkan form...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalTambahSupplier'));
        myModal.show();
        
        $.ajax({
            url: 'tambah.php',
            type: 'GET',
            success: function(response) {
                $('#kontenModalTambah').html(response);
            },
            error: function() {
                $('#kontenModalTambah').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form supplier.</div>');
            }
        });
    }

    function bukaModalEdit(idSupplier) {
        // Efek loading
        $('#kontenModalEdit').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-warning"></i><br>Mengambil data...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEditSupplier'));
        myModal.show();
        
        // Panggil PHP via AJAX
        $.ajax({
            url: 'edit.php?id=' + idSupplier,
            type: 'GET',
            success: function(response) {
                $('#kontenModalEdit').html(response);
            },
            error: function() {
                $('#kontenModalEdit').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat data supplier.</div>');
            }
        });
    }

</script>

</body>
</html>