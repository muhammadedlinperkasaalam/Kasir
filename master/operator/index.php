<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// Ambil Data Operator
$stmt = $pdo->query("SELECT * FROM operator ORDER BY kode_user ASC");
$users = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manajemen User | POS System</title>
    
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
        
        /* Custom Badge */
        .badge-admin { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 0.4em 0.8em;}
        .badge-kasir { background-color: #e0e7ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 0.4em 0.8em;}
        .badge-shift-pagi { background-color: #fef9c3; color: #92400e; border: 1px solid #fde047; padding: 0.3em 0.6em; font-size: 0.7rem;}
        .badge-shift-malam { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.3em 0.6em; font-size: 0.7rem;}
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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Manajemen User</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Akses Login & Keamanan</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-dark fw-bold shadow-sm rounded-pill px-3" onclick="bukaModalTambahUser()">
                    <i class="fas fa-user-plus me-1"></i> <span class="d-none d-sm-inline">Tambah User</span>
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
            
            <div class="card bg-white card-custom d-flex flex-column h-100">
                
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-shrink-0">
                    <h6 class="mb-0 text-dark fw-bolder"><i class="fas fa-shield-alt text-muted me-2"></i>Daftar Operator Terdaftar</h6>
                    <span class="badge bg-light text-muted border border-secondary border-opacity-25 px-3 py-2 rounded-pill">
                        Total: <?= count($users) ?> Akun
                    </span>
                </div>

                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted small text-uppercase fw-bold">
                            <tr>
                                <th class="ps-4 py-3 border-0">Profil Operator</th>
                                <th class="py-3 border-0">Kontak</th>
                                <th class="text-center py-3 border-0">Level Hak Akses</th>
                                <th class="text-center py-3 border-0">Jadwal Shift</th>
                                <th class="text-center pe-4 py-3 border-0" width="120">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $row): 
                                $is_admin = ($row['level'] == 'Admin');
                                $badge_level = $is_admin ? 'badge-admin' : 'badge-kasir';
                                $icon_level = $is_admin ? 'fas fa-crown text-danger opacity-50' : 'fas fa-desktop text-primary opacity-50';
                                
                                $is_pagi = ($row['shift'] == 'Pagi');
                                $badge_shift = $is_pagi ? 'badge-shift-pagi' : 'badge-shift-malam';
                                $icon_shift = $is_pagi ? 'fas fa-sun text-warning' : 'fas fa-moon text-secondary';
                                
                                // Deteksi apakah baris ini adalah akun yang sedang dilogin
                                $is_me = ($row['kode_user'] == $_SESSION['user_id']);
                            ?>
                            <tr class="<?= $is_me ? 'bg-light' : '' ?>">
                                <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-secondary bg-opacity-10 text-dark rounded-circle d-flex align-items-center justify-content-center fw-bolder me-3" style="width: 42px; height: 42px;">
                                            <?= strtoupper(substr($row['nama_user'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark mb-1 d-flex align-items-center">
                                                <?= htmlspecialchars($row['nama_user']) ?> 
                                                <?php if($is_me): ?><span class="badge bg-success ms-2" style="font-size:0.6rem;">Anda</span><?php endif; ?>
                                            </div>
                                            <div class="text-muted small font-monospace"><i class="fas fa-at opacity-50 me-1"></i><?= htmlspecialchars($row['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="py-3 border-bottom-0 text-muted" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <?= htmlspecialchars($row['no_telepon']) ?: '<i class="text-muted opacity-50">Kosong</i>' ?>
                                </td>
                                
                                <td class="text-center py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge <?= $badge_level ?> rounded-pill shadow-sm">
                                        <i class="<?= $icon_level ?> me-1"></i> <?= $row['level'] ?>
                                    </span>
                                </td>
                                
                                <td class="text-center py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <span class="badge <?= $badge_shift ?> rounded-pill">
                                        <i class="<?= $icon_shift ?> me-1"></i> Shift <?= $row['shift'] ?>
                                    </span>
                                </td>
                                
                                <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                    <div class="btn-group btn-group-sm shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                        <a href="#" onclick="bukaModalEditUser('<?= $row['kode_user'] ?>')" class="btn btn-light border text-warning" title="Edit Profil">
                                            <i class="fas fa-user-edit"></i>
                                        </a>
                                        
                                        <?php if(!$is_me): ?>
                                            <button onclick="hapusData('<?= $row['kode_user'] ?>', '<?= htmlspecialchars($row['nama_user']) ?>')" class="btn btn-light border text-danger" title="Hapus Akun">
                                                <i class="fas fa-user-slash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-light border text-muted" disabled title="Tidak bisa menghapus akun sendiri saat sedang login">
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($users)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                            <i class="fas fa-users-slash fa-4x opacity-25 mb-3"></i>
                                            <h5 class="fw-bolder text-dark">Tidak Ada Data</h5>
                                            <p class="mb-0">Belum ada operator terdaftar di dalam sistem.</p>
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

<div class="modal fade" id="modalTambahUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalTambahUser">
            </div>
    </div>
</div>

<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalEditUser">
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Penangkapan Session Sukses/Error dari file tambah/edit
    <?php if(isset($_SESSION['success'])): ?>
        Swal.fire({ icon: 'success', title: 'Berhasil!', text: '<?= $_SESSION['success'] ?>', timer: 2000, showConfirmButton: false });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if(isset($_SESSION['error'])): ?>
        Swal.fire({ icon: 'error', title: 'Gagal!', text: '<?= $_SESSION['error'] ?>' });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    // Fungsi Hapus User dengan Konfirmasi Nama
    function hapusData(id, nama) {
        Swal.fire({
            title: 'Cabut Hak Akses?',
            html: `Akun <b>${nama}</b> akan dihapus secara permanen dan tidak bisa login lagi.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus Akun!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'hapus.php?id=' + id;
            }
        })
    }

    // Fungsi untuk membuka modal tambah user
    function bukaModalTambahUser() {
        // Animasi loading
        $('#kontenModalTambahUser').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-dark"></i><br>Menyiapkan form registrasi...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalTambahUser'));
        myModal.show();
        
        // Tarik form via AJAX
        $.ajax({
            url: 'tambah.php',
            type: 'GET',
            success: function(response) {
                $('#kontenModalTambahUser').html(response);
            },
            error: function() {
                $('#kontenModalTambahUser').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form.</div>');
            }
        });
    }

    // Fungsi untuk membuka modal edit user
    function bukaModalEditUser(idUser) {
        // Efek loading
        $('#kontenModalEditUser').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-warning"></i><br>Mengambil data operator...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEditUser'));
        myModal.show();
        
        // Tarik form edit via AJAX
        $.ajax({
            url: 'edit.php?id=' + idUser,
            type: 'GET',
            success: function(response) {
                $('#kontenModalEditUser').html(response);
            },
            error: function() {
                $('#kontenModalEditUser').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat profil.</div>');
            }
        });
    }
</script>

</body>
</html>