<?php
session_start();
require_once '../../config/database.php';
header('Content-Type: application/json');

// ================================================================
// NOTIFIKASI WA KE ADMIN SETIAP TRANSAKSI BARU TERSIMPAN
// ================================================================
// Nomor admin yang menerima notifikasi. Ganti nilainya di sini kalau nomor berubah.
const WA_ADMIN_NOTIFY_NUMBER = '6289687288927';

function kirim_wa_notifikasi_admin($pdo, $no_penjualan, $nama_pelanggan, $grand_total) {
    if (empty(WA_ADMIN_NOTIFY_NUMBER)) return;
    try {
        $set = $pdo->query("SELECT base_url, session_name FROM waha_setting LIMIT 1")->fetch();
        $base = rtrim($set['base_url'] ?? 'http://localhost:8000', '/');
        // base_url di tabel waha_setting disimpan tanpa "/api", endpoint asli ada di /api/send-message.
        if (!preg_match('#/api$#', $base)) { $base .= '/api'; }

        $pesan = $nama_pelanggan;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base . '/send-message',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'session_id' => $set['session_name'] ?? 'default',
                'phone' => WA_ADMIN_NOTIFY_NUMBER,
                'message' => $pesan,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        // Notifikasi gagal tidak boleh menggagalkan transaksi yang sudah tersimpan.
        error_log('Gagal kirim notifikasi WA admin: ' . $e->getMessage());
    }
}

// ================================================================
// [AUTO FIX] UPDATE STRUKTUR DATABASE (SUPER LENGKAP)
// ================================================================
try {
    $cek_tabel = $pdo->query("SHOW TABLES LIKE 'log_deposit'");
    if ($cek_tabel->rowCount() == 0) {
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
            no_penjualan char(10) DEFAULT NULL,
            metode_pembayaran varchar(50) DEFAULT NULL,
            tanggal date NOT NULL,
            jenis varchar(20) NOT NULL, 
            keterangan text,
            jumlah_masuk double DEFAULT 0,
            jumlah_keluar double DEFAULT 0,
            kode_user varchar(10) NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS pemasukan_lain (
            id int(11) NOT NULL AUTO_INCREMENT,
            tgl_pemasukan date NOT NULL,
            kode_pelanggan varchar(20) DEFAULT NULL,
            kategori varchar(100) DEFAULT NULL,
            nominal double DEFAULT 0,
            metode_bayar varchar(50) DEFAULT NULL,
            keterangan text,
            kode_user varchar(10) NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB");
    }
    
    // Auto Add Kolom ke Pelanggan
    $cols_pelanggan = ['sisa_utang' => 'DOUBLE DEFAULT 0', 'saldo_deposit' => 'DOUBLE DEFAULT 0'];
    foreach ($cols_pelanggan as $col => $def) {
        try { $pdo->query("SELECT $col FROM pelanggan LIMIT 1"); } 
        catch (Exception $e) { $pdo->exec("ALTER TABLE pelanggan ADD COLUMN $col $def"); }
    }

    // Auto Add Kolom ke Penjualan
    $cols_penjualan = [
        'diskon_global' => 'DOUBLE DEFAULT 0',
        'ongkir' => 'DOUBLE DEFAULT 0',
        'nominal_deposit' => 'DOUBLE DEFAULT 0',
        'uang_pelunasan_utang' => 'DOUBLE DEFAULT 0',
        'metode_pembayaran' => 'VARCHAR(50) DEFAULT NULL',
        'is_verif_qris' => "ENUM('Y','N') DEFAULT 'N'",
        'tgl_verif_qris' => 'DATE DEFAULT NULL'
    ];
    foreach ($cols_penjualan as $col => $def) {
        try { $pdo->query("SELECT $col FROM penjualan LIMIT 1"); } 
        catch (Exception $e) { $pdo->exec("ALTER TABLE penjualan ADD COLUMN $col $def"); }
    }

    // Auto Add Kolom ke Arus Kas
    $cols_arus_kas = [
        'no_penjualan' => 'CHAR(10) DEFAULT NULL',
        'metode_pembayaran' => 'VARCHAR(50) DEFAULT NULL'
    ];
    foreach ($cols_arus_kas as $col => $def) {
        try { $pdo->query("SELECT $col FROM arus_kas LIMIT 1"); } 
        catch (Exception $e) { $pdo->exec("ALTER TABLE arus_kas ADD COLUMN $col $def"); }
    }

    // Auto struktur order_files untuk item dari Print Analyzer Modal
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_files (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        no_penjualan VARCHAR(20) DEFAULT NULL,
        nama_file VARCHAR(255) DEFAULT NULL,
        path_file VARCHAR(255) DEFAULT NULL,
        waktu_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status_baca VARCHAR(20) DEFAULT NULL,
        jenis_kertas VARCHAR(100) DEFAULT NULL,
        ukuran_kertas VARCHAR(50) DEFAULT NULL,
        qty_cetak INT(11) DEFAULT 1,
        printed_count INT(11) DEFAULT 0,
        finishing VARCHAR(50) DEFAULT NULL,
        halaman VARCHAR(100) DEFAULT NULL,
        total_halaman INT DEFAULT 0,
        bw_pages INT DEFAULT 0,
        color_25_pages INT DEFAULT 0,
        color_50_pages INT DEFAULT 0,
        color_75_pages INT DEFAULT 0,
        color_100_pages INT DEFAULT 0,
        estimasi_harga DECIMAL(15,0) DEFAULT 0,
        analyzer_job_id BIGINT UNSIGNED NULL,
        page_per_sheet INT NOT NULL DEFAULT 1,
        print_orientation VARCHAR(20) NOT NULL DEFAULT 'auto',
        print_grayscale TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    foreach ([
        'total_halaman' => 'INT DEFAULT 0',
        'bw_pages' => 'INT DEFAULT 0',
        'color_25_pages' => 'INT DEFAULT 0',
        'color_50_pages' => 'INT DEFAULT 0',
        'color_75_pages' => 'INT DEFAULT 0',
        'color_100_pages' => 'INT DEFAULT 0',
        'estimasi_harga' => 'DECIMAL(15,0) DEFAULT 0',
        'analyzer_job_id' => 'BIGINT UNSIGNED NULL',
        'page_per_sheet' => 'INT NOT NULL DEFAULT 1',
        'print_orientation' => "VARCHAR(20) NOT NULL DEFAULT 'auto'",
        'print_grayscale' => 'TINYINT(1) NOT NULL DEFAULT 0'
    ] as $col => $def) {
        try { $pdo->query("SELECT $col FROM order_files LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN $col $def"); }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_pekerjaan (id INT AUTO_INCREMENT PRIMARY KEY, no_penjualan VARCHAR(20), status_order VARCHAR(50), catatan TEXT, waktu_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_tasks (id INT AUTO_INCREMENT PRIMARY KEY, no_penjualan VARCHAR(20), nama_task VARCHAR(255), status_task VARCHAR(20)) ENGINE=InnoDB");
} catch (Exception $e) {}
// ================================================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Ambil data
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);
    if (!$input && isset($_POST['data'])) {
        $input = json_decode($_POST['data'], true);
    }

    if (!$input) { echo json_encode(['status' => 'error', 'message' => 'Data invalid']); exit; }

    $kode_pelanggan = $input['kode_pelanggan'];
    $kode_user      = $_SESSION['user_id'];
    
    $uang_bayar_belanja      = (float) $input['uang_bayar'];
    $uang_bayar_utang_lama   = (float) ($input['uang_pelunasan_utang'] ?? 0);
    $nominal_deposit_dipakai = (float) ($input['nominal_deposit'] ?? 0); 
    $nominal_deposit_baru    = (float) ($input['nominal_deposit_baru'] ?? 0); 
    $diskon_global           = (float) ($input['diskon_global'] ?? 0);
    $ongkir                  = (float) ($input['ongkir'] ?? 0);
    $metode                  = $input['metode']; 
    $items                   = $input['items'];

    // Tanggal transaksi bisa dicustom dari kasir. Default tetap tanggal sistem komputer/server.
    $tanggal_transaksi_input = trim((string)($input['tanggal_transaksi'] ?? date('Y-m-d')));
    $tanggalObj = DateTime::createFromFormat('Y-m-d', $tanggal_transaksi_input);
    $tanggalErrors = DateTime::getLastErrors();
    if (!$tanggalObj || ($tanggalErrors && ($tanggalErrors['warning_count'] > 0 || $tanggalErrors['error_count'] > 0))) {
        $tanggalObj = new DateTime();
    }
    $tanggal_transaksi = $tanggalObj->format('Y-m-d');
    $jam_transaksi = date('H:i:s');

    try {
        $pdo->beginTransaction();

        // 0. AMBIL DATA OPERATOR & MAX NO PENJUALAN
        $stmtOp = $pdo->prepare("SELECT shift FROM operator WHERE kode_user = ?");
        $stmtOp->execute([$kode_user]);
        $dOp = $stmtOp->fetch();
        $shift_sekarang = $dOp['shift'] ?? '1';

        $prefix = "JL" . $tanggalObj->format('ym');
        $stmtN = $pdo->prepare("SELECT MAX(no_penjualan) as max_no FROM penjualan WHERE no_penjualan LIKE ?");
        $stmtN->execute([$prefix . '%']);
        $rowN = $stmtN->fetch();
        $urut = $rowN['max_no'] ? (int)substr($rowN['max_no'], -4) + 1 : 1;
        $no_penjualan = $prefix . str_pad($urut, 4, '0', STR_PAD_LEFT);

        // 1. SIAPKAN PREPARED STATEMENTS
        $stmtGetBrg = $pdo->prepare("SELECT * FROM barang WHERE kode_barang = ?");
        $stmtGetKomp = $pdo->prepare("SELECT * FROM pengeluaran_barang WHERE kode_barang = ?");
        $stmtUpdStokBahan = $pdo->prepare("UPDATE barang SET stok = stok - ? WHERE kode_barang = ?");
        $stmtUpdStokUtama = $pdo->prepare("UPDATE barang SET stok = stok - ? WHERE kode_barang = ?");
        
        $stmtInsertItem = $pdo->prepare("INSERT INTO penjualan_item (
            no_penjualan, tgl_penjualan, kode_user, kode_pelanggan, kode_barang,
            harga_beli_kotor, harga_beli_bersih, harga_jual, diskon, jumlah,
            stok_awal, stok_terakhir, keterangan, shift_new, pelunasan, subtotal
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        // 2. PROSES LOOPING ITEMS
        $total_omzet = 0; 
        $total_laba_nota = 0;
        $analyzerOrderFiles = [];

        foreach ($items as $item) {
            $kode_brg_jual = $item['kode_barang'];
            $qty_jual      = (float)$item['jumlah'];
            $harga_jual    = (float)$item['harga'];
            $diskon_item   = (float)($item['diskon'] ?? 0);
            $ket_item = '';
            $metaJobId = 0;
            if (!empty($item['analyzer_meta']) && is_array($item['analyzer_meta'])) {
                $m = $item['analyzer_meta'];
                $fileName = trim((string)($m['file_name'] ?? ''));
                $catLabel = trim((string)($m['category_label'] ?? $m['category'] ?? ''));
                $pagesMeta = (int)($m['pages'] ?? 0);
                $qtyMeta = (int)($m['qty_cetak'] ?? 1);
                $pagesPerSheetMeta = (int)($m['pages_per_sheet'] ?? 1);
                $sheetsMeta = (int)($m['sheets'] ?? $pagesMeta);
                $profileMeta = trim((string)($m['profile_name'] ?? ''));
                $pricingBasisMeta = (string)($m['pricing_basis'] ?? 'sheet');
                $ketPageSheet = $pricingBasisMeta === 'sheet'
                    ? "{$pagesMeta} hlm dalam {$sheetsMeta} sheet x {$qtyMeta}"
                    : ($pagesPerSheetMeta > 1 ? "{$pagesMeta} hlm / {$pagesPerSheetMeta} page per sheet = {$sheetsMeta} sheet x {$qtyMeta}" : "{$pagesMeta} hlm x {$qtyMeta}");
                $borderlessMeta = !empty($m['borderless']);
                $grayscaleMeta = !empty($m['print_grayscale']);
                $orientationMeta = strtolower(trim((string)($m['orientation'] ?? 'auto')));
                if (!in_array($orientationMeta, ['auto','portrait','landscape'], true)) $orientationMeta = 'auto';
                $orientationLabelMeta = $orientationMeta === 'landscape' ? 'Landscape' : ($orientationMeta === 'portrait' ? 'Portrait' : 'Auto');
                $ket_item = trim("Analyzer: {$fileName} | {$catLabel} | {$ketPageSheet}" . ($profileMeta ? " | {$profileMeta}" : '') . " | {$orientationLabelMeta}" . ($borderlessMeta ? ' | Borderless' : '') . ($grayscaleMeta ? ' | Grayscale' : ''));
                if (!empty($m['job_id'])) {
                    $metaJobId = (int)$m['job_id'];
                    if (empty($analyzerOrderFiles[$metaJobId])) {
                        $analyzerOrderFiles[$metaJobId] = $m;
                        $analyzerOrderFiles[$metaJobId]['estimasi_harga'] = 0;
                    }
                }
            }
            
            $stmtGetBrg->execute([$kode_brg_jual]);
            $brg = $stmtGetBrg->fetch(PDO::FETCH_ASSOC);
            
            if(!$brg) continue; 

            $subtotal = ($harga_jual * $qty_jual) - $diskon_item;
            $total_omzet += $subtotal;
            if (!empty($metaJobId) && isset($analyzerOrderFiles[$metaJobId])) {
                $analyzerOrderFiles[$metaJobId]['estimasi_harga'] += max(0, $subtotal);
            }

            $stok_awal = (float)($brg['stok'] ?? 0);
            $stok_akhir = $stok_awal;
            
            $stmtGetKomp->execute([$kode_brg_jual]);
            $komposisi = $stmtGetKomp->fetchAll(PDO::FETCH_ASSOC);

            if (count($komposisi) > 0) {
                foreach ($komposisi as $bahan) {
                    $kode_bahan = $bahan['kode_keluar'];
                    $qty_bahan = $bahan['jumlah'] * $qty_jual;
                    $stmtUpdStokBahan->execute([$qty_bahan, $kode_bahan]);
                }
            } else {
                $stmtUpdStokUtama->execute([$qty_jual, $kode_brg_jual]);
                $stok_akhir = $stok_awal - $qty_jual;
            }

            $harga_beli_bersih = (float)($brg['harga_beli_bersih'] ?? 0);
            $harga_beli_kotor  = (float)($brg['harga_beli'] ?? 0);
            $laba_item = ($harga_jual - $harga_beli_bersih) * $qty_jual;
            $total_laba_nota += $laba_item;

            $stmtInsertItem->execute([
                $no_penjualan, $tanggal_transaksi, $kode_user, $kode_pelanggan, $kode_brg_jual,
                $harga_beli_kotor, $harga_beli_bersih, $harga_jual, $diskon_item, 
                $qty_jual, $stok_awal, $stok_akhir, $ket_item, $shift_sekarang, 'N', $subtotal
            ]);
        }

        // 3. HITUNG HEADER & KEUANGAN
        $grand_total = ($total_omzet - $diskon_global) + $ongkir;
        if($grand_total < 0) $grand_total = 0;

        // Potong Deposit di Database
        if ($nominal_deposit_dipakai > 0 && $kode_pelanggan != 'UMUM') {
            $pdo->prepare("UPDATE pelanggan SET saldo_deposit = saldo_deposit - ? WHERE kode_pelanggan = ?")
                ->execute([$nominal_deposit_dipakai, $kode_pelanggan]);
            
            $pdo->prepare("INSERT INTO log_deposit (kode_pelanggan, nominal, keterangan, kode_user) VALUES (?, ?, ?, ?)")
                ->execute([$kode_pelanggan, -$nominal_deposit_dipakai, "Bayar Belanja", $kode_user]);
        }

        // Keterangan Header (Bersih tanpa {{PAY:...}})
        $ket_header = "";
        if ($nominal_deposit_dipakai > 0) {
            $ket_header .= "Pakai Depo: " . number_format($nominal_deposit_dipakai, 0, ',', '.') . " ";
        }
        if($ongkir > 0) $ket_header .= "{{ONGKIR:$ongkir}}";

        // Tentukan Lunas/Utang
        $total_uang_masuk_sah = $uang_bayar_belanja + $nominal_deposit_dipakai;
        $pelunasan = 'Y';
        
        if ($metode == 'Utang' || $total_uang_masuk_sah < ($grand_total - 100)) {
            $pelunasan = 'N';
        }

        if ($pelunasan == 'Y') {
            $pdo->prepare("UPDATE penjualan_item SET pelunasan = 'Y' WHERE no_penjualan = ?")->execute([$no_penjualan]);
        } else if ($kode_pelanggan != 'UMUM') {
            $kurang_bayar = $grand_total - $total_uang_masuk_sah;
            if ($kurang_bayar > 0) {
                $pdo->prepare("UPDATE pelanggan SET sisa_utang = sisa_utang + ? WHERE kode_pelanggan = ?")
                    ->execute([$kurang_bayar, $kode_pelanggan]);
            }
        }

        $is_verif_qris = 'N';
        $tgl_verif_qris = null;

        // 4. SIMPAN HEADER PENJUALAN (Dengan Kolom Baru)
        $sqlHead = "INSERT INTO penjualan (
            no_penjualan, kode_pelanggan, kode_user, tgl_penjualan, jam, 
            keterangan, uang_bayar, shift, pelunasan, tgl_transaksi, 
            total_omzet, total_laba, diskon_global, ongkir, nominal_deposit, uang_pelunasan_utang,
            metode_pembayaran, is_verif_qris, tgl_verif_qris
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $pdo->prepare($sqlHead)->execute([
            $no_penjualan, $kode_pelanggan, $kode_user, 
            $tanggal_transaksi, $jam_transaksi, trim($ket_header), $uang_bayar_belanja, $shift_sekarang, $pelunasan, $tanggal_transaksi, 
            $grand_total, $total_laba_nota, $diskon_global, $ongkir, $nominal_deposit_dipakai, $uang_bayar_utang_lama,
            $metode, $is_verif_qris, $tgl_verif_qris
        ]);

        // Simpan file dari Print Analyzer Modal ke order_files dan status order
        if (!empty($analyzerOrderFiles)) {
            $stmtJob = $pdo->prepare("SELECT * FROM print_jobs WHERE id = ?");
            $stmtOrderFile = $pdo->prepare("INSERT INTO order_files (
                no_penjualan, nama_file, path_file, status_baca, jenis_kertas, ukuran_kertas, qty_cetak,
                finishing, halaman, total_halaman, bw_pages, color_25_pages, color_50_pages, color_75_pages,
                color_100_pages, estimasi_harga, analyzer_job_id, page_per_sheet, print_orientation, print_grayscale
            ) VALUES (?, ?, ?, 'selesai', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($analyzerOrderFiles as $jobId => $m) {
                $stmtJob->execute([$jobId]);
                $job = $stmtJob->fetch(PDO::FETCH_ASSOC);
                if (!$job) continue;
                $qtyMeta = max(1, (int)($m['qty_cetak'] ?? 1));
                // Aturan Manajemen Order / Produksi dari Print Analyzer:
                // - Potongan 2 sisi per sheet/lembar = 0  => finishing = 1 sisi
                // - Potongan 2 sisi per sheet/lembar > 0  => finishing = 2 sisi
                // - Jika halaman tidak diisi, simpan sebagai "semua halaman"
                $duplexDiscountPerSheet = isset($m['duplex_discount_per_sheet']) ? (int)$m['duplex_discount_per_sheet'] : -1;
                if ($duplexDiscountPerSheet < 0 && !empty($m['profile_id'])) {
                    try {
                        $stmtProfileSide = $pdo->prepare("SELECT duplex_discount_per_sheet FROM price_profiles WHERE id = ?");
                        $stmtProfileSide->execute([(int)$m['profile_id']]);
                        $duplexDiscountPerSheet = (int)($stmtProfileSide->fetchColumn() ?: 0);
                    } catch (Exception $e) {
                        $duplexDiscountPerSheet = 0;
                    }
                }
                if ($duplexDiscountPerSheet < 0) $duplexDiscountPerSheet = 0;

                $orderFinishing = $duplexDiscountPerSheet > 0 ? '2 sisi' : '1 sisi';
                if (!empty($m['borderless'])) {
                    $orderFinishing .= ' + borderless';
                }
                $orderHalaman = trim((string)($m['halaman'] ?? ''));
                if ($orderHalaman === '') $orderHalaman = 'semua halaman';
                $orderOrientation = strtolower(trim((string)($m['orientation'] ?? 'auto')));
                if (!in_array($orderOrientation, ['auto','portrait','landscape'], true)) $orderOrientation = 'auto';

                $stmtOrderFile->execute([
                    $no_penjualan,
                    $job['original_filename'] ?? ($m['file_name'] ?? ''),
                    'uploads/print_analyzer/uploads/' . ($job['stored_filename'] ?? ($m['stored_filename'] ?? '')),
                    $m['paper_type'] ?? '',
                    $m['paper_size'] ?? '',
                    $qtyMeta,
                    $orderFinishing,
                    $orderHalaman,
                    (int)($job['total_pages'] ?? 0),
                    (int)($job['bw_pages'] ?? 0),
                    (int)($job['color_25_pages'] ?? 0),
                    (int)($job['color_50_pages'] ?? 0),
                    (int)($job['color_75_pages'] ?? 0),
                    (int)($job['color_100_pages'] ?? 0),
                    (float)($m['estimasi_harga'] ?? 0),
                    $jobId,
                    max(1, (int)($m['pages_per_sheet'] ?? 1)),
                    $orderOrientation,
                    !empty($m['print_grayscale']) ? 1 : 0
                ]);
                try { $pdo->prepare("UPDATE print_jobs SET no_penjualan = ? WHERE id = ?")->execute([$no_penjualan, $jobId]); } catch (Exception $e) {}
            }
            $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order, catatan) VALUES (?, 'File Sudah Dicek', ?)")
                ->execute([$no_penjualan, 'Dibuat dari Print Analyzer di halaman kasir']);
            foreach (['Cek file', 'Print file', 'Finishing', 'Hubungi pelanggan'] as $task) {
                $pdo->prepare("INSERT INTO order_tasks (no_penjualan, nama_task, status_task) VALUES (?, ?, 'pending')")
                    ->execute([$no_penjualan, $task]);
            }
        }

        // =========================================================================
        // [PERBAIKAN] INTEGRASI ARUS KAS LENGKAP
        // =========================================================================
        
        // 5. CATAT ARUS KAS UNTUK NOTA INI (Pembayaran Baru)
        if ($uang_bayar_belanja > 0 && strtoupper($metode) != 'UTANG') {
            $sql_arus_baru = "INSERT INTO arus_kas (no_penjualan, metode_pembayaran, tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user) 
                              VALUES (?, ?, ?, 'Pemasukan', ?, ?, 0, ?)";
            $pdo->prepare($sql_arus_baru)->execute([$no_penjualan, $metode, $tanggal_transaksi, "Pembayaran DP/Lunas $no_penjualan", $uang_bayar_belanja, $kode_user]);
        }

        // 6. PEMBAYARAN UTANG LAMA (Jika Ada)
        if ($uang_bayar_utang_lama > 0 && $kode_pelanggan != 'UMUM') {
            $pdo->prepare("UPDATE pelanggan SET sisa_utang = sisa_utang - ? WHERE kode_pelanggan = ?")
                ->execute([$uang_bayar_utang_lama, $kode_pelanggan]);
            
            $stmtHutang = $pdo->prepare("SELECT no_penjualan, total_omzet, uang_bayar FROM penjualan WHERE kode_pelanggan = ? AND pelunasan = 'N' AND no_penjualan != ? ORDER BY tgl_penjualan ASC");
            $stmtHutang->execute([$kode_pelanggan, $no_penjualan]);
            
            $sisa_dana = $uang_bayar_utang_lama;
            $stmtUpdJual = $pdo->prepare("UPDATE penjualan SET uang_bayar = ?, pelunasan = ? WHERE no_penjualan = ?");
            $stmtUpdItem = $pdo->prepare("UPDATE penjualan_item SET pelunasan = 'Y' WHERE no_penjualan = ?");
            
            // Perbaikan Query Arus Kas Utang
            $stmtArusUtang = $pdo->prepare("INSERT INTO arus_kas (no_penjualan, metode_pembayaran, tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user) VALUES (?, ?, ?, 'Pemasukan', ?, ?, 0, ?)");

            while ($rowH = $stmtHutang->fetch()) {
                if ($sisa_dana <= 0) break;
                
                $depo_lama = $pdo->query("SELECT nominal_deposit FROM penjualan WHERE no_penjualan = '{$rowH['no_penjualan']}'")->fetchColumn();
                $tagihan_nota = $rowH['total_omzet'] - $rowH['uang_bayar'] - (float)$depo_lama;
                
                if ($tagihan_nota <= 0) continue;

                $bayar = min($sisa_dana, $tagihan_nota);
                $baru_bayar = $rowH['uang_bayar'] + $bayar;
                
                $is_lunas = (($baru_bayar + (float)$depo_lama) >= ($rowH['total_omzet'] - 100)) ? 'Y' : 'N';

                $stmtUpdJual->execute([$baru_bayar, $is_lunas, $rowH['no_penjualan']]);
                if($is_lunas == 'Y') {
                    $stmtUpdItem->execute([$rowH['no_penjualan']]);
                }
                
                // CATAT PELUNASAN CICILAN KE ARUS KAS
                $stmtArusUtang->execute([$rowH['no_penjualan'], $metode, $tanggal_transaksi, "Pelunasan Cicilan", $bayar, $kode_user]);

                $sisa_dana -= $bayar;
            }
        }

        // 7. SIMPAN KEMBALIAN KE DEPOSIT (Jika Ada)
        if ($nominal_deposit_baru > 0 && $kode_pelanggan != 'UMUM') {
            $pdo->prepare("UPDATE pelanggan SET saldo_deposit = saldo_deposit + ? WHERE kode_pelanggan = ?")->execute([$nominal_deposit_baru, $kode_pelanggan]);
            
            $pdo->prepare("INSERT INTO log_deposit (kode_pelanggan, nominal, keterangan, kode_user) VALUES (?, ?, ?, ?)")->execute([$kode_pelanggan, $nominal_deposit_baru, "Kembalian Nota $no_penjualan", $kode_user]);
            
            // Perbaikan Query Arus Kas Deposit Kembalian
            $pdo->prepare("INSERT INTO arus_kas (no_penjualan, metode_pembayaran, tanggal, jenis, keterangan, jumlah_masuk, jumlah_keluar, kode_user) VALUES (?, ?, ?, 'Pemasukan', ?, ?, 0, ?)")
                ->execute([$no_penjualan, $metode, $tanggal_transaksi, "Deposit Kembalian $no_penjualan", $nominal_deposit_baru, $kode_user]);
        }

        // 8. CATAT ONGKIR (Masuk ke pemasukan_lain)
        // Catatan: Jika uang ongkir sudah ter-cover secara fisik di `$uang_bayar_belanja`, 
        // memasukkannya lagi ke `arus_kas` akan membuat pencatatan kas di kasir ganda (double-entry). 
        // Jadi ini dibiarkan ke 'pemasukan_lain' untuk kebutuhan pelaporan kategori pendapatan.
        if ($ongkir > 0 && $metode != 'Utang') {
            $pdo->prepare("INSERT INTO pemasukan_lain (tgl_pemasukan, kode_pelanggan, kategori, nominal, metode_bayar, keterangan, kode_user) VALUES (?, ?, 'Pendapatan Ongkir', ?, ?, ?, ?)")
                ->execute([$tanggal_transaksi, $kode_pelanggan, $ongkir, $metode, "Ongkir $no_penjualan", $kode_user]);
        }

        $pdo->commit();

        // Ambil nama pelanggan untuk isi notifikasi (bukan syarat transaksi berhasil, jadi dibungkus try/catch sendiri).
        $nama_pelanggan_notif = 'Pelanggan Umum';
        try {
            if (!empty($kode_pelanggan) && strtoupper($kode_pelanggan) !== 'UMUM') {
                $stmtNamaPel = $pdo->prepare("SELECT nama_pelanggan FROM pelanggan WHERE kode_pelanggan = ?");
                $stmtNamaPel->execute([$kode_pelanggan]);
                $nama_pelanggan_notif = $stmtNamaPel->fetchColumn() ?: 'Pelanggan Umum';
            }
        } catch (Throwable $e) {}
        kirim_wa_notifikasi_admin($pdo, $no_penjualan, $nama_pelanggan_notif, $grand_total);

        echo json_encode(['status' => 'success', 'no_penjualan' => $no_penjualan]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>