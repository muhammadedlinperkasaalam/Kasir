<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';


// --- AUTO SEED: shortcut tambahan kasir ---
$defaultShortcuts = [
    ['buka_file_whatsapp', 'ALT+W', 'Buka File Desain WhatsApp', 'KASIR & TRANSAKSI'],
    ['buka_print_analyzer', 'ALT+A', 'Buka Print Analyzer', 'KASIR & TRANSAKSI'],
    ['cetak_struk', 'ALT+C', 'Cetak Struk Terakhir', 'KASIR & TRANSAKSI'],
    ['kirim_nota_wa', 'ALT+K', 'Kirim Nota WhatsApp', 'KASIR & TRANSAKSI'],
    ['buka_manajemen_order', 'ALT+O', 'Buka Manajemen Order', 'KASIR & TRANSAKSI'],
];
try {
    $cekTbl = $pdo->query("SHOW TABLES LIKE 'settings_shortcut'");
    if ($cekTbl && $cekTbl->rowCount() > 0) {
        $cekShortcutStmt = $pdo->prepare("SELECT COUNT(*) FROM settings_shortcut WHERE kode_aksi = ?");
        $insertShortcutStmt = $pdo->prepare("INSERT INTO settings_shortcut (kode_aksi, tombol, label_aksi, kategori) VALUES (?, ?, ?, ?)");
        foreach ($defaultShortcuts as $sc) {
            $cekShortcutStmt->execute([$sc[0]]);
            if ((int)$cekShortcutStmt->fetchColumn() === 0) {
                $insertShortcutStmt->execute($sc);
            }
        }
    }
} catch (Exception $e) { /* abaikan agar halaman setting tetap bisa dibuka */ }

// --- 1. PROSES SIMPAN (UPDATE) ---
if (isset($_POST['simpan'])) {
    try {
        $pdo->beginTransaction();
        foreach ($_POST['tombol'] as $id => $val) {
            $stmt = $pdo->prepare("UPDATE settings_shortcut SET tombol = ? WHERE id = ?");
            $stmt->execute([$val, $id]);
        }
        $pdo->commit();
        echo "<script>alert('Berhasil! Pengaturan shortcut telah diperbarui.'); window.location='shortcut.php';</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Gagal menyimpan: " . $e->getMessage() . "');</script>";
    }
}

// --- 2. AMBIL SEMUA DATA DARI DATABASE (DINAMIS) ---
$all_data = $pdo->query("SELECT * FROM settings_shortcut ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- 3. PISAHKAN DATA BERDASARKAN KATEGORI ---
$list_kasir = [];
$list_dashboard = [];

foreach ($all_data as $item) {
    if ($item['kategori'] === 'KASIR & TRANSAKSI') {
        $list_kasir[] = $item;
    } else {
        $list_dashboard[] = $item;
    }
}

// --- 4. DAFTAR TOMBOL YANG TERSEDIA ---
$list_keys = [
    'NONE',
    'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
    'INSERT', 'INS', 'HOME', 'END', 'DELETE', 'DEL', 'PAGEUP', 'PGUP', 'PAGEDOWN', 'PGDN', 'ESCAPE',
    'ALT+W', 'ALT+A', 'ALT+C', 'ALT+K', 'ALT+O', 'ALT+P', 'ALT+M',
    'CTRL+W', 'CTRL+A', 'CTRL+C', 'CTRL+K', 'CTRL+O', 'CTRL+P', 'CTRL+M'
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Shortcut | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        
        body { 
            background-color: #f8f9fa; 
            font-family: 'Inter', sans-serif; 
            display: flex; 
            min-height: 100vh; 
            color: #1e293b; 
            margin: 0;
        }
        
        /* Sidebar Styling */
        .sidebar { 
            width: 250px; 
            min-width: 250px; 
            background: white; 
            border-right: 1px solid #e9ecef; 
        }

        /* Main Content Styling - FULL WIDTH CONFIG */
        .main-content { 
            flex: 1; /* Mengambil seluruh sisa ruang kanan */
            padding: 25px; 
            background: #f8f9fa; 
            overflow-y: auto;
        }
        
        /* Layout Full Width */
        .container-fluid-custom {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        /* Cards & Table */
        .card-custom { 
            border: none; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.03); 
            background: white; 
            overflow: hidden; 
            margin-bottom: 2rem;
            width: 100%; /* Melebar penuh dalam main-content */
        }
        
        .card-header-custom { 
            padding: 20px 25px; 
            border-bottom: 1px solid #f1f5f9; 
            background: white; 
            font-weight: 700; 
        }
        
        .table-custom th { 
            border-bottom: 2px solid #e9ecef; 
            color: #64748b; 
            font-weight: 600; 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            padding: 15px 25px; 
            background-color: #f8fafc; 
        }
        
        .table-custom td { 
            vertical-align: middle; 
            border-bottom: 1px dashed #f1f3f5; 
            padding: 12px 25px; 
            font-size: 0.9rem; 
        }
        
        .table-hover tbody tr:hover { 
            background-color: #f8fafc; 
        }
        
        /* Key Select Styling */
        .key-select { 
            border-radius: 8px; 
            font-weight: 700; 
            font-size: 0.85rem; 
            text-align: center; 
            cursor: pointer; 
            background-color: #f1f5f9; 
            transition: all 0.2s; 
            max-width: 180px;
            margin: 0 auto;
        }
        
        .key-select:focus { 
            border-color: #0d6efd; 
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1); 
        }
        
        /* Status Badges */
        .badge-wa { 
            background-color: #f0fdf4; 
            color: #16a34a; 
            border: 1px solid #bbf7d0; 
            font-size: 0.65rem; 
            padding: 4px 8px; 
            border-radius: 6px; 
        }

        .btn-save {
            padding: 15px;
            border-radius: 12px;
            font-weight: 700;
            letter-spacing: 0.5px;
            box-shadow: 0 10px 20px rgba(13, 110, 253, 0.15);
        }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-keyboard text-primary me-2"></i> Pengaturan Shortcut</h4>
                <p class="text-muted small mb-0 mt-1">Konfigurasi tombol pintas untuk akses cepat seluruh menu sistem.</p>
            </div>
        </div>

        <div class="container-fluid-custom">
            
            <div class="alert alert-warning border-0 bg-white shadow-sm d-flex align-items-center p-3 rounded-4 mb-4">
                <div class="bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; min-width: 40px;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="small text-dark">
                    <strong>Saran Penggunaan:</strong> Hindari menggunakan tombol yang sama untuk dua fungsi berbeda agar tidak terjadi konflik navigasi saat operasional kasir.
                </div>
            </div>

            <form method="POST">
                
                <div class="card card-custom">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center text-primary">
                            <i class="fas fa-cash-register me-3"></i>
                            <h6 class="m-0 fw-bold">Modul Kasir & Transaksi</h6>
                        </div>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-10 rounded-pill"><?= count($list_kasir) ?> Fungsi</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Deskripsi Aksi</th>
                                        <th width="250" class="text-center">Tombol Pintas (Key)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($list_kasir)): ?>
                                        <tr><td colspan="2" class="text-center py-5 text-muted fst-italic">Data shortcut kasir tidak ditemukan.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($list_kasir as $s): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="fw-bold text-dark mb-1"><?= $s['label_aksi'] ?></div>
                                                <div class="text-muted font-monospace" style="font-size: 0.7rem;">ID: <?= $s['kode_aksi'] ?></div>
                                            </td>
                                            <td class="text-center">
                                                <select name="tombol[<?= $s['id'] ?>]" class="form-select key-select border-primary border-opacity-25 text-primary">
                                                    <?php foreach($list_keys as $key): ?>
                                                        <option value="<?= $key ?>" <?= strtoupper($s['tombol']) == $key ? 'selected' : '' ?>><?= $key ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card card-custom">
                    <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center text-dark">
                            <i class="fas fa-directions me-3 text-secondary"></i>
                            <h6 class="m-0 fw-bold">Navigasi Menu Utama</h6>
                        </div>
                        <span class="badge bg-light text-secondary border rounded-pill"><?= count($list_dashboard) ?> Menu</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Nama Menu / Tujuan</th>
                                        <th width="250" class="text-center">Tombol Pintas (Key)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($list_dashboard)): ?>
                                        <tr><td colspan="2" class="text-center py-5 text-muted fst-italic">Data shortcut navigasi tidak ditemukan.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($list_dashboard as $s): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center">
                                                    <span class="fw-bold text-dark me-2"><?= $s['label_aksi'] ?></span>
                                                    <?php if(stripos($s['kode_aksi'], 'wa') !== false): ?>
                                                        <span class="badge badge-wa">WA API</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted font-monospace" style="font-size: 0.7rem;">ID: <?= $s['kode_aksi'] ?></div>
                                            </td>
                                            <td class="text-center">
                                                <select name="tombol[<?= $s['id'] ?>]" class="form-select key-select border-secondary border-opacity-25 text-dark">
                                                    <?php foreach($list_keys as $key): ?>
                                                        <option value="<?= $key ?>" <?= strtoupper($s['tombol']) == $key ? 'selected' : '' ?>><?= $key ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="pb-5 mt-4">
                    <button type="submit" name="simpan" class="btn btn-primary btn-save w-100 fw-bold">
                        <i class="fas fa-check-circle me-2"></i> PERBARUI SEMUA SETTING SHORTCUT
                    </button>
                    <div class="text-center mt-3 text-muted small">
                        <i class="fas fa-info-circle me-1"></i> Perubahan akan langsung aktif di seluruh halaman setelah disimpan.
                    </div>
                </div>

            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
