<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// ============================================================
// [FIX OTOMATIS] BUAT TABEL log_deposit & arus_kas JIKA BELUM ADA
// ============================================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS log_deposit (
        id int(11) NOT NULL AUTO_INCREMENT,
        tgl_deposit datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        kode_pelanggan varchar(20) NOT NULL,
        nominal double NOT NULL,
        keterangan text,
        kode_user varchar(10) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS arus_kas (
        id int(11) NOT NULL AUTO_INCREMENT,
        tanggal date NOT NULL,
        jenis varchar(20) NOT NULL, 
        keterangan text,
        jumlah_masuk double DEFAULT 0,
        jumlah_keluar double DEFAULT 0,
        kode_user varchar(10) NOT NULL,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");
} catch (Exception $e) {}

$script_swal = "";

// --- 1. SETUP PAGINATION & PENCARIAN ---
$keyword = isset($_GET['q']) ? $_GET['q'] : '';
$limit   = 10; 
$page    = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset  = ($page - 1) * $limit;

// --- 2. HITUNG TOTAL DATA ---
$sql_count = "SELECT COUNT(*) FROM pelanggan WHERE nama_pelanggan LIKE ? OR no_telepon LIKE ?";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute(["%$keyword%", "%$keyword%"]);
$total_data = $stmt_count->fetchColumn();
$total_pages = ceil($total_data / $limit);

// --- 3. AMBIL DATA ---
$sql = "SELECT * FROM pelanggan 
        WHERE nama_pelanggan LIKE ? OR no_telepon LIKE ? 
        ORDER BY kode_pelanggan ASC
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute(["%$keyword%", "%$keyword%"]);
$pelanggan = $stmt->fetchAll();

// --- 4. PROSES DEPOSIT ---
if (isset($_POST['simpan_deposit'])) {
    $kode_plg = $_POST['kode_plg'];
    $nominal_raw = str_replace('.', '', $_POST['nominal']); 
    $nominal     = (float) $nominal_raw;
    $ket         = $_POST['keterangan'];
    $user        = $_SESSION['user_id'];

    if ($nominal != 0) { 
        try {
            $pdo->beginTransaction();

            $stmtUpd = $pdo->prepare("UPDATE pelanggan SET saldo_deposit = saldo_deposit + ? WHERE kode_pelanggan = ?");
            $stmtUpd->execute([$nominal, $kode_plg]);

            if ($nominal > 0) {
                $stmtLog = $pdo->prepare("INSERT INTO log_deposit (tgl_deposit, kode_pelanggan, nominal, keterangan, kode_user) VALUES (NOW(), ?, ?, ?, ?)");
                $stmtLog->execute([$kode_plg, $nominal, "Top Up: $ket", $user]);
            }

            $nominal_abs = abs($nominal);
            $stmtKas = $pdo->prepare("INSERT INTO arus_kas (tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user) VALUES (?, ?, ?, ?, ?, ?)");
            
            if ($nominal > 0) {
                $stmtKas->execute([date('Y-m-d'), 'Pemasukan', "Deposit: $kode_plg ($ket)", $nominal_abs, 0, $user]);
            } else {
                $stmtKas->execute([date('Y-m-d'), 'Pengeluaran', "Koreksi Deposit: $kode_plg ($ket)", 0, $nominal_abs, $user]);
            }

            $pdo->commit();
            $script_swal = "Swal.fire({icon: 'success', title: 'Deposit Berhasil!', text: 'Saldo bertambah & tercatat di Arus Kas.', timer: 2000, showConfirmButton: false}).then(() => { window.location='index.php'; });";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = addslashes($e->getMessage());
            $script_swal = "Swal.fire('Gagal Sistem', '$msg', 'error');";
        }
    } else {
        $script_swal = "Swal.fire('Peringatan', 'Nominal deposit tidak boleh 0.', 'warning');";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Master Pelanggan | POS System</title>
    
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
        .card-custom { border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; display: flex; flex-direction: column;}
        .table-responsive-custom { flex-grow: 1; overflow-y: auto; background-color: #fff; min-height: 40vh;}
        thead th { position: sticky; top: 0; background-color: #f8f9fa !important; z-index: 10; box-shadow: 0 1px 0 var(--border-color); border-bottom: none !important;}
        
        .table-hover tbody tr:hover { background-color: #f8f9fa !important; }
        
        .badge-deposit { font-size: 0.85em; font-weight: 700; padding: 0.4em 0.8em; border-radius: 8px; border: 1px solid transparent; letter-spacing: 0.5px;}
        .badge-plus { background-color: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .badge-minus { background-color: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        
        /* STYLE PAGINATION RAPI */
        .pagination { margin-bottom: 0; }
        .pagination .page-link { border-radius: 8px; margin: 0 3px; border: 1px solid var(--border-color); color: var(--text-dark); font-weight: 600; padding: 0.4rem 0.8rem;}
        .pagination .page-item.active .page-link { background-color: var(--primary-color); border-color: var(--primary-color); color: white; box-shadow: 0 2px 5px rgba(26, 86, 219, 0.3); }
        .pagination .page-item.disabled .page-link { background-color: #f8f9fa; color: #adb5bd; border-color: var(--border-color); }
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
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Master Pelanggan</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Data Pelanggan & Dompet Deposit</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-primary fw-bold shadow-sm rounded-pill px-3" onclick="bukaModalTambahPelanggan()">
                    <i class="fas fa-plus me-1"></i> <span class="d-none d-sm-inline">Pelanggan Baru</span>
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
                
                <div class="card-header bg-white p-3 border-bottom flex-shrink-0">
                    <div class="row align-items-center">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <h6 class="mb-0 text-primary fw-bolder"><i class="fas fa-users text-primary opacity-75 me-2"></i>Daftar Buku Kontak</h6>
                        </div>
                        <div class="col-md-6">
                            <form class="input-group shadow-sm" method="GET" style="border-radius: 8px; overflow:hidden;">
                                <span class="input-group-text bg-white border-0 text-muted"><i class="fas fa-search"></i></span>
                                <input class="form-control border-0 bg-white" type="search" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="Cari Nama Pelanggan atau No WA..." autocomplete="off">
                                <button class="btn btn-light border-start text-dark fw-bold px-4" type="submit">Cari</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card-body p-0 table-responsive-custom">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="text-muted small text-uppercase fw-bold">
                            <tr>
                                <th class="ps-4 py-3 border-0">Profil Pelanggan</th>
                                <th class="py-3 border-0 d-none d-md-table-cell">Kontak & Alamat</th>
                                <th class="text-end py-3 border-0" width="180">Saldo Deposit</th>
                                <th class="text-center pe-4 py-3 border-0" width="140">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($pelanggan) > 0): ?>
                                <?php foreach($pelanggan as $row): ?>
                                <tr>
                                    <td class="ps-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold me-3" style="width: 40px; height: 40px;">
                                                <?= strtoupper(substr($row['nama_pelanggan'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bolder text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($row['nama_pelanggan']) ?></div>
                                                <div class="text-muted small"><i class="fas fa-id-badge opacity-50 me-1"></i><?= $row['kode_pelanggan'] ?></div>
                                                
                                                <div class="d-md-none mt-2">
                                                    <?php if(!empty($row['no_telepon'])): ?>
                                                        <div class="small fw-semibold text-primary"><i class="fab fa-whatsapp me-1"></i><?= htmlspecialchars($row['no_telepon']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td class="py-3 border-bottom-0 d-none d-md-table-cell" style="border-bottom: 1px solid var(--border-color) !important;">
                                        <?php if(!empty($row['no_telepon'])): ?>
                                            <div class="fw-semibold text-primary mb-1"><i class="fab fa-whatsapp opacity-75 me-2"></i><?= htmlspecialchars($row['no_telepon']) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic mb-1 d-block">No HP Kosong</span>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['alamat'])): ?>
                                            <div class="small text-muted text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($row['alamat']) ?>">
                                                <i class="fas fa-map-marker-alt opacity-50 me-1"></i><?= htmlspecialchars($row['alamat']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-end py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                        <?php 
                                            $saldo = $row['saldo_deposit'] ?? 0;
                                            $cls_saldo = ($saldo < 0) ? 'badge-minus' : 'badge-plus';
                                        ?>
                                        <span class="badge-deposit <?= $cls_saldo ?> shadow-sm d-inline-block">
                                            Rp <?= number_format($saldo, 0, ',', '.') ?>
                                        </span>
                                    </td>

                                    <td class="text-center pe-4 py-3 border-bottom-0" style="border-bottom: 1px solid var(--border-color) !important;">
                                        <?php if($row['kode_pelanggan'] == 'UMUM'): ?>
                                            <span class="badge bg-light text-muted border border-secondary border-opacity-25 px-3 py-2"><i class="fas fa-lock me-1"></i> Terkunci</span>
                                        <?php else: ?>
                                            <div class="btn-group btn-group-sm shadow-sm" style="border-radius: 8px; overflow:hidden;">
                                                <button class="btn btn-success fw-bold px-2" onclick="bukaModalDeposit('<?= $row['kode_pelanggan'] ?>', '<?= addslashes($row['nama_pelanggan']) ?>')" title="Isi Saldo Deposit">
                                                    <i class="fas fa-wallet"></i>
                                                </button>
                                                <a href="#" onclick="bukaModalEditPelanggan('<?= $row['kode_pelanggan'] ?>')" class="btn btn-light border text-warning px-2" title="Edit Profil">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="#" onclick="hapusPelanggan('<?= $row['kode_pelanggan'] ?>', '<?= addslashes($row['nama_pelanggan']) ?>')" class="btn btn-light border text-danger px-2" title="Hapus Pelanggan">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted">
                                        <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
                                        <h6 class="fw-bold text-dark">Tidak ada hasil.</h6>
                                        <p class="small">Pelanggan yang Anda cari tidak ditemukan dalam database.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if($total_pages > 1): ?>
                <div class="card-footer bg-white border-top p-3 d-flex justify-content-center flex-shrink-0">
                    <nav>
                        <ul class="pagination mb-0 shadow-sm" style="border-radius: 8px; overflow:hidden;">
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link border-0" href="?page=<?= $page - 1 ?>&q=<?= $keyword ?>"><i class="fas fa-chevron-left"></i></a>
                            </li>

                            <?php
                            $tampil_awal = ($page > 2) ? $page - 1 : 1;
                            $tampil_akhir = ($page < ($total_pages - 1)) ? $page + 1 : $total_pages;

                            if ($tampil_awal > 1) {
                                echo '<li class="page-item"><a class="page-link border-0" href="?page=1&q='.$keyword.'">1</a></li>';
                                if ($tampil_awal > 2) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>';
                            }

                            for ($i = $tampil_awal; $i <= $tampil_akhir; $i++) {
                                $active = ($i == $page) ? 'active' : '';
                                echo '<li class="page-item '.$active.'"><a class="page-link border-0" href="?page='.$i.'&q='.$keyword.'">'.$i.'</a></li>';
                            }

                            if ($tampil_akhir < $total_pages) {
                                if ($tampil_akhir < ($total_pages - 1)) echo '<li class="page-item disabled"><span class="page-link border-0">...</span></li>';
                                echo '<li class="page-item"><a class="page-link border-0" href="?page='.$total_pages.'&q='.$keyword.'">'.$total_pages.'</a></li>';
                            }
                            ?>

                            <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link border-0" href="?page=<?= $page + 1 ?>&q=<?= $keyword ?>"><i class="fas fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDeposit" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-success text-white border-0 px-4 py-3">
                <h5 class="modal-title fw-bolder mb-0"><i class="fas fa-wallet me-2"></i>Top Up Dompet Deposit</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal"></button>
            </div>
            
            <form method="POST">
                <div class="modal-body p-4 bg-light">
                    
                    <div class="mb-4 text-center bg-white p-3 rounded-3 border shadow-sm">
                        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 1px;">Nama Pelanggan</small>
                        <h4 class="fw-bolder text-primary mt-1 mb-0" id="inputNamaDisplay">...</h4>
                        <input type="hidden" name="kode_plg" id="inputKode">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Nominal Uang Masuk (Rp)</label>
                        <div class="input-group input-group-lg shadow-sm" style="border-radius: 8px; overflow:hidden;">
                            <span class="input-group-text bg-white fw-bold border-0 text-success"><i class="fas fa-money-bill-wave"></i></span>
                            <input type="text" name="nominal" id="inputNominal" class="form-control border-0 fw-bolder text-success fs-4" required placeholder="0" autocomplete="off">
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold small text-muted">Keterangan / Metode Bayar</label>
                        <textarea name="keterangan" class="form-control border-0 shadow-sm" style="border-radius: 8px;" rows="2" placeholder="Contoh: Transfer BCA a/n Budi, Tunai di Kasir..."></textarea>
                    </div>

                </div>
                
                <div class="modal-footer bg-white border-top px-4 py-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-light text-muted border fw-bold rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="simpan_deposit" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">
                        <i class="fas fa-save me-1"></i> Proses Deposit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTambahPelanggan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalTambahPelanggan">
            </div>
    </div>
</div>

<div class="modal fade" id="modalEditPelanggan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;" id="kontenModalEditPelanggan">
            </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Fungsi Buka Modal Deposit
    function bukaModalDeposit(kode, nama) {
        document.getElementById('inputKode').value = kode;
        document.getElementById('inputNamaDisplay').innerText = nama;
        document.getElementById('inputNominal').value = ''; 
        
        var myModal = new bootstrap.Modal(document.getElementById('modalDeposit'));
        myModal.show();
        
        // Auto focus ke input uang
        setTimeout(() => { document.getElementById('inputNominal').focus(); }, 400);
    }

    // Format Ribuan Otomatis
    $('#inputNominal').on('keyup', function(e) {
        let val = $(this).val().replace(/[^0-9-]/g, ''); // Izinkan minus jika koreksi
        if(val !== "") {
            // Logika format uang yg lebih aman
            let isNegative = val.startsWith('-');
            val = val.replace('-', '');
            let formatted = new Intl.NumberFormat('id-ID').format(val);
            $(this).val(isNegative ? '-' + formatted : formatted);
        } else {
            $(this).val("");
        }
    });

    // Fungsi SweetAlert Hapus Pelanggan
    function hapusPelanggan(id, nama) {
        Swal.fire({
            title: 'Hapus Pelanggan?',
            html: `Data <b>${nama}</b> beserta histori depositnya akan terhapus.`,
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

    // Fungsi untuk membuka modal tambah pelanggan
    function bukaModalTambahPelanggan() {
        // Tampilkan animasi loading sebelum data selesai diunduh
        $('#kontenModalTambahPelanggan').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-primary"></i><br>Menyiapkan form...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalTambahPelanggan'));
        myModal.show();
        
        // Tarik tampilan form tambah.php via AJAX
        $.ajax({
            url: 'tambah.php',
            type: 'GET',
            success: function(response) {
                $('#kontenModalTambahPelanggan').html(response);
            },
            error: function() {
                $('#kontenModalTambahPelanggan').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat form pelanggan.</div>');
            }
        });
    }

    // Fungsi untuk membuka modal edit pelanggan
    function bukaModalEditPelanggan(idPelanggan) {
        // Tampilkan animasi loading
        $('#kontenModalEditPelanggan').html('<div class="p-5 text-center text-muted"><i class="fas fa-circle-notch fa-spin fa-3x mb-3 text-warning"></i><br>Mengambil data pelanggan...</div>');
        
        var myModal = new bootstrap.Modal(document.getElementById('modalEditPelanggan'));
        myModal.show();
        
        // Tarik tampilan form edit.php via AJAX
        $.ajax({
            url: 'edit.php?id=' + idPelanggan,
            type: 'GET',
            success: function(response) {
                $('#kontenModalEditPelanggan').html(response);
            },
            error: function() {
                $('#kontenModalEditPelanggan').html('<div class="p-5 text-center text-danger"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><br>Gagal memuat profil pelanggan.</div>');
            }
        });
    }
</script>

</body>
</html>