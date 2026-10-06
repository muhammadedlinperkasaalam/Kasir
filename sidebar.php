<?php
// ==============================================================================
// 1. OTOMATIS DETEKSI BASE URL (ANTI-NYASAR / DOUBLE URL)
// Logic ini akan mendeteksi otomatis letak folder project (misal: /kasir/)
// sehingga semua link akan selalu diarahkan ke root dengan benar.
// ==============================================================================
$doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$proj_root = str_replace('\\', '/', __DIR__);
$base_url = str_replace($doc_root, '', $proj_root);
$base_url = '/' . ltrim(rtrim($base_url, '/'), '/') . '/';
if ($base_url === '//') $base_url = '/';

$current_page = $_SERVER['PHP_SELF'];

// Deteksi apakah sedang di Dashboard Utama
$is_dashboard = (basename($current_page) == 'index.php' && 
                 strpos($current_page, '/transaksi/') === false && 
                 strpos($current_page, '/master/') === false && 
                 strpos($current_page, '/laporan/') === false && 
                 strpos($current_page, '/settings/') === false);

// ==========================================
// 2. AMBIL DATA SHORTCUT DARI DATABASE
// ==========================================
$menu_shortcuts = [];
try {
    if (isset($pdo)) {
        $stmt_sidebar_sc = $pdo->query("SELECT kode_aksi, tombol FROM settings_shortcut WHERE kode_aksi LIKE 'nav_%'");
        while($row = $stmt_sidebar_sc->fetch(PDO::FETCH_ASSOC)) {
            $menu_shortcuts[$row['kode_aksi']] = strtoupper($row['tombol']);
        }
    }
} catch (Exception $e) {}

function showShortcutBadge($kode_aksi, $shortcuts_array) {
    if (!empty($shortcuts_array[$kode_aksi])) {
        return '<span class="badge bg-light text-secondary border ms-auto shadow-sm" style="font-size: 0.65rem; font-family: monospace;">' . htmlspecialchars($shortcuts_array[$kode_aksi]) . '</span>';
    }
    return '';
}

// Fungsi bantu untuk mengecek menu aktif agar otomatis terbuka (Show)
function isMenuActive($page_path, $current) {
    return strpos($current, $page_path) !== false;
}
?>

<style>
    /* Styling Dasar Sidebar Container */
    .sidebar-container {
        width: 260px; 
        height: 100vh; 
        position: sticky; 
        top: 0; 
        overflow-y: auto; 
        z-index: 1030;
        background-color: #ffffff;
        transition: left 0.3s ease;
    }

    /* Modifikasi Scrollbar Sidebar agar halus */
    .sidebar-container::-webkit-scrollbar { width: 5px; }
    .sidebar-container::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    /* Hover Effect untuk Sidebar */
    .hover-bg-light { transition: background-color 0.2s, color 0.2s; border-radius: 8px;}
    .hover-bg-light:hover { background-color: #f8f9fa !important; color: var(--primary-color) !important; }
    
    /* Indikator anak menu aktif */
    .nav-link.active-sub { color: var(--primary-color) !important; font-weight: bold; background: transparent;}
    .nav-link.active-sub::before { content: '•'; margin-right: 8px; color: var(--primary-color); }
    
    /* Rotate panah saat menu terbuka */
    .nav-link[aria-expanded="true"] .fa-chevron-down { transform: rotate(180deg); transition: transform 0.3s ease; }
    .nav-link[aria-expanded="false"] .fa-chevron-down { transform: rotate(0deg); transition: transform 0.3s ease; }

    /* Responsif HP */
    @media (max-width: 768px) {
        .sidebar-container { 
            position: fixed; 
            left: -260px; /* Disembunyikan ke luar layar */
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            z-index: 1060;
            -webkit-overflow-scrolling: touch;
        }
        .sidebar-container.show-mobile { left: 0; display: flex !important; }
        .sidebar-backdrop-mobile {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .35);
            z-index: 1050;
        }
        .sidebar-backdrop-mobile.show { display: block; }
        body.sidebar-mobile-open { overflow: hidden; }
    }
    
    /* Garis Pemisah (Divider) Khusus Menu Sub */
    .menu-divider { border-top: 1px dashed #e5e7eb; margin: 8px 0; }
</style>

<div class="d-flex flex-column flex-shrink-0 bg-white border-end shadow-sm sidebar-container" id="sidebarAddinta">
    
    <div class="p-3 border-bottom d-flex align-items-center justify-content-between sticky-top bg-white">
        <a href="<?= $base_url ?>index.php" class="d-flex align-items-center text-decoration-none text-dark">
            <div class="text-white rounded-circle d-flex align-items-center justify-content-center me-2 shadow-sm" style="width: 36px; height: 36px; background: var(--primary-color);">
                <i class="fas fa-print" style="font-size: 0.9rem;"></i>
            </div>
            <span class="fs-5 fw-bolder" style="color: var(--primary-color); letter-spacing: -0.5px;">Addinta<span class="text-dark">POS</span></span>
        </a>
        <button class="btn btn-sm btn-light border d-md-none text-danger" id="btnCloseSidebar"><i class="fas fa-times"></i></button>
    </div>

    <ul class="nav nav-pills flex-column mb-auto p-3" style="font-size: 0.95rem;">
        
        <li class="nav-item mb-1">
            <a href="<?= $base_url ?>index.php" class="nav-link d-flex align-items-center <?= $is_dashboard ? 'active bg-primary bg-opacity-10 text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-home me-3 <?= $is_dashboard ? '' : 'text-muted' ?>" style="width: 20px;"></i>
                Dashboard
            </a>
        </li>
        
        <li class="nav-item mb-1">
            <a href="<?= $base_url ?>transaksi/penjualan/index.php" class="nav-link d-flex align-items-center <?= isMenuActive('transaksi/penjualan/index.php', $current_page) ? 'active bg-primary bg-opacity-10 text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-cash-register me-3 <?= isMenuActive('transaksi/penjualan/index.php', $current_page) ? '' : 'text-muted' ?>" style="width: 20px;"></i>
                Kasir (POS)
                <?= showShortcutBadge('nav_kasir', $menu_shortcuts) ?>
            </a>
        </li>

        <?php $menu_trx_active = isMenuActive('/transaksi/pemasukan_lain/', $current_page) || isMenuActive('/transaksi/penjualan/daftar_piutang.php', $current_page) || isMenuActive('/transaksi/penjualan/daftar_piutang.php', $current_page) || isMenuActive('/transaksi/penjualan/riwayat.php', $current_page) || isMenuActive('/transaksi/pembelian/', $current_page) || isMenuActive('/transaksi/order/', $current_page) || isMenuActive('/transaksi/print_analyzer/', $current_page); ?>
        <li class="nav-item mb-1 mt-2">
            <a data-bs-toggle="collapse" href="#transaksiMenu" role="button" aria-expanded="<?= $menu_trx_active ? 'true' : 'false' ?>" class="nav-link d-flex align-items-center <?= $menu_trx_active ? 'text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-shopping-bag me-3 <?= $menu_trx_active ? '' : 'text-muted' ?>" style="width: 20px;"></i> 
                <span class="flex-grow-1">Transaksi</span>
                <i class="fas fa-chevron-down text-muted" style="font-size: 0.7rem;"></i>
            </a>
            <div class="collapse <?= $menu_trx_active ? 'show' : '' ?>" id="transaksiMenu">
                <ul class="nav flex-column ms-4 mt-1" style="font-size: 0.85rem;">
                    <li><a href="<?= $base_url ?>transaksi/print_analyzer/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/print_analyzer/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-wand-magic-sparkles me-2" style="width: 14px;"></i> Print Analyzer</a></li>
                    <li><a href="<?= $base_url ?>transaksi/order/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/order/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-tasks me-2" style="width: 14px;"></i> Manajemen Order</a></li>
                    <li><a href="<?= $base_url ?>transaksi/kas/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/kas/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-cash-register me-2" style="width: 14px;"></i> Manajemen Cash</a></li>
                    <li><a href="<?= $base_url ?>transaksi/penjualan/riwayat.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/penjualan/riwayat.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-history me-2" style="width: 14px;"></i> Riwayat Transaksi <?= showShortcutBadge('nav_riwayat', $menu_shortcuts) ?></a></li>
                    <li><a href="<?= $base_url ?>transaksi/penjualan/list_android.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/penjualan/list_android.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-mobile-screen-button me-2" style="width: 14px;"></i> List Transaksi Android</a></li>
                    <li><a href="<?= $base_url ?>transaksi/penjualan/daftar_piutang.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/penjualan/daftar_piutang.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-hand-holding-usd me-2" style="width: 14px;"></i> Kelola Piutang <?= showShortcutBadge('nav_utang', $menu_shortcuts) ?></a></li>
                    <li><a href="<?= $base_url ?>transaksi/pembelian/tambah.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/pembelian/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-box-open me-2" style="width: 14px;"></i> Restock Pembelian <?= showShortcutBadge('nav_restock', $menu_shortcuts) ?></a></li>
                    <li><a href="<?= $base_url ?>transaksi/pemasukan_lain/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/transaksi/pemasukan_lain/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-donate me-2" style="width: 14px;"></i> Pemasukan Lain <?= showShortcutBadge('nav_inlain', $menu_shortcuts) ?></a></li>
                </ul>
            </div>
        </li>

        <li class="nav-item mb-1">
            <a href="<?= $base_url ?>transaksi/pengeluaran_non_stok/riwayat.php" class="nav-link d-flex align-items-center <?= isMenuActive('transaksi/pengeluaran_non_stok', $current_page) ? 'active bg-primary bg-opacity-10 text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-wallet me-3 <?= isMenuActive('transaksi/pengeluaran_non_stok', $current_page) ? '' : 'text-muted' ?>" style="width: 20px;"></i>
                Biaya Operasional
                <?= showShortcutBadge('nav_biaya', $menu_shortcuts) ?>
            </a>
        </li>

        <?php $menu_master_active = isMenuActive('/master/', $current_page); ?>
        <li class="nav-item mb-1 mt-2">
            <a data-bs-toggle="collapse" href="#masterDataMenu" role="button" aria-expanded="<?= $menu_master_active ? 'true' : 'false' ?>" class="nav-link d-flex align-items-center <?= $menu_master_active ? 'text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-database me-3 <?= $menu_master_active ? '' : 'text-muted' ?>" style="width: 20px;"></i> 
                <span class="flex-grow-1">Master Data</span>
                <i class="fas fa-chevron-down text-muted" style="font-size: 0.7rem;"></i>
            </a>
            <div class="collapse <?= $menu_master_active ? 'show' : '' ?>" id="masterDataMenu">
                <ul class="nav flex-column ms-4 mt-1" style="font-size: 0.85rem;">
                    <li><a href="<?= $base_url ?>master/barang/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/master/barang/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-cubes me-2" style="width: 14px;"></i> Database Barang <?= showShortcutBadge('nav_barang', $menu_shortcuts) ?></a></li>
                    <li><a href="<?= $base_url ?>master/kategori/index.php" class="nav-link text-secondary py-2 <?= isMenuActive('/master/kategori/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-tags me-2" style="width: 14px;"></i> Kategori Barang</a></li>
                    <li><a href="<?= $base_url ?>master/supplier/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/master/supplier/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-truck me-2" style="width: 14px;"></i> Data Supplier</a></li>
                    <li><a href="<?= $base_url ?>master/pelanggan/index.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/master/pelanggan/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-users me-2" style="width: 14px;"></i> Buku Pelanggan <?= showShortcutBadge('nav_pelanggan', $menu_shortcuts) ?></a></li>
                    
                    <?php if(isset($_SESSION['level']) && ($_SESSION['level'] == 'admin' || $_SESSION['level'] == 'Owner')): ?>
                    <li><a href="<?= $base_url ?>master/operator/index.php" class="nav-link text-secondary py-2 <?= isMenuActive('/master/operator/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-user-shield me-2" style="width: 14px;"></i> Akses User / Kasir</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>

        <?php $menu_laporan_active = isMenuActive('/laporan/', $current_page) || (isset($_GET['stok']) && $_GET['stok'] == 'low'); ?>
        <li class="nav-item mb-1 mt-2">
            <a data-bs-toggle="collapse" href="#laporanMenu" role="button" aria-expanded="<?= $menu_laporan_active ? 'true' : 'false' ?>" class="nav-link d-flex align-items-center <?= $menu_laporan_active ? 'text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-chart-line me-3 <?= $menu_laporan_active ? '' : 'text-muted' ?>" style="width: 20px;"></i> 
                <span class="flex-grow-1">Laporan</span>
                <i class="fas fa-chevron-down text-muted" style="font-size: 0.7rem;"></i>
            </a>
            <div class="collapse <?= $menu_laporan_active ? 'show' : '' ?>" id="laporanMenu">
                <ul class="nav flex-column ms-4 mt-1" style="font-size: 0.85rem;">
                    
                    <li><a href="<?= $base_url ?>laporan/rekap_kalender.php" class="nav-link fw-bold text-primary py-2 <?= isMenuActive('/laporan/rekap_kalender.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-calendar-alt me-2" style="width: 14px;"></i> Laporan Profitabilitas</a></li>
                    <li><a href="<?= $base_url ?>laporan/harian.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/harian.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-calendar-day me-2" style="width: 14px;"></i> Laporan Harian</a></li>
                    <li><a href="<?= $base_url ?>laporan/bulanan.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/bulanan.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-calendar me-2" style="width: 14px;"></i> Laporan Bulanan</a></li>
                    <div class="menu-divider"></div>

                    <li><a href="<?= $base_url ?>laporan/setoran_harian.php" class="nav-link fw-bold text-success py-2 d-flex align-items-center <?= isMenuActive('/laporan/setoran_harian.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-file-invoice-dollar me-2" style="width: 14px;"></i> Form Setor Harian <?= showShortcutBadge('nav_setor', $menu_shortcuts) ?></a></li>
                    
                    <li><a href="<?= $base_url ?>laporan/list_setor.php" class="nav-link fw-bold text-secondary py-2 <?= isMenuActive('/laporan/list_setor.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-list-ul me-2" style="width: 14px;"></i> List Data Setoran</a></li>
                    
                    <li><a href="<?= $base_url ?>laporan/laporan_cash.php" class="nav-link fw-bold text-dark py-2 <?= isMenuActive('/laporan/laporan_cash.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-money-bill-wave me-2" style="width: 14px;"></i> Laporan Arus Kas</a></li>
                    
                    <li><a href="<?= $base_url ?>laporan/verifikasi_csv.php" class="nav-link fw-bold text-info py-2 <?= isMenuActive('/laporan/verifikasi_csv.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-wallet me-2" style="width: 14px;"></i> Cek GoPay (CSV)</a></li>
                    <li><a href="<?= $base_url ?>laporan/verifikasi_manual.php" class="nav-link fw-bold text-secondary py-2 <?= isMenuActive('/laporan/verifikasi_manual.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-check-double me-2" style="width: 14px;"></i> Verifikasi Manual</a></li>
                    <li><a href="<?= $base_url ?>laporan/verifikasi_bukti_wa.php" class="nav-link fw-bold text-success py-2 <?= isMenuActive('/laporan/verifikasi_bukti_wa.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fab fa-whatsapp me-2" style="width: 14px;"></i> Bukti Pembayaran WA</a></li>
                    <div class="menu-divider"></div>

                    <li><a href="<?= $base_url ?>laporan/penjualan/index.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/penjualan/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-chart-bar text-primary me-2" style="width: 14px;"></i> Laporan Penjualan</a></li>
                    <li><a href="<?= $base_url ?>laporan/customer_nota.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/customer_nota.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-users text-success me-2" style="width: 14px;"></i> Laporan Customer Nota</a></li>
                    <li><a href="<?= $base_url ?>laporan/ranking.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/ranking.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-trophy text-warning me-2" style="width: 14px;"></i> Laporan Terlaris</a></li>
                    <li><a href="<?= $base_url ?>laporan/stok.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/stok.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-box-open text-info me-2" style="width: 14px;"></i> Laporan Stok</a></li>
                    <li><a href="<?= $base_url ?>laporan/laba.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/laba.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-chart-pie text-success me-2" style="width: 14px;"></i> Laporan Laba Rugi</a></li>
                    
                    <li><a href="<?= $base_url ?>laporan/neraca_lajur.php" class="nav-link fw-bold text-secondary py-2 <?= isMenuActive('/laporan/neraca_lajur.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-scale-balanced me-2" style="width: 14px;"></i> Neraca Lajur</a></li>
                    <li><a href="<?= $base_url ?>laporan/neraca_saldo.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/neraca_saldo.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-balance-scale-right me-2" style="width: 14px;"></i> Neraca Saldo</a></li>
                    <li><a href="<?= $base_url ?>laporan/jurnal_umum.php" class="nav-link text-secondary py-2 <?= isMenuActive('/laporan/jurnal_umum.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-book-journal-whills me-2" style="width: 14px;"></i> Jurnal Umum</a></li>
                    <div class="menu-divider"></div>
                    
                    <li><a href="<?= $base_url ?>master/barang/index.php?stok=low" class="nav-link fw-bold text-danger py-2 <?= (isset($_GET['stok']) && $_GET['stok'] == 'low') ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-exclamation-triangle me-2" style="width: 14px;"></i> Cek Stok Habis</a></li>

                </ul>
            </div>
        </li>

        <?php if(isset($_SESSION['level']) && ($_SESSION['level'] == 'admin' || $_SESSION['level'] == 'Owner')): ?>
        <?php $menu_settings_active = isMenuActive('/settings/', $current_page); ?>
        <li class="nav-item mb-1 mt-2">
            <a data-bs-toggle="collapse" href="#pengaturanMenu" role="button" aria-expanded="<?= $menu_settings_active ? 'true' : 'false' ?>" class="nav-link d-flex align-items-center <?= $menu_settings_active ? 'text-primary fw-bold' : 'text-dark hover-bg-light' ?>">
                <i class="fas fa-cogs me-3 <?= $menu_settings_active ? '' : 'text-muted' ?>" style="width: 20px;"></i> 
                <span class="flex-grow-1">Pengaturan</span>
                <i class="fas fa-chevron-down text-muted" style="font-size: 0.7rem;"></i>
            </a>
            <div class="collapse <?= $menu_settings_active ? 'show' : '' ?>" id="pengaturanMenu">
                <ul class="nav flex-column ms-4 mt-1" style="font-size: 0.85rem;">
                    <li><a href="<?= $base_url ?>settings/toko/index.php" class="nav-link text-secondary py-2 <?= isMenuActive('/settings/toko/', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-store me-2" style="width: 14px;"></i> Identitas Toko</a></li>
                    <li><a href="<?= $base_url ?>settings/whatsapp.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/settings/whatsapp.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fab fa-whatsapp me-2" style="width: 14px;"></i> Template Nota WA <?= showShortcutBadge('nav_wa_template', $menu_shortcuts) ?></a></li>
                    <li><a href="<?= $base_url ?>settings/waha.php" class="nav-link text-secondary py-2 d-flex align-items-center <?= isMenuActive('/settings/waha.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-server me-2" style="width: 14px;"></i> Server WAHA API <?= showShortcutBadge('nav_waha', $menu_shortcuts) ?></a></li>
                    <li><a href="<?= $base_url ?>settings/shortcut.php" class="nav-link text-secondary py-2 <?= isMenuActive('/settings/shortcut.php', $current_page) ? 'active-sub' : 'hover-bg-light' ?>"><i class="fas fa-keyboard me-2" style="width: 14px;"></i> Shortcut Keyboard</a></li>
                </ul>
            </div>
        </li>
        <?php endif; ?>
    </ul>
    
    <div class="p-3 border-top mt-auto bg-light sticky-bottom text-center">
        <a href="<?= $base_url ?>auth/logout.php" onclick="return confirm('Yakin ingin menutup shift kasir dan keluar?')" class="btn btn-outline-danger w-100 fw-bold rounded-pill shadow-sm py-2" style="transition: 0.2s;">
            <i class="fas fa-power-off me-2"></i> KELUAR SISTEM
        </a>
        <div class="mt-2 text-muted fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">V 2.0 &copy; <?= date('Y') ?> Addinta</div>
    </div>
</div>
<div class="sidebar-backdrop-mobile" id="sidebarBackdropMobile"></div>
<script>
(function() {
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function() {
        const sidebar = document.getElementById('sidebarAddinta');
        const closeBtn = document.getElementById('btnCloseSidebar');
        const backdrop = document.getElementById('sidebarBackdropMobile');
        if (!sidebar) return;

        function openSidebar() {
            sidebar.style.display = 'flex';
            sidebar.classList.add('show-mobile');
            if (backdrop) backdrop.classList.add('show');
            document.body.classList.add('sidebar-mobile-open');
        }

        function closeSidebar() {
            sidebar.classList.remove('show-mobile');
            if (window.innerWidth <= 768) sidebar.style.display = '';
            if (backdrop) backdrop.classList.remove('show');
            document.body.classList.remove('sidebar-mobile-open');
        }

        // Tombol hamburger dari halaman lama/baru. Jangan ambil tombol close.
        function handleSidebarTap(e) {
            const openBtn = e.target.closest('#btnOpenSidebar, #btnToggleSidebar, [data-sidebar-toggle], .btn-sidebar-mobile, .navbar-toggler, .btn-light.d-md-none:not(#btnCloseSidebar)');
            if (openBtn && !openBtn.closest('#sidebarAddinta')) {
                e.preventDefault();
                openSidebar();
                return;
            }

            const closeTarget = e.target.closest('#btnCloseSidebar, #sidebarBackdropMobile');
            if (closeTarget) {
                e.preventDefault();
                closeSidebar();
            }
        }
        document.addEventListener('click', handleSidebarTap, true);
        document.addEventListener('touchstart', handleSidebarTap, {capture:true, passive:false});

        if (closeBtn) closeBtn.addEventListener('click', function(e) { e.preventDefault(); closeSidebar(); });
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        // Fallback collapse untuk Android jika Bootstrap JS tidak aktif/terlambat load.
        document.querySelectorAll('#sidebarAddinta [data-bs-toggle="collapse"]').forEach(function(toggle) {
            toggle.addEventListener('click', function(e) {
                const href = toggle.getAttribute('href') || toggle.getAttribute('data-bs-target') || '';
                if (!href || href.charAt(0) !== '#') return;
                const target = document.querySelector(href);
                if (!target) return;

                if (!window.bootstrap || !window.bootstrap.Collapse) {
                    e.preventDefault();
                    const isShown = target.classList.contains('show');
                    target.classList.toggle('show', !isShown);
                    toggle.setAttribute('aria-expanded', isShown ? 'false' : 'true');
                }
            });
        });

        // Setelah klik menu biasa di Android, tutup sidebar agar halaman terasa responsif.
        sidebar.querySelectorAll('a.nav-link[href]:not([data-bs-toggle="collapse"])').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });
    });
})();
</script>
