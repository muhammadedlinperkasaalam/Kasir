<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');


// ================================================================
// PRINT AGENT SUPPORT
// ================================================================
function pa_ensure_tables($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS print_clients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_token VARCHAR(100) NOT NULL UNIQUE,
        client_name VARCHAR(120) NOT NULL,
        ip_address VARCHAR(50) NULL,
        last_seen DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS print_client_printers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        printer_name VARCHAR(255) NOT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        media_types_json TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_client_printer (client_id, printer_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS print_agent_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        order_file_id INT NULL,
        no_penjualan VARCHAR(20) NULL,
        file_url TEXT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        printer_name VARCHAR(255) NOT NULL,
        copies INT NOT NULL DEFAULT 1,
        pages VARCHAR(100) DEFAULT 'semua halaman',
        finishing VARCHAR(50) DEFAULT '1 sisi',
        paper_size VARCHAR(50) NULL,
        fit_to_page TINYINT(1) NOT NULL DEFAULT 1,
        scale_mode VARCHAR(20) NOT NULL DEFAULT 'fit',
        custom_scale INT NOT NULL DEFAULT 100,
        print_part VARCHAR(20) NOT NULL DEFAULT 'all',
        page_per_sheet INT NOT NULL DEFAULT 1,
        orientation VARCHAR(20) NOT NULL DEFAULT 'auto',
        print_grayscale TINYINT(1) NOT NULL DEFAULT 0,
        printer_paper_type VARCHAR(120) NOT NULL DEFAULT 'plain',
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        error_message TEXT NULL,
        page_data TEXT NULL,
        status_message TEXT NULL,
        updated_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->query("SELECT media_types_json FROM print_client_printers LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_client_printers ADD COLUMN media_types_json TEXT NULL AFTER is_active"); }
    try { $pdo->exec("ALTER TABLE print_agent_jobs MODIFY COLUMN printer_paper_type VARCHAR(120) NOT NULL DEFAULT 'plain'"); } catch (Exception $e) {}
    try { $pdo->query("SELECT fit_to_page FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN fit_to_page TINYINT(1) NOT NULL DEFAULT 1 AFTER paper_size"); }
    try { $pdo->query("SELECT scale_mode FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN scale_mode VARCHAR(20) NOT NULL DEFAULT 'fit' AFTER fit_to_page"); }
    try { $pdo->query("SELECT custom_scale FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN custom_scale INT NOT NULL DEFAULT 100 AFTER scale_mode"); }
    try { $pdo->query("SELECT print_part FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN print_part VARCHAR(20) NOT NULL DEFAULT 'all' AFTER custom_scale"); }
    try { $pdo->query("SELECT page_per_sheet FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN page_per_sheet INT NOT NULL DEFAULT 1 AFTER print_part"); }
    try { $pdo->query("SELECT orientation FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN orientation VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER page_per_sheet"); }
    try { $pdo->query("SELECT print_grayscale FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN print_grayscale TINYINT(1) NOT NULL DEFAULT 0 AFTER orientation"); }
    try { $pdo->query("SELECT printer_paper_type FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN printer_paper_type VARCHAR(120) NOT NULL DEFAULT 'plain' AFTER print_grayscale"); }
    try { $pdo->query("SELECT view_token FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN view_token VARCHAR(64) NULL AFTER printer_paper_type"); }
    try { $pdo->query("SELECT created_by_ip FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN created_by_ip VARCHAR(50) NULL AFTER view_token"); }
    try { $pdo->query("SELECT page_data FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN page_data TEXT NULL AFTER error_message"); }
    try { $pdo->query("SELECT status_message FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN status_message TEXT NULL AFTER page_data"); }
    try { $pdo->query("SELECT updated_at FROM print_agent_jobs LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE print_agent_jobs ADD COLUMN updated_at DATETIME NULL AFTER status_message"); }
}
function pa_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // transaksi/order -> root project
    $root = preg_replace('#/transaksi/order$#', '', $script);
    return rtrim($scheme . '://' . $host . $root, '/');
}
function pa_url_encode_path($path) {
    $path = str_replace('\\', '/', (string)$path);
    $parts = array_map('rawurlencode', explode('/', ltrim($path, '/')));
    return implode('/', $parts);
}
function pa_file_rel_path($path_file) {
    $path_file = str_replace('\\', '/', (string)$path_file);
    $path_file = ltrim($path_file, '/');
    // File dari Print Analyzer sudah menyimpan path lengkap dari root project:
    // uploads/print_analyzer/uploads/xxx.pdf
    if (strpos($path_file, 'uploads/') === 0) {
        return $path_file;
    }
    // File upload manual order lama hanya menyimpan nama file, lokasinya uploads/orders/
    return 'uploads/orders/' . $path_file;
}
function pa_file_url($path_file) {
    return pa_base_url() . '/' . pa_url_encode_path(pa_file_rel_path($path_file));
}
function pa_file_fs_path($path_file) {
    return realpath(__DIR__ . '/../../' . pa_file_rel_path($path_file));
}

function pa_count_pages_from_range($halaman): int {
    $halaman = trim((string)$halaman);
    if ($halaman === '' || preg_match('/^(all|semua|semua halaman)$/i', $halaman)) return 0;
    $total = 0;
    $seen = [];
    foreach (preg_split('/\s*,\s*/', $halaman) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/', $part, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2];
            if ($a > $b) { $tmp = $a; $a = $b; $b = $tmp; }
            for ($i=$a; $i<=$b; $i++) { if ($i > 0 && !isset($seen[$i])) { $seen[$i]=1; $total++; } }
        } elseif (preg_match('/^\d+$/', $part)) {
            $i = (int)$part;
            if ($i > 0 && !isset($seen[$i])) { $seen[$i]=1; $total++; }
        }
    }
    return $total;
}

function pa_detect_pdf_page_count($path_file): int {
    $fs = pa_file_fs_path($path_file);
    if (!$fs || !is_file($fs) || !is_readable($fs)) return 0;
    $ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true)) return 1;
    if ($ext !== 'pdf') return 0;
    $data = @file_get_contents($fs);
    if ($data === false || $data === '') return 0;
    // Hitung objek halaman PDF. Regex ini menghindari /Pages agar tidak ikut terhitung.
    if (preg_match_all('/\/Type\s*\/Page\b/', $data, $m)) {
        return (int)count($m[0]);
    }
    return 0;
}

function pa_effective_page_count(array $of): int {
    // Jika operator isi range halaman tertentu (misal 1-5,8), tampilkan jumlah halaman yang akan diprint.
    $rangeCount = pa_count_pages_from_range($of['halaman'] ?? '');
    if ($rangeCount > 0) return $rangeCount;
    $dbCount = (int)($of['total_halaman'] ?? 0);
    if ($dbCount > 0) return $dbCount;
    return pa_detect_pdf_page_count($of['path_file'] ?? '');
}

function pa_detect_page_per_sheet(array $of): int {
    // Jangan menebak dari teks keterangan item seperti "2 hlm dalam 1 sheet",
    // karena teks itu berarti jumlah halaman dalam file, bukan page/sheet.
    // Sumber yang benar adalah kolom order_files.page_per_sheet dari Print Analyzer.
    $pps = (int)($of['page_per_sheet'] ?? 1);
    return in_array($pps, [1,2,4,6,9,16], true) ? $pps : 1;
}
// Cek/CREATE/ALTER tabel print agent cukup sekali per sesi login, bukan di setiap request.
// Kalau memang perlu paksa cek ulang (misal setelah update skema), tinggal logout/login lagi.
if (empty($_SESSION['pa_tables_ready'])) {
    try { pa_ensure_tables($pdo); $_SESSION['pa_tables_ready'] = true; } catch (Exception $e) {}
}

// ================================================================
// SETUP PATH
// ================================================================
$wa_download_dir = realpath(__DIR__ . '/../../whatsapp/wa-engine/downloads'); 
if (!$wa_download_dir) { $wa_download_dir = __DIR__ . '/../../whatsapp/wa-engine/downloads'; }

// ================================================================
// [AUTO FIX] UPDATE STRUKTUR TABEL
// ================================================================
try { $pdo->query("SELECT printed_count FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN printed_count INT DEFAULT 0"); }
try { $pdo->query("SELECT last_print_client_id FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_client_id INT NULL AFTER printed_count"); }
try { $pdo->query("SELECT last_print_client_name FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_client_name VARCHAR(150) NULL AFTER last_print_client_id"); }
try { $pdo->query("SELECT last_print_printer_name FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_printer_name VARCHAR(255) NULL AFTER last_print_client_name"); }
try { $pdo->query("SELECT last_print_job_id FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_job_id INT NULL AFTER last_print_printer_name"); }
try { $pdo->query("SELECT last_print_at FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_at DATETIME NULL AFTER last_print_job_id"); }
try { $pdo->query("SELECT last_print_status FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_status VARCHAR(30) NULL AFTER last_print_at"); }
try { $pdo->query("SELECT last_print_error FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_error TEXT NULL AFTER last_print_status"); }
try { $pdo->query("SELECT last_print_page_data FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_page_data TEXT NULL AFTER last_print_error"); }
try { $pdo->query("SELECT finishing FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN finishing VARCHAR(50) DEFAULT '1 Sisi'"); }
try { $pdo->query("SELECT halaman FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN halaman VARCHAR(100) DEFAULT 'All'"); }
try { $pdo->query("SELECT total_halaman FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN total_halaman INT DEFAULT 0"); }
try { $pdo->query("SELECT page_per_sheet FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN page_per_sheet INT NOT NULL DEFAULT 1"); }
try { $pdo->query("SELECT print_orientation FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN print_orientation VARCHAR(20) NOT NULL DEFAULT 'auto'"); }
try { $pdo->query("SELECT print_grayscale FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN print_grayscale TINYINT(1) NOT NULL DEFAULT 0"); }
try { $pdo->query("SELECT printer_paper_type FROM order_files LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE order_files ADD COLUMN printer_paper_type VARCHAR(120) NOT NULL DEFAULT 'plain' AFTER print_grayscale"); }
// Status tahap print borderless butuh teks lebih panjang dari default lama VARCHAR(20).
// Jika tidak diperbesar, MySQL akan memotong "Proses Print Halaman Genap" sehingga saat reload tampil lagi sebagai Belum Cetak.
try { $pdo->exec("ALTER TABLE order_files MODIFY status_baca VARCHAR(50) DEFAULT 'Belum'"); } catch (Exception $e) {}


// ================================================================
// [OPTIMASI] Index ringan untuk Manajemen Order
// Dibuat otomatis sekali saja agar list order, file, task, dan print client tidak makin berat.
// ================================================================
function mo_ensure_index($pdo, $table, $index, $cols) {
    try {
        $cek = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $cek->execute([$index]);
        if (!$cek->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($cols)");
        }
    } catch (Exception $e) {}
}
try {
    mo_ensure_index($pdo, 'penjualan', 'idx_mo_tgl_jam', '`tgl_penjualan`,`jam`');
    mo_ensure_index($pdo, 'penjualan', 'idx_mo_no_penjualan', '`no_penjualan`');
    mo_ensure_index($pdo, 'order_pekerjaan', 'idx_mo_status_no', '`status_order`,`no_penjualan`');
    mo_ensure_index($pdo, 'order_files', 'idx_mo_file_no', '`no_penjualan`');
    mo_ensure_index($pdo, 'order_tasks', 'idx_mo_task_no_status', '`no_penjualan`,`status_task`');
    mo_ensure_index($pdo, 'print_clients', 'idx_mo_client_seen', '`is_active`,`last_seen`');
    mo_ensure_index($pdo, 'print_client_printers', 'idx_mo_printer_client', '`client_id`,`is_active`');
} catch (Exception $e) {}

// ================================================================
// HANDLE AJAX REQUEST
// ================================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    // MANAGEMEN ORDER: update status order tanpa refresh halaman.
    if ($_POST['action'] == 'update_order_status_ajax') {
        try {
            $no = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['no_penjualan'] ?? ''));
            $status = trim((string)($_POST['status_order'] ?? 'Antri'));
            if (!in_array($status, ['Antri','Proses','Selesai'], true)) { $status = 'Antri'; }
            if ($no === '') throw new Exception('Nomor order tidak valid.');
            $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?");
            $cek->execute([$no]);
            if ($cek->fetch()) {
                $pdo->prepare("UPDATE order_pekerjaan SET status_order = ? WHERE no_penjualan = ?")->execute([$status, $no]);
            } else {
                $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, ?)")->execute([$no, $status]);
            }
            echo json_encode(['status'=>'success','new_order_status'=>$status,'message'=>'Status order diperbarui.']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // MANAGEMEN ORDER: upload/tambah file tanpa refresh halaman penuh.
    if ($_POST['action'] == 'upload_file_ajax') {
        try {
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['no_penjualan'] ?? ''));
            if ($id === '') throw new Exception('Nomor order tidak valid.');
            $j = trim((string)($_POST['jenis_kertas'] ?? ''));
            $u = trim((string)($_POST['ukuran_kertas'] ?? ''));
            $q = max(1, (int)($_POST['qty_cetak'] ?? 1));
            $fin = trim((string)($_POST['finishing'] ?? '1 Sisi'));
            $hal = trim((string)($_POST['halaman'] ?? 'All'));
            if ($hal === '') $hal = 'All';

            $uploadDir = realpath(__DIR__ . '/../../uploads/orders');
            if (!$uploadDir) {
                @mkdir(__DIR__ . '/../../uploads/orders', 0777, true);
                $uploadDir = realpath(__DIR__ . '/../../uploads/orders');
            }
            if (!$uploadDir || !is_dir($uploadDir) || !is_writable($uploadDir)) {
                throw new Exception('Folder uploads/orders tidak bisa ditulis.');
            }

            $sql_insert = "INSERT INTO order_files (no_penjualan, nama_file, path_file, jenis_kertas, ukuran_kertas, qty_cetak, printed_count, finishing, halaman) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)";
            $inserted = 0;

            if (!empty($_POST['wa_file'])) {
                $wa_files = is_array($_POST['wa_file']) ? $_POST['wa_file'] : [$_POST['wa_file']];
                foreach ($wa_files as $file_names) {
                    $file_names = basename((string)$file_names);
                    if ($file_names === '') continue;
                    $src = rtrim($wa_download_dir, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $file_names;
                    if (is_file($src)) {
                        usleep(100000);
                        $safe = preg_replace("/[^a-zA-Z0-9._-]/", "_", $file_names);
                        $new = time() . "_" . mt_rand(100,999) . "_" . $safe;
                        if (@rename($src, $uploadDir . DIRECTORY_SEPARATOR . $new)) {
                            $stmt = $pdo->prepare($sql_insert);
                            $stmt->execute([$id, $file_names, $new, $j, $u, $q, $fin, $hal]);
                            $inserted++;
                        }
                    }
                }
            }

            if (isset($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
                for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
                    if (empty($_FILES['files']['name'][$i])) continue;
                    if (!isset($_FILES['files']['tmp_name'][$i]) || !is_uploaded_file($_FILES['files']['tmp_name'][$i])) continue;
                    $orig = (string)$_FILES['files']['name'][$i];
                    $safe = preg_replace("/[^a-zA-Z0-9._-]/", "_", $orig);
                    $new = time() . "_" . mt_rand(100,999) . "_" . $safe;
                    if (@move_uploaded_file($_FILES['files']['tmp_name'][$i], $uploadDir . DIRECTORY_SEPARATOR . $new)) {
                        $pdo->prepare($sql_insert)->execute([$id, $orig, $new, $j, $u, $q, $fin, $hal]);
                        $inserted++;
                    }
                }
            }

            if ($inserted <= 0) throw new Exception('Tidak ada file yang berhasil diupload/dipindahkan.');
            echo json_encode(['status'=>'success','count'=>$inserted,'message'=>$inserted.' file berhasil ditambahkan.']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // MANAGEMEN ORDER: refresh ringan status saja.
    // Sebelumnya halaman melakukan .load() penuh setiap 10 detik sehingga query besar,
    // scan file WhatsApp, Select2, dan render HTML jalan berulang-ulang.
    if ($_POST['action'] == 'order_light_refresh') {
        try {
            $notes = $_POST['notes'] ?? [];
            if (!is_array($notes)) $notes = [];
            $notes = array_values(array_unique(array_filter(array_map(function($n){
                return preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$n);
            }, $notes))));
            $notes = array_slice($notes, 0, 250);

            $orders = [];
            foreach ($notes as $n) $orders[$n] = 'Antri';
            if (!empty($notes)) {
                $ph = implode(',', array_fill(0, count($notes), '?'));
                $st = $pdo->prepare("SELECT no_penjualan, status_order FROM order_pekerjaan WHERE no_penjualan IN ($ph)");
                $st->execute($notes);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $orders[$r['no_penjualan']] = $r['status_order'] ?: 'Antri';
                }
            }

            $selected = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['selected_no'] ?? ''));
            $filesLite = [];
            $progress = null;
            if ($selected !== '') {
                $fs = $pdo->prepare("SELECT id, path_file, status_baca, printed_count, qty_cetak FROM order_files WHERE no_penjualan=?");
                $fs->execute([$selected]);
                foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $cur = (int)($f['printed_count'] ?? 0);
                    $target = max(1, (int)($f['qty_cetak'] ?? 1));
                    $statusText = trim((string)($f['status_baca'] ?? ''));
                    if (strcasecmp($statusText, 'Sudah') === 0) {
                        $html = '<span class="badge bg-success rounded-pill" style="font-size:0.7rem;">Selesai ('.$cur.'/'.$target.')</span>';
                    } elseif (stripos($statusText, 'Genap') !== false) {
                        $html = '<span class="badge bg-info text-dark rounded-pill" style="font-size:0.7rem;">Proses print halaman genap</span>';
                    } elseif (stripos($statusText, 'Ganjil') !== false) {
                        $html = '<span class="badge bg-info text-dark rounded-pill" style="font-size:0.7rem;">Proses print halaman ganjil</span>';
                    } elseif ($cur > 0) {
                        $html = '<span class="badge bg-warning text-dark rounded-pill" style="font-size:0.7rem;">Diprint ('.$cur.'/'.$target.')</span>';
                    } else {
                        $html = '<span class="badge bg-light text-secondary border rounded-pill" style="font-size:0.7rem;">Belum Cetak</span>';
                    }
                    $filesLite[] = ['safe_id'=>md5($f['path_file']), 'html'=>$html, 'status'=>$statusText, 'current'=>$cur, 'target'=>$target];
                }

                $t = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status_task='Selesai' THEN 1 ELSE 0 END) selesai FROM order_tasks WHERE no_penjualan=?");
                $t->execute([$selected]); $dt = $t->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
                $f = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status_baca='Sudah' THEN 1 ELSE 0 END) selesai FROM order_files WHERE no_penjualan=?");
                $f->execute([$selected]); $df = $f->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
                $grandTot = (int)$dt['total'] + (int)$df['total'];
                $grandDone = (int)$dt['selesai'] + (int)$df['selesai'];
                $progress = [
                    'persen' => $grandTot > 0 ? round(($grandDone / $grandTot) * 100) : 0,
                    'unfinished_files' => max(0, (int)$df['total'] - (int)$df['selesai']),
                    'unfinished_tasks' => max(0, (int)$dt['total'] - (int)$dt['selesai'])
                ];
            }

            echo json_encode(['status'=>'success', 'orders'=>$orders, 'files'=>$filesLite, 'progress'=>$progress]);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
        }
        exit;
    }


    // PRINT AGENT: daftar client/printer aktif
    if ($_POST['action'] == 'print_agent_list') {
        try {
            pa_ensure_tables($pdo);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $stmt = $pdo->query("SELECT c.*
                                 FROM print_clients c
                                 WHERE c.is_active = 1 AND c.last_seen >= (NOW() - INTERVAL 2 MINUTE)
                                 ORDER BY (c.ip_address = " . $pdo->quote($ip) . ") DESC, c.client_name ASC");
            $printerStmt = $pdo->prepare("SELECT printer_name, is_default, media_types_json
                                          FROM print_client_printers
                                          WHERE client_id=? AND is_active=1
                                          ORDER BY is_default DESC, printer_name ASC");
            $clients = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $printers = [];
                $printerStmt->execute([(int)$row['id']]);
                foreach ($printerStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $media = [];
                    if (!empty($pr['media_types_json'])) {
                        $tmp = json_decode((string)$pr['media_types_json'], true);
                        if (is_array($tmp)) $media = $tmp;
                    }
                    if (!$media) {
                        $media = [
                            ['value'=>'plain','label'=>'Plain Paper'],
                            ['value'=>'photo_glossy','label'=>'Photo Paper Glossy']
                        ];
                    }
                    $printers[] = [
                        'name' => $pr['printer_name'],
                        'is_default' => (int)$pr['is_default'] === 1,
                        'paper_types' => $media
                    ];
                }
                $clients[] = [
                    'id' => (int)$row['id'],
                    'client_name' => $row['client_name'],
                    'ip_address' => $row['ip_address'],
                    'is_current_ip' => ($row['ip_address'] === $ip),
                    'printers' => $printers
                ];
            }
            echo json_encode(['status'=>'success','current_ip'=>$ip,'clients'=>$clients]);
        } catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
        exit;
    }

    // PRINT AGENT: kirim job print ke client tertentu
    if ($_POST['action'] == 'print_agent_enqueue') {
        try {
            pa_ensure_tables($pdo);
            $file_id = (int)($_POST['file_id'] ?? 0);
            $client_id = (int)($_POST['client_id'] ?? 0);
            $printer_name = trim($_POST['printer_name'] ?? '');
            $fit_to_page = !empty($_POST['fit_to_page']) ? 1 : 0;
            $scale_mode = strtolower(trim($_POST['scale_mode'] ?? ($fit_to_page ? 'fit' : 'noscale')));
            if (!in_array($scale_mode, ['fit','noscale','custom'], true)) { $scale_mode = $fit_to_page ? 'fit' : 'noscale'; }
            $custom_scale = (int)($_POST['custom_scale'] ?? 100);
            if ($custom_scale < 10) $custom_scale = 10;
            if ($custom_scale > 400) $custom_scale = 400;
            if ($scale_mode === 'custom') { $fit_to_page = 0; }
            $print_part = strtolower(trim($_POST['print_part'] ?? 'all'));
            if (!in_array($print_part, ['all','even','odd'], true)) { $print_part = 'all'; }
            if ($file_id <= 0 || $client_id <= 0 || $printer_name === '') {
                throw new Exception('Client/printer/file belum dipilih.');
            }
            $stmt = $pdo->prepare("SELECT * FROM order_files WHERE id = ? LIMIT 1");
            $stmt->execute([$file_id]);
            $of = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$of) throw new Exception('File order tidak ditemukan.');
            $c = $pdo->prepare("SELECT id, client_name FROM print_clients WHERE id = ? AND is_active = 1 LIMIT 1");
            $c->execute([$client_id]);
            $clientRow = $c->fetch(PDO::FETCH_ASSOC);
            if (!$clientRow) throw new Exception('Client print tidak aktif.');
            $clientName = trim((string)($clientRow['client_name'] ?? 'Client'));
            $target_copies = max(1, (int)($of['qty_cetak'] ?? 1));
            $printed_count = max(0, (int)($of['printed_count'] ?? 0));
            $remaining_copies = max(1, $target_copies - $printed_count);
            $copies_mode = strtolower(trim($_POST['copies_mode'] ?? 'all_remaining'));
            if (!in_array($copies_mode, ['all_remaining','single'], true)) { $copies_mode = 'all_remaining'; }
            // Jika qty cetak lebih dari 1, operator bisa memilih print satuan.
            // all_remaining = cetak semua sisa qty, single = cetak 1 lembar/copy saja.
            $copies = ($copies_mode === 'single') ? 1 : $remaining_copies;
            $pages = trim($of['halaman'] ?? 'semua halaman');
            if ($pages === '' || strtolower($pages) === 'all') $pages = 'semua halaman';
            $finishing = trim($of['finishing'] ?? '1 sisi');
            $finishing_lc = strtolower($finishing);
            if (!(strpos($finishing_lc, '2') !== false && strpos($finishing_lc, 'borderless') !== false)) {
                $print_part = 'all';
            }
            $paper = strtoupper(trim((string)($_POST['paper_size'] ?? ($of['ukuran_kertas'] ?? ''))));
            $paper = preg_replace('/[^A-Z0-9+ _.-]/', '', $paper);
            $paper = substr($paper ?: trim($of['ukuran_kertas'] ?? ''), 0, 50);
            $page_per_sheet = pa_detect_page_per_sheet($of);
            $orientation = strtolower(trim((string)($_POST['orientation'] ?? ($of['print_orientation'] ?? 'auto'))));
            if (!in_array($orientation, ['auto','portrait','landscape'], true)) $orientation = 'auto';
            $print_grayscale = !empty($_POST['print_grayscale']) ? 1 : (int)($of['print_grayscale'] ?? 0);
            $print_grayscale = $print_grayscale ? 1 : 0;
            $printer_paper_type = trim((string)($_POST['printer_paper_type'] ?? ($of['printer_paper_type'] ?? 'plain')));
            $printer_paper_type = preg_replace('/[^a-zA-Z0-9 _\-.\/()+]/', '', $printer_paper_type);
            $printer_paper_type = substr($printer_paper_type ?: 'plain', 0, 120);
            $fileName = $of['nama_file'] ?: $of['path_file'];
            $viewToken = bin2hex(random_bytes(16));
            $createdByIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $partLabel = $print_part === 'even' ? 'halaman genap' : ($print_part === 'odd' ? 'halaman ganjil' : 'semua halaman');
            $initialPageData = trim($partLabel . ' | ' . $pages . ' | ' . $page_per_sheet . ' page/sheet | copy ' . $copies . ' | kertas ' . ($paper ?: '-'));
            $statusMessage = 'Job dibuat di server dan menunggu komputer client mengambil job.';
            $ins = $pdo->prepare("INSERT INTO print_agent_jobs (client_id, order_file_id, no_penjualan, file_url, file_name, printer_name, copies, pages, finishing, paper_size, fit_to_page, scale_mode, custom_scale, print_part, page_per_sheet, orientation, print_grayscale, printer_paper_type, view_token, created_by_ip, page_data, status_message, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $ins->execute([$client_id, $file_id, $of['no_penjualan'], pa_file_url($of['path_file']), $fileName, $printer_name, $copies, $pages, $finishing, $paper, $fit_to_page, $scale_mode, $custom_scale, $print_part, $page_per_sheet, $orientation, $print_grayscale, $printer_paper_type, $viewToken, $createdByIp, $initialPageData, $statusMessage]);
            $jobId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE order_files SET last_print_client_id=?, last_print_client_name=?, last_print_printer_name=?, last_print_job_id=?, last_print_at=NOW(), last_print_status='pending', last_print_error=NULL, last_print_page_data=? WHERE id=?")
                ->execute([$client_id, $clientName, $printer_name, $jobId, $initialPageData, $file_id]);
            echo json_encode(['status'=>'success','message'=>'Job print dikirim ke agent.','job_id'=>$jobId,'client_id'=>$client_id,'client_name'=>$clientName,'printer_name'=>$printer_name,'paper_size'=>$paper,'page_data'=>$initialPageData]);
        } catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
        exit;
    }

    // PRINT AGENT: cek status job dari komputer client.
    // Dipakai server/manajemen order untuk menunggu hasil print benar-benar selesai/gagal di client.
    if ($_POST['action'] == 'print_agent_job_status') {
        try {
            pa_ensure_tables($pdo);
            $job_id = (int)($_POST['job_id'] ?? 0);
            if ($job_id <= 0) throw new Exception('Job print tidak valid.');
            $stmt = $pdo->prepare("SELECT j.*, c.client_name, c.ip_address,
                                          of.printed_count, of.qty_cetak, of.status_baca,
                                          of.last_print_status, of.last_print_error, of.last_print_page_data
                                   FROM print_agent_jobs j
                                   LEFT JOIN print_clients c ON c.id = j.client_id
                                   LEFT JOIN order_files of ON of.id = j.order_file_id
                                   WHERE j.id = ? LIMIT 1");
            $stmt->execute([$job_id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) throw new Exception('Job print tidak ditemukan.');
            $status = strtolower((string)($job['status'] ?? 'pending'));
            $pct = 0; $orderStatus = '';
            $np = trim((string)($job['no_penjualan'] ?? ''));
            if ($np !== '') {
                try {
                    $t = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_task = 'Selesai' THEN 1 ELSE 0 END) as selesai FROM order_tasks WHERE no_penjualan = ?");
                    $t->execute([$np]); $dt = $t->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
                    $f = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_baca = 'Sudah' THEN 1 ELSE 0 END) as selesai FROM order_files WHERE no_penjualan = ?");
                    $f->execute([$np]); $df = $f->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
                    $tot = (int)($dt['total'] ?? 0) + (int)($df['total'] ?? 0);
                    $done = (int)($dt['selesai'] ?? 0) + (int)($df['selesai'] ?? 0);
                    $pct = ($tot > 0) ? (int)round(($done / $tot) * 100) : 0;
                    $orderStatus = ($pct >= 100) ? 'Selesai' : 'Proses';
                } catch (Exception $x) {}
            }
            $map = [
                'pending' => 'Menunggu komputer client mengambil job',
                'processing' => 'Sedang diproses di komputer client',
                'done' => 'Print berhasil di komputer client',
                'failed' => 'Print gagal di komputer client'
            ];
            echo json_encode([
                'status' => 'success',
                'job_status' => $status,
                'job_status_label' => $map[$status] ?? $status,
                'job_id' => (int)$job['id'],
                'client_id' => (int)$job['client_id'],
                'client_name' => $job['client_name'] ?? '',
                'client_ip' => $job['ip_address'] ?? '',
                'printer_name' => $job['printer_name'] ?? '',
                'page_data' => $job['page_data'] ?? '',
                'status_message' => $job['status_message'] ?? '',
                'error_message' => $job['error_message'] ?? '',
                'started_at' => $job['started_at'] ?? '',
                'finished_at' => $job['finished_at'] ?? '',
                'updated_at' => $job['updated_at'] ?? '',
                'current' => (int)($job['printed_count'] ?? 0),
                'target' => max(1, (int)($job['qty_cetak'] ?? 1)),
                'file_status' => $job['status_baca'] ?? '',
                'last_print_status' => $job['last_print_status'] ?? '',
                'last_print_error' => $job['last_print_error'] ?? '',
                'last_print_page_data' => $job['last_print_page_data'] ?? '',
                'persen' => $pct,
                'new_order_status' => $orderStatus
            ]);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // UPDATE KETERANGAN TAHAP PRINT BORDERLESS 2 SISI
    // Dipakai saat operator klik Print Genap agar kolom status file langsung memberi keterangan proses.
    if ($_POST['action'] == 'update_file_print_phase') {
        $path_file = $_POST['path_file'] ?? '';
        $no_penjualan = $_POST['no_penjualan'] ?? '';
        $phase = strtolower(trim($_POST['phase'] ?? ''));
        try {
            $phase_status = '';
            if ($phase === 'even') {
                $phase_status = 'Proses Print Halaman Genap';
            } elseif ($phase === 'odd') {
                $phase_status = 'Proses Print Halaman Ganjil';
            }
            if ($path_file === '' || $no_penjualan === '' || $phase_status === '') {
                throw new Exception('Data tahap print tidak lengkap.');
            }
            $stmt = $pdo->prepare("UPDATE order_files SET status_baca = ? WHERE path_file = ? AND no_penjualan = ?");
            $stmt->execute([$phase_status, $path_file, $no_penjualan]);

            $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?");
            $cek->execute([$no_penjualan]);
            if ($cek->rowCount() > 0) {
                $pdo->prepare("UPDATE order_pekerjaan SET status_order = 'Proses' WHERE no_penjualan = ?")->execute([$no_penjualan]);
            } else {
                $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, 'Proses')")->execute([$no_penjualan]);
            }

            echo json_encode(['status'=>'success','file_status'=>$phase_status,'new_order_status'=>'Proses']);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // 1A. DOWNLOAD DIANGGAP TERPRINT SEMENTARA
    if ($_POST['action'] == 'download_mark_printed') {
        $file_id = (int)($_POST['file_id'] ?? 0);
        $no_penjualan = trim((string)($_POST['no_penjualan'] ?? ''));
        try {
            $stmtGet = $pdo->prepare("SELECT id, no_penjualan, qty_cetak FROM order_files WHERE id=? AND no_penjualan=? LIMIT 1");
            $stmtGet->execute([$file_id, $no_penjualan]);
            $fData = $stmtGet->fetch(PDO::FETCH_ASSOC);
            if (!$fData) throw new Exception('File order tidak ditemukan.');
            $target = max(1, (int)($fData['qty_cetak'] ?? 1));
            $pdo->prepare("UPDATE order_files
                           SET printed_count=?, status_baca='Sudah',
                               last_print_client_id=NULL,
                               last_print_client_name='Download Manual',
                               last_print_printer_name='Download',
                               last_print_job_id=NULL,
                               last_print_at=NOW(),
                               last_print_status='done',
                               last_print_error=NULL,
                               last_print_page_data='Download manual dari server'
                           WHERE id=? AND no_penjualan=?")
                ->execute([$target, $file_id, $no_penjualan]);

            $t = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_task = 'Selesai' THEN 1 ELSE 0 END) as selesai FROM order_tasks WHERE no_penjualan = ?");
            $t->execute([$no_penjualan]);
            $dt = $t->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
            $f = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_baca = 'Sudah' THEN 1 ELSE 0 END) as selesai FROM order_files WHERE no_penjualan = ?");
            $f->execute([$no_penjualan]);
            $df = $f->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
            $tot = (int)$dt['total'] + (int)$df['total'];
            $done = (int)$dt['selesai'] + (int)$df['selesai'];
            $pct = ($tot > 0) ? round(($done / $tot) * 100) : 0;
            $new_st_order = ($pct >= 100) ? 'Selesai' : 'Proses';
            $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?");
            $cek->execute([$no_penjualan]);
            if ($cek->rowCount() > 0) $pdo->prepare("UPDATE order_pekerjaan SET status_order = ? WHERE no_penjualan = ?")->execute([$new_st_order, $no_penjualan]);
            else $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, ?)")->execute([$no_penjualan, $new_st_order]);

            echo json_encode(['status'=>'success','persen'=>$pct,'new_order_status'=>$new_st_order,'target'=>$target,'current'=>$target,'is_done'=>true]);
        } catch (Exception $e) {
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // 1. UPDATE STATUS FILE
    if ($_POST['action'] == 'update_file_status') {
        $path_file = $_POST['path_file']; $no_penjualan = $_POST['no_penjualan'];
        $print_increment = max(1, (int)($_POST['print_increment'] ?? 1));
        $print_client_id = (int)($_POST['print_client_id'] ?? 0);
        $print_client_name = trim((string)($_POST['print_client_name'] ?? ''));
        $print_printer_name = trim((string)($_POST['print_printer_name'] ?? ''));
        $print_job_id = (int)($_POST['print_job_id'] ?? 0);
        try {
            $stmtGet = $pdo->prepare("SELECT id, qty_cetak, printed_count FROM order_files WHERE path_file = ? AND no_penjualan = ?"); 
            $stmtGet->execute([$path_file, $no_penjualan]); 
            $fData = $stmtGet->fetch();
            
            if ($fData) {
                $target = max(1, (int)$fData['qty_cetak']);
                $curr = min($target, (int)$fData['printed_count'] + $print_increment);
                $st = ($curr >= $target) ? 'Sudah' : 'Belum';
                if ($print_client_name === '' && $print_client_id > 0) {
                    $cn = $pdo->prepare("SELECT client_name FROM print_clients WHERE id=? LIMIT 1");
                    $cn->execute([$print_client_id]);
                    $print_client_name = trim((string)$cn->fetchColumn());
                }
                $pdo->prepare("UPDATE order_files SET printed_count = ?, status_baca = ?, last_print_client_id = NULLIF(?,0), last_print_client_name = NULLIF(?,''), last_print_printer_name = NULLIF(?,''), last_print_job_id = NULLIF(?,0), last_print_at = NOW(), last_print_status='done', last_print_error=NULL WHERE path_file = ? AND no_penjualan = ?")->execute([$curr, $st, $print_client_id, $print_client_name, $print_printer_name, $print_job_id, $path_file, $no_penjualan]);
                
                // Kalkulasi Progress Bar
                $t = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_task = 'Selesai' THEN 1 ELSE 0 END) as selesai FROM order_tasks WHERE no_penjualan = ?"); $t->execute([$no_penjualan]); $dt = $t->fetch();
                $f = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_baca = 'Sudah' THEN 1 ELSE 0 END) as selesai FROM order_files WHERE no_penjualan = ?"); $f->execute([$no_penjualan]); $df = $f->fetch();
                $tot = $dt['total'] + $df['total']; $done = $dt['selesai'] + $df['selesai'];
                $pct = ($tot > 0) ? round(($done / $tot) * 100) : 0;
                
                $new_st_order = ($pct >= 100) ? 'Selesai' : 'Proses';
                $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?"); $cek->execute([$no_penjualan]);
                if ($cek->rowCount() > 0) {
                    $pdo->prepare("UPDATE order_pekerjaan SET status_order = ? WHERE no_penjualan = ?")->execute([$new_st_order, $no_penjualan]);
                } else {
                    $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, ?)")->execute([$no_penjualan, $new_st_order]);
                }

                echo json_encode(['status' => 'success', 'persen' => $pct, 'current' => $curr, 'target' => $target, 'is_done' => ($st == 'Sudah'), 'new_order_status' => $new_st_order]);
            } else { echo json_encode(['status' => 'error']); }
        } catch (Exception $e) { echo json_encode(['status' => 'error']); } exit;
    }

    // 2. UPDATE STATUS TASK
    if ($_POST['action'] == 'update_task_status') {
        $id = $_POST['id']; $st = $_POST['status']; $nota = $_POST['no_penjualan'];
        try {
            $pdo->prepare("UPDATE order_tasks SET status_task = ? WHERE id = ?")->execute([$st, $id]);
            
            $t = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_task = 'Selesai' THEN 1 ELSE 0 END) as selesai FROM order_tasks WHERE no_penjualan = ?"); $t->execute([$nota]); $dt = $t->fetch();
            $f = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_baca = 'Sudah' THEN 1 ELSE 0 END) as selesai FROM order_files WHERE no_penjualan = ?"); $f->execute([$nota]); $df = $f->fetch();
            $tot = $dt['total'] + $df['total']; $done = $dt['selesai'] + $df['selesai'];
            $pct = ($tot > 0) ? round(($done / $tot) * 100) : 0;

            $new_st_order = ($pct >= 100) ? 'Selesai' : 'Proses';
            $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?"); $cek->execute([$nota]);
            if ($cek->rowCount() > 0) {
                $pdo->prepare("UPDATE order_pekerjaan SET status_order = ? WHERE no_penjualan = ?")->execute([$new_st_order, $nota]);
            } else {
                $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, ?)")->execute([$nota, $new_st_order]);
            }

            echo json_encode(['status' => 'success', 'persen' => $pct, 'new_order_status' => $new_st_order]);
        } catch (Exception $e) { echo json_encode(['status' => 'error']); } exit;
    }

    // 3. KIRIM WA
    if ($_POST['action'] == 'kirim_wa_ajax') { echo json_encode(kirim_wa_order($pdo, $_POST['id'], $_POST['status'])); exit; }
}

function kirim_wa_order($pdo, $no, $st) {
    $q = $pdo->prepare("SELECT pl.nama_pelanggan, pl.no_telepon, pl.wa_lid FROM penjualan p JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan WHERE p.no_penjualan = ?"); 
    $q->execute([$no]); 
    $d = $q->fetch();
    if (!$d || strtolower(trim($d['nama_pelanggan'])) == 'umum') return ['status' => 'error', 'message' => 'Pelanggan UMUM/Tidak ada data.'];
    
    $hp = preg_replace('/[^0-9]/', '', $d['no_telepon']??''); 
    $lid = preg_replace('/[^0-9]/', '', $d['wa_lid']??'');
    $trg = (!empty($hp)) ? ((substr($hp,0,1)=='0'?'62'.substr($hp,1):$hp)) : ((!empty($lid)) ? $lid.'@lid' : '');
    if (!$trg) return ['status' => 'error', 'message' => 'Kontak kosong.'];
    
    $sql_antrian = "SELECT COUNT(DISTINCT no_penjualan) FROM (
                        SELECT p.no_penjualan FROM penjualan p LEFT JOIN order_pekerjaan op ON p.no_penjualan = op.no_penjualan WHERE p.tgl_penjualan >= CURDATE() AND op.status_order IS NULL
                        UNION
                        SELECT no_penjualan FROM order_pekerjaan WHERE status_order = 'Antri'
                    ) as antrian_aktif";
    $jml_antrian = $pdo->query($sql_antrian)->fetchColumn();
    
    $keterangan_tambahan = "";
    if (strtolower($st) == 'selesai') {
        $keterangan_tambahan = "\n\nYeay! *Pesanan Anda sudah selesai* dan sudah bisa diambil ya Kak.";
    } else if (strtolower($st) == 'proses') {
        $keterangan_tambahan = "\n\nPesanan Anda sedang kami kerjakan (Terdapat *" . $jml_antrian . "* orderan di antrian). Mohon ditunggu ya Kak.";
    } else {
        $keterangan_tambahan = "\n\nPesanan Anda masuk dalam daftar tunggu (Total antrian saat ini: *" . $jml_antrian . "* orderan).";
    }

    $msg = "Halo Kak *" . trim($d['nama_pelanggan']) . "*,\n\nUpdate Nota: *" . $no . "*\nStatus: *" . strtoupper($st) . "*" . $keterangan_tambahan . "\n\nTerima kasih dari Addinta Printing!";

    $set = $pdo->query("SELECT base_url, session_name FROM waha_setting LIMIT 1")->fetch();
    $base = rtrim($set['base_url'] ?? 'http://localhost:8000', '/');
    // base_url di tabel waha_setting disimpan TANPA "/api" (mis. http://localhost:8000),
    // sedangkan endpoint asli WA Gateway ada di /api/send-message. Pastikan /api selalu ada,
    // supaya tidak dobel kalau suatu saat base_url disimpan dengan /api juga.
    if (!preg_match('#/api$#', $base)) { $base .= '/api'; }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $base . '/send-message',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['session_id' => $set['session_name']??'default', 'phone' => $trg, 'message' => $msg]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 'error', 'message' => 'Tidak bisa menghubungi WA Gateway: ' . ($curl_err ?: 'unknown error')];
    }

    $res = json_decode($raw, true);
    if (!is_array($res)) {
        return ['status' => 'error', 'message' => 'Respon WA Gateway tidak valid (HTTP ' . $http_code . ').'];
    }
    // Respon sukses dari wa-gateway: {"status":true,"message":"Terkirim","data":...}
    // Respon gagal (termasuk endpoint tidak ditemukan / error dari Node engine): {"error":true,"message":"..."}
    // atau {"status":false,"message":"..."}.
    $is_success = ($res['status'] ?? false) === true || ($res['status'] ?? '') === 'success';
    if ($is_success) {
        return ['status' => 'success', 'message' => 'Terkirim!'];
    }
    $err_message = $res['message'] ?? ('Gagal kirim (HTTP ' . $http_code . ').');
    return ['status' => 'error', 'message' => $err_message];
}

// ================================================================
// HANDLE POST (UPLOAD & DELETE)
// ================================================================
$filter_periode = $_GET['periode'] ?? 'hari_ini'; $keyword = $_GET['cari'] ?? '';
$url_params = "&periode=" . $filter_periode . "&cari=" . urlencode($keyword);

if (isset($_POST['upload_file']) && isset($_GET['id'])) {
    $id = $_GET['id']; 
    $j = $_POST['jenis_kertas']??''; 
    $u = $_POST['ukuran_kertas']??''; 
    $q = max(1, (int)($_POST['qty_cetak']??1));
    $fin = $_POST['finishing'] ?? '1 Sisi'; 
    $hal = !empty($_POST['halaman']) ? $_POST['halaman'] : 'All'; 

    // PISAHKAN SQL QUERY AGAR LEBIH AMAN DARI ERROR SYNTAX / KARAKTER TERSEMBUNYI
    $sql_insert = "INSERT INTO order_files (no_penjualan, nama_file, path_file, jenis_kertas, ukuran_kertas, qty_cetak, printed_count, finishing, halaman) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)";

    if (!empty($_POST['wa_file'])) {
        $wa_files = is_array($_POST['wa_file']) ? $_POST['wa_file'] : [$_POST['wa_file']];

        foreach ($wa_files as $file_names) {
            $src = $wa_download_dir . '/' . $file_names;
            if (file_exists($src)) {
                usleep(100000);
                $new = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "_", $file_names);
                if (rename($src, '../../uploads/orders/' . $new)) {
                    $stmt = $pdo->prepare($sql_insert);
                    $stmt->execute([$id, $file_name, $new, $j, $u, $q, $fin, $hal]);
                }
            }
        }
    }
    if (isset($_FILES['files']['name'][0])) {
        for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
            if (!empty($_FILES['files']['name'][$i])) {
                $new = time() . "_" . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES['files']['name'][$i]);
                if (move_uploaded_file($_FILES['files']['tmp_name'][$i], '../../uploads/orders/' . $new)) {
                    $pdo->prepare("INSERT INTO order_files (no_penjualan, nama_file, path_file, jenis_kertas, ukuran_kertas, qty_cetak, printed_count, finishing, halaman) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")->execute([$id, $_FILES['files']['name'][$i], $new, $j, $u, $q, $fin, $hal]);
                }
            }
        }
    }
    header("Location: ?id=$id$url_params"); exit;
}

if (isset($_GET['del_file'])) { 
    $q = $pdo->prepare("SELECT path_file FROM order_files WHERE path_file = ?"); $q->execute([$_GET['del_file']]); $f = $q->fetch();
    $fsPath = $f ? pa_file_fs_path($f['path_file']) : false;
    if($fsPath && file_exists($fsPath)) unlink($fsPath);
    $pdo->prepare("DELETE FROM order_files WHERE path_file = ?")->execute([$_GET['del_file']]); header("Location: ?id=".$_GET['id'].$url_params); exit; 
}

if (isset($_POST['update_status_order'])) { 
    $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?"); $cek->execute([$_GET['id']]); 
    if ($cek->rowCount() > 0) $pdo->prepare("UPDATE order_pekerjaan SET status_order = ? WHERE no_penjualan = ?")->execute([$_POST['status_order'], $_GET['id']]); 
    else $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, ?)")->execute([$_GET['id'], $_POST['status_order']]); 
    header("Location: ?id=".$_GET['id'].$url_params); exit; 
}

// ================================================================
// LOAD DATA
// ================================================================
$ta = date('Y-m-d'); $tk = date('Y-m-d');
if($filter_periode=='kemarin') $ta=$tk=date('Y-m-d', strtotime('-1 days')); elseif($filter_periode=='7_hari') $ta=date('Y-m-d', strtotime('-7 days')); elseif($filter_periode=='bulan_ini') $ta=date('Y-m-01'); elseif($filter_periode=='semua') $ta='2000-01-01';
$ta_db = $ta." 00:00:00"; $tk_db = $tk." 23:59:59";
$wh = ""; $qp = [];
if ($filter_periode !== 'semua') { $wh .= " AND p.tgl_penjualan >= ? AND p.tgl_penjualan <= ?"; $qp[] = $ta_db; $qp[] = $tk_db; }
if (!empty($keyword)) { $wh .= " AND (p.no_penjualan LIKE ? OR pl.nama_pelanggan LIKE ?)"; $qp[] = "%$keyword%"; $qp[] = "%$keyword%"; }

$sql = "SELECT * FROM (SELECT p.no_penjualan, p.tgl_penjualan, p.jam, pl.nama_pelanggan, COALESCE(op.status_order, 'Antri') as status_order FROM penjualan p LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan LEFT JOIN order_pekerjaan op ON p.no_penjualan = op.no_penjualan WHERE 1=1 $wh UNION SELECT p.no_penjualan, p.tgl_penjualan, p.jam, pl.nama_pelanggan, op.status_order FROM order_pekerjaan op JOIN penjualan p ON op.no_penjualan = p.no_penjualan LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan WHERE LOWER(TRIM(REPLACE(op.status_order, '_', ' '))) IN ('file sudah dicek', 'file dicek', 'sudah dicek', 'antri', 'proses')) AS gabungan GROUP BY no_penjualan ORDER BY
    CASE
        WHEN LOWER(TRIM(REPLACE(status_order, '_', ' '))) IN ('file sudah dicek', 'file dicek', 'sudah dicek') THEN 0
        WHEN LOWER(TRIM(status_order)) = 'antri' THEN 1
        WHEN LOWER(TRIM(status_order)) = 'proses' THEN 2
        WHEN LOWER(TRIM(status_order)) = 'selesai' THEN 3
        ELSE 4
    END ASC,
    tgl_penjualan DESC,
    jam DESC
    LIMIT 250";
$stmt = $pdo->prepare($sql); $stmt->execute($qp); $list_order = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sel = null; $files=[]; $tasks=[]; $st_aktif='Antri'; $wa_list=[]; $has_wa=false;
$progress_pct = 0; 

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT p.*, pl.nama_pelanggan, pl.no_telepon, pl.wa_lid, COALESCE(op.status_order, 'Antri') as status_order FROM penjualan p LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan LEFT JOIN order_pekerjaan op ON p.no_penjualan = op.no_penjualan WHERE p.no_penjualan = ?");
    $stmt->execute([$id]); $sel = $stmt->fetch(PDO::FETCH_ASSOC);
    if($sel) {
        $st_aktif = $sel['status_order'];
        $hp = preg_replace('/[^0-9]/', '', $sel['no_telepon']??''); if(substr($hp,0,1)=='0')$hp='62'.substr($hp,1);
        $lid = preg_replace('/[^0-9]/', '', $sel['wa_lid']??'');
        if($hp || $lid) { 
            $has_wa = true; 
            if(is_dir($wa_download_dir)) { 
                $sc = scandir($wa_download_dir); 
                foreach($sc as $f) { 
                    if($f != '.' && $f != '..') { 
                        if((!empty($hp) && strpos($f, $hp) !== false) || (!empty($lid) && strpos($f, $lid) !== false)) { 
                            $filepath = $wa_download_dir . '/' . $f;
                            
                            // 1. Ambil Waktu
                            $time = date('d/m', filemtime($filepath)); // Menampilkan Tanggal/Bulan saja (ubah jadi 'd/m H:i' jika ingin pakai jam)
                            
                            // 2. Hitung Ukuran File agar cantik (KB/MB)
                            $bytes = filesize($filepath);
                            if ($bytes >= 1048576) { $sz = round($bytes / 1048576, 1) . ' MB'; } 
                            elseif ($bytes >= 1024) { $sz = round($bytes / 1024, 1) . ' KB'; } 
                            else { $sz = $bytes . ' B'; }

                            // 3. Bersihkan Nama File
                            $c_name = $f;
                            
                            // [BARU] Hapus semua angka di awal nama file (biasanya ini nomor HP) beserta tanda _ atau -
                            // Contoh: "628123456_laporan.pdf" -> otomatis jadi "laporan.pdf"
                            $c_name = preg_replace('/^[0-9]+[_-]+/', '', $c_name);
                            
                            // Hapus nomor HP dari tampilan nama (Sebagai backup jika posisinya tidak di awal)
                            if(!empty($hp)) { 
                                $c_name = str_replace([$hp.'_', $hp.'-', $hp], '', $c_name); 
                            }
                            $c_name = ltrim($c_name, '_- '); // Bersihkan sisa garis bawah di depan

                            // 4. Masukkan ke list dropdown
                            $wa_list[] = [
                                'name' => $f, // Nama asli untuk sistem
                                'display' => "[$time] $c_name" // HANYA MENAMPILKAN TANGGAL & NAMA FILE
                            ]; 
                        }
                    }
                }
            }
        }
        
        $files = $pdo->prepare("SELECT * FROM order_files WHERE no_penjualan = ? ORDER BY waktu_upload DESC"); $files->execute([$id]); $files = $files->fetchAll();
        
        $allowed = ['Jilid', 'Softcover', 'Hardcover', 'Spiral', 'Editing', 'Edit', 'Laminating'];
        $sql_clean = "DELETE FROM order_tasks WHERE no_penjualan = ? AND (";
        $conds=[]; foreach($allowed as $a) $conds[]="nama_task NOT LIKE '%$a%'"; $sql_clean .= implode(" AND ", $conds) . ")";
        $pdo->prepare($sql_clean)->execute([$id]);

        $tasks = $pdo->prepare("SELECT * FROM order_tasks WHERE no_penjualan = ?"); $tasks->execute([$id]); $tasks = $tasks->fetchAll();
        
        if(empty($tasks)) {
            $items = $pdo->prepare("SELECT pi.*, b.nama_barang FROM penjualan_item pi JOIN barang b ON pi.kode_barang = b.kode_barang WHERE pi.no_penjualan = ?"); $items->execute([$id]); $items = $items->fetchAll();
            $tasks_to_add = [];
            foreach($items as $i) { 
                $txt = strtolower($i['nama_barang'] . ' ' . ($i['keterangan'] ?? '')); 
                $q = (int)$i['jumlah']; 
                $pre = "";

                if(strpos($txt,'jilid')!==false) $pre="Jilid"; 
                elseif(strpos($txt,'softcover')!==false) $pre="Softcover";
                elseif(strpos($txt,'hardcover')!==false) $pre="Hardcover";
                elseif(strpos($txt,'spiral')!==false) $pre="Spiral";
                elseif(strpos($txt,'edit')!==false) $pre="Editing";
                elseif(strpos($txt,'laminating')!==false) $pre="Laminating"; 
                elseif(strpos($txt,'potong')!==false) $pre="Laminating"; 

                if($pre){ 
                    for($x=1; $x<=$q; $x++) { 
                        $nm = "$pre: " . $i['nama_barang'] . ($q > 1 ? " ($x/$q)" : "");
                        $dup = false; foreach($tasks as $existing) { if($existing['nama_task'] == $nm) $dup = true; }
                        if(!$dup) $tasks_to_add[] = $nm; 
                    }
                }
            }
            if(!empty($tasks_to_add)) { 
                $ins=$pdo->prepare("INSERT INTO order_tasks (no_penjualan, nama_task) VALUES (?,?)"); 
                foreach($tasks_to_add as $nt) $ins->execute([$id,$nt]); 
                header("Refresh:0"); exit; 
            }
        }

        $tot_tasks = count($tasks);
        $done_tasks = 0; foreach($tasks as $t) { if($t['status_task']=='Selesai') $done_tasks++; }
        $tot_files = count($files);
        $done_files = 0; foreach($files as $f) { if($f['status_baca']=='Sudah') $done_files++; }
        $grand_tot = $tot_tasks + $tot_files;
        $grand_done = $done_tasks + $done_files;
        $progress_pct = ($grand_tot > 0) ? round(($grand_done / $grand_tot) * 100) : 0;
    }
}

// ================================================================
// AJAX: AMBIL PANEL DETAIL SATU ORDER SAJA (TANPA RELOAD HALAMAN PENUH)
// ================================================================
// Dipanggil saat klik item di "Daftar Antrian". Hanya me-render fragment
// .col-detail (file yang sama dipakai juga oleh halaman penuh di bawah),
// tanpa ikut merender sidebar, daftar antrian, maupun CSS/JS halaman.
if (isset($_GET['action']) && $_GET['action'] === 'get_order_detail') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_GET['id']) || !$sel) {
        echo json_encode(['status' => 'error', 'message' => 'Order tidak ditemukan.']);
        exit;
    }
    ob_start();
    include __DIR__ . '/_order_detail_partial.php';
    $html_detail = ob_get_clean();

    echo json_encode([
        'status'        => 'success',
        'no_penjualan'  => $sel['no_penjualan'],
        'nama_pelanggan'=> $sel['nama_pelanggan'] ?? 'UMUM',
        'status_order'  => $st_aktif,
        'html'          => $html_detail,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manajemen Produksi | POS System</title>
    <link rel="icon" type="image/png" href="../../assets/img/logo.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { 
            background: #f0f2f5; 
            font-size: 0.9rem; 
            font-family: 'Inter', sans-serif;
            overflow-x: hidden; 
        }
        
        /* Layout Pembungkus */
        .wrapper { display: flex; width: 100vw; height: 100vh; overflow: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; height: 100vh; overflow-y: auto; background: #f0f2f5;}
        
        .header-top { height: 70px; background-color: #fff; padding: 0 1.5rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; z-index: 1020; position: sticky; top: 0;}
        
        /* List Antrian Custom */
        .list-group-item { border-left: 4px solid transparent; transition: 0.2s; margin-bottom: 6px; border-radius: 8px !important; border: 1px solid rgba(0,0,0,0.05); }
        .list-group-item:hover { background-color: #f8f9fa; }
        .list-group-item.active-order { border-left: 4px solid #0d6efd; background: #e7f1ff; }
        
        .badge-status { font-size: 0.7rem; padding: 4px 8px; border-radius: 10px; font-weight: bold;}
        .s-antri { background: #ffc107; color: #000; } 
        .s-proses { background: #0dcaf0; color: #000; } 
        .s-selesai { background: #198754; color: #fff; }
        
        /* Task & Files */
        .task-row { transition: 0.2s; cursor: pointer; border-bottom: 1px solid #f0f0f0;} 
        .task-row:last-child { border-bottom: none; }
        .task-row:hover { background: #f8f9fa; }
        .task-done { text-decoration: line-through; color: #198754; opacity: 0.7; }
        
        /* Select2 Setup */
        .select2-container--bootstrap-5 .select2-selection { font-size: 0.85rem; min-height: 35px; border-radius: 6px;}
        .select2-container .select2-selection--single .select2-selection__rendered { padding-top: 3px; }
        
        /* Penyesuaian Scroll Kolom */
        .scroll-list { max-height: calc(100vh - 210px); overflow-y: auto; padding-right: 5px;}
        
        /* Responsif Mobile - Sembunyikan kolom jika tidak diperlukan */
        @media(max-width:768px) { 
            .col-list { display: <?= $sel ? 'none' : 'block' ?>; } 
            .col-detail { display: <?= $sel ? 'block' : 'none' ?>; } 
            .scroll-list { max-height: calc(100vh - 160px); }
        }

        /* Custom Select2 Text Wrap untuk Teks Panjang */
        .select2-container--bootstrap-5 .select2-selection--single {
            height: auto !important; 
            min-height: 35px;
        }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            white-space: normal !important;
            word-wrap: break-word !important;
            line-height: 1.4 !important;
            padding-top: 5px !important;
            padding-bottom: 5px !important;
        }
        /* Menyesuaikan list pilihan saat dropdown dibuka */
        .select2-results__option {
            white-space: normal !important;
            word-wrap: break-word !important;
        }
    </style>
</head>
<body>

<div class="wrapper">
    <?php 
        $base_dir = '../../'; 
        include '../../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="header-top shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Manajemen Produksi</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Addinta Printing</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <a href="index.php?periode=<?= $filter_periode ?>" id="btnBackToList" class="btn btn-light border btn-sm d-md-none fw-bold shadow-sm rounded-3 js-back-to-list" style="<?= $sel ? '' : 'display:none;' ?>">
                    <i class="fas fa-list me-1"></i> List Antrian
                </a>
                
                <div class="d-none d-sm-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="d-flex flex-column ms-2 me-3 justify-content-center">
                        <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                    </div>
                </div>
            </div>
        </header>

        <div class="container-fluid px-3 py-4">
            <div class="row">
                
                <div class="col-md-4 col-lg-3 mb-3 col-list">
                    <div class="card shadow-sm border-0 rounded-3 h-100">
                        <div class="p-3 border-bottom bg-white rounded-top-3">
                            <h6 class="fw-bolder mb-3 text-dark"><i class="fas fa-list-ol text-primary me-2"></i>Daftar Antrian</h6>
                            <form method="GET" class="row g-2">
                                <div class="col-12">
                                    <div class="input-group input-group-sm shadow-sm" style="border-radius:6px; overflow:hidden;">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="fas fa-search"></i></span>
                                        <input type="text" name="cari" class="form-control border-start-0 ps-0" placeholder="Cari Nama/Nota..." value="<?= htmlspecialchars($keyword) ?>" style="box-shadow:none;">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <select name="periode" class="form-select form-select-sm text-muted shadow-sm" onchange="this.form.submit()">
                                        <option value="hari_ini" <?= $filter_periode=='hari_ini'?'selected':'' ?>>Order Hari Ini</option>
                                        <option value="kemarin" <?= $filter_periode=='kemarin'?'selected':'' ?>>Kemarin</option>
                                        <option value="semua" <?= $filter_periode=='semua'?'selected':'' ?>>Semua Waktu</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                        
                        <div class="card-body p-2 scroll-list" id="live-order-list">
                            <div class="list-group list-group-flush">
                                <?php if(empty($list_order)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-box-open fa-2x mb-2 opacity-25"></i>
                                        <div class="small fw-bold">Tidak ada antrian</div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach($list_order as $lo): 
                                        $bg='s-antri'; 
                                        if($lo['status_order']=='Proses')$bg='s-proses'; 
                                        elseif($lo['status_order']=='Selesai')$bg='s-selesai'; 
                                    ?>
                                    <a href="?id=<?= $lo['no_penjualan'] ?><?= $url_params ?>" data-nota="<?= htmlspecialchars($lo['no_penjualan'], ENT_QUOTES) ?>" class="list-group-item list-group-item-action js-order-link <?= (isset($_GET['id']) && $_GET['id']==$lo['no_penjualan'])?'active-order':'' ?>">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="fw-bold <?= (isset($_GET['id']) && $_GET['id']==$lo['no_penjualan']) ? 'text-primary' : 'text-dark' ?>" style="font-size: 0.95rem;"><?= htmlspecialchars($lo['nama_pelanggan']??'UMUM') ?></span>
                                            <span class="badge-status badge-status-left <?= $bg ?>"><?= $lo['status_order'] ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small class="text-muted fw-semibold">#<?= $lo['no_penjualan'] ?></small>
                                            <small class="text-muted"><i class="far fa-clock me-1"></i><?= date('H:i', strtotime($lo['jam'])) ?></small>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php include __DIR__ . '/_order_detail_partial.php'; ?>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modPrev">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">
            <div class="modal-header bg-dark border-0 py-2 px-3">
                <h6 class="modal-title text-white-50"><i class="fas fa-eye me-2"></i>Preview File</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-center bg-dark" id="contPrev"></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.21/mammoth.browser.min.js"></script>
<script>
    let CURRENT_ORDER_NO = <?= json_encode($sel['no_penjualan'] ?? '') ?>;
    const FILTER_PERIODE = <?= json_encode($filter_periode) ?>;
    const FILTER_KEYWORD = <?= json_encode($keyword) ?>;

    function initDetailWidgets() {
        try { $('.select2-wa').select2({theme:'bootstrap-5', width:'100%'}); } catch(e) {}
        try { $('.select2-kertas').select2({theme:'bootstrap-5', width:'100%', placeholder: '- Kertas -', tags: true}); } catch(e) {}
        try { $('.select2-ukuran').select2({theme:'bootstrap-5', width:'100%', placeholder: '- Ukuran -', tags: true}); } catch(e) {}
    }

    function setActiveOrderInList(no) {
        $('#live-order-list .js-order-link').each(function(){
            const isActive = (String($(this).data('nota')) === String(no));
            $(this).toggleClass('active-order', isActive);
            $(this).find('.fw-bold').first()
                .toggleClass('text-primary', isActive)
                .toggleClass('text-dark', !isActive);
        });
    }

    function showDetailPanelMobile() {
        if (window.innerWidth <= 768) { $('.col-list').hide(); $('.col-detail').show(); }
        $('#btnBackToList').show();
    }
    function showListPanelMobile() {
        if (window.innerWidth <= 768) { $('.col-detail').hide(); $('.col-list').show(); }
    }

    // Ambil panel detail 1 order lewat AJAX (tanpa reload halaman penuh).
    // Ini yang bikin klik antrian jadi cepat: server cuma render fragment detail,
    // bukan seluruh halaman (sidebar, daftar antrian, CSS/JS ikut ter-render ulang).
    function loadOrderDetail(no, pushState) {
        if (!no) return;
        pushState = (pushState !== false);
        const $detail = $('.col-detail');
        $detail.css('opacity', 0.5);

        $.get('index.php', { action: 'get_order_detail', id: no, periode: FILTER_PERIODE, cari: FILTER_KEYWORD }, function(res){
            if (!res || res.status !== 'success') {
                moToast('error', (res && res.message) ? res.message : 'Gagal memuat detail order');
                $detail.css('opacity', 1);
                return;
            }
            $detail.replaceWith(res.html);
            CURRENT_ORDER_NO = res.no_penjualan;
            setActiveOrderInList(res.no_penjualan);
            initDetailWidgets();
            showDetailPanelMobile();

            if (pushState) {
                const url = '?id=' + encodeURIComponent(res.no_penjualan) + '&periode=' + encodeURIComponent(FILTER_PERIODE) + '&cari=' + encodeURIComponent(FILTER_KEYWORD);
                history.pushState({ no_penjualan: res.no_penjualan }, '', url);
            }
        }, 'json').fail(function(){
            moToast('error', 'Gagal memuat detail order (koneksi terputus).');
            $detail.css('opacity', 1);
        });
    }

    // Tombol back/forward browser: kalau ada state order tersimpan, ambil ulang via AJAX.
    // Kalau tidak ada (misal load pertama), fallback ke reload biasa supaya tetap aman.
    $(window).on('popstate', function(e){
        const state = e.originalEvent.state;
        if (state && state.no_penjualan) {
            loadOrderDetail(state.no_penjualan, false);
        } else {
            location.reload();
        }
    });

    if (CURRENT_ORDER_NO) {
        history.replaceState({ no_penjualan: CURRENT_ORDER_NO }, '', window.location.href);
    }

    $(document).ready(function(){ 
        initDetailWidgets();
        
        $(document).on('select2:open', () => { setTimeout(() => { document.querySelector('.select2-search__field').focus(); }, 50); });

        // Klik item di Daftar Antrian: ambil detail via AJAX, jangan reload halaman.
        $(document).on('click', '.js-order-link', function(e){
            e.preventDefault();
            loadOrderDetail($(this).data('nota'), true);
        });

        // Tombol "List Antrian" (mobile): cukup tukar panel, data sudah ada, tidak perlu request lagi.
        $(document).on('click', '.js-back-to-list', function(e){
            e.preventDefault();
            showListPanelMobile();
        });

        // REALTIME REFRESH RINGAN
        // Tidak lagi reload HTML halaman penuh setiap 10 detik karena itu membuat Manajemen Order berat.
        // Sekarang hanya ambil status ringkas: status order, badge file, dan progress.
        setInterval(lightRefreshOrderStatus, 25000); 
    });
    
    function orderStatusClass(status) {
        status = String(status || '').toLowerCase().trim();
        if (status === 'proses') return 's-proses';
        if (status === 'selesai') return 's-selesai';
        return 's-antri';
    }

    function lightRefreshOrderStatus() {
        if (document.hidden) return;
        // Jangan refresh saat modal print/preview sedang terbuka supaya tidak mengganggu operator.
        if ($('.modal.show').length > 0) return;

        const notes = [];
        $('#live-order-list [data-nota]').each(function(){
            const n = $(this).data('nota');
            if (n) notes.push(n);
        });
        if (!notes.length && !CURRENT_ORDER_NO) return;

        $.post('index.php', {action:'order_light_refresh', notes: notes, selected_no: CURRENT_ORDER_NO}, function(res){
            if (!res || res.status !== 'success') return;

            if (res.orders) {
                Object.keys(res.orders).forEach(function(no){
                    const st = res.orders[no] || 'Antri';
                    const badge = $('#live-order-list [data-nota="'+no+'"]').find('.badge-status-left');
                    badge.text(st).removeClass('s-antri s-proses s-selesai').addClass(orderStatusClass(st));
                });
            }

            if (Array.isArray(res.files)) {
                res.files.forEach(function(f){
                    if (f.safe_id && f.html) $('#badge-file-'+f.safe_id).html(f.html);
                });
            }

            if (res.progress) {
                updateProgressBar(res.progress.persen || 0);
                const uf = parseInt(res.progress.unfinished_files || 0, 10);
                const ut = parseInt(res.progress.unfinished_tasks || 0, 10);
                if (uf > 0) $('#badge-tab-file').text(uf).show(); else $('#badge-tab-file').hide();
                if (ut > 0) $('#badge-tab-task').text(ut).show(); else $('#badge-tab-task').hide();
            }
        }, 'json');
    }

    function updateOrderStatusUI(new_status) {
        $('select[name="status_order"]').val(new_status);
        $('.active-order .badge-status-left').text(new_status)
            .removeClass('s-antri s-proses s-selesai')
            .addClass(new_status === 'Antri' ? 's-antri' : (new_status === 'Proses' ? 's-proses' : 's-selesai'));
    }

    function moToast(icon, title) {
        if (window.Swal) {
            Swal.fire({toast:true, position:'top-end', icon:icon, title:title, showConfirmButton:false, timer:1700});
        } else {
            console.log(title);
        }
    }

    function refreshSelectedOrderSection() {
        if (!CURRENT_ORDER_NO) return;
        lightRefreshOrderStatus();
        // Refresh hanya panel detail order lewat endpoint AJAX ringan, bukan reload halaman penuh.
        loadOrderDetail(CURRENT_ORDER_NO, false);
    }

    $(document).on('submit', '#formOrderStatus', function(e){
        e.preventDefault();
        return false;
    });

    $(document).on('change', '#statusOrderSelect', function(){
        const $sel = $(this);
        const no = $('#formOrderStatus').data('no') || CURRENT_ORDER_NO;
        const st = $sel.val();
        const oldText = $sel.data('old') || '';
        $sel.prop('disabled', true);
        $.post('index.php', {action:'update_order_status_ajax', no_penjualan:no, status_order:st}, function(res){
            if(res && res.status === 'success') {
                updateOrderStatusUI(res.new_order_status || st);
                moToast('success', 'Status order diperbarui');
            } else {
                moToast('error', (res && res.message) ? res.message : 'Gagal update status');
                if (oldText) $sel.val(oldText);
            }
        }, 'json').fail(function(xhr){
            moToast('error', 'Gagal AJAX update status: '+xhr.status);
            if (oldText) $sel.val(oldText);
        }).always(function(){
            $sel.prop('disabled', false).data('old', $sel.val());
        });
    });

    $(document).on('submit', '.form-order-upload', function(e){
        e.preventDefault();
        const form = this;
        const fd = new FormData(form);
        fd.append('action', 'upload_file_ajax');
        fd.append('no_penjualan', $(form).data('no') || CURRENT_ORDER_NO);
        const $btn = $(form).find('button[name="upload_file"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Upload...');
        $.ajax({url:'index.php', method:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
          .done(function(res){
              if(res && res.status === 'success') {
                  moToast('success', res.message || 'File berhasil ditambahkan');
                  form.reset();
                  $(form).find('input[name="qty_cetak"]').val('1');
                  $(form).find('input[name="halaman"]').val('All');
                  refreshSelectedOrderSection();
              } else {
                  moToast('error', (res && res.message) ? res.message : 'Upload gagal');
              }
          })
          .fail(function(xhr){
              moToast('error', 'Upload gagal AJAX: '+xhr.status);
          })
          .always(function(){
              $btn.prop('disabled', false).html(oldHtml);
          });
    });

    function updateFilePrintPhase(file_id_safe, path_file, nota, phase) {
        const label = phase === 'even' ? 'Proses print halaman genap' : 'Proses print halaman ganjil';
        $('#badge-file-'+file_id_safe).html('<span class="badge bg-info text-dark rounded-pill" style="font-size:0.7rem;">'+label+'</span>');
        $.post('index.php', {action:'update_file_print_phase', path_file:path_file, no_penjualan:nota, phase:phase}, function(res){
            if(res && res.status === 'success') {
                updateOrderStatusUI(res.new_order_status || 'Proses');
                if(res.file_status) {
                    $('#badge-file-'+file_id_safe).html('<span class="badge bg-info text-dark rounded-pill" style="font-size:0.7rem;">'+res.file_status.replace('Print Halaman', 'print halaman')+'</span>');
                }
            }
        }, 'json');
    }

    function updateFileStatus(file_id_safe, path_file, nota, btn, printIncrement = 1, printInfo = {}) {
        printIncrement = Math.max(1, parseInt(printIncrement || '1', 10));
        $.post('index.php', {
            action:'update_file_status',
            path_file:path_file,
            no_penjualan:nota,
            print_increment:printIncrement,
            print_client_id:(printInfo.client_id || ''),
            print_client_name:(printInfo.client_name || ''),
            print_printer_name:(printInfo.printer_name || ''),
            print_job_id:(printInfo.job_id || '')
        }, function(res){
            if(res.status=='success') { 
                updateProgressBar(res.persen);
                updateOrderStatusUI(res.new_order_status);
                
                let t = res.target; let c = res.current; let d = res.is_done;
                const clientName = printInfo.client_name || '';
                const printerName = printInfo.printer_name || '';
                let lastInfo = '';
                if (clientName || printerName) {
                    lastInfo = '<div class="mt-1 small text-muted lh-sm" style="font-size:0.68rem;">'
                        + (clientName ? '<i class="fas fa-desktop me-1"></i>'+ $('<div>').text(clientName).html() : '')
                        + (printerName ? '<br><i class="fas fa-print me-1"></i>'+ $('<div>').text(printerName).html() : '')
                        + '<br><i class="fas fa-clock me-1"></i>Baru saja</div>';
                }
                if (d) { 
                    $('#badge-file-'+file_id_safe).html('<span class="badge bg-success ms-1 rounded-pill" style="font-size:0.7rem;">Selesai ('+c+'/'+t+')</span>' + lastInfo); 
                    $(btn).removeClass('btn-dark btn-success disabled').addClass('btn-warning text-dark').attr('title','Reprint file ini').html('<i class="fas fa-redo"></i>');
                    
                    if(res.persen >= 100) {
                        Swal.fire({icon:'success', title:'Semua Selesai!', text:'Status otomatis menjadi Selesai.', timer:1500, showConfirmButton:false}); 
                    }
                } else { 
                    $('#badge-file-'+file_id_safe).html('<span class="badge bg-warning text-dark ms-1 rounded-pill" style="font-size:0.7rem;">Diprint ('+c+'/'+t+')</span>' + lastInfo); 
                }
            }
        }, 'json');
    }
    
    function toggleTask(id, nota) {
        let row = $('#task-'+id); 
        let iconCont = row.find('.icon-task-container'); 
        let icon = row.find('.icon-task'); 
        let text = row.find('.text-task-name'); 
        let isDone = icon.hasClass('fa-check'); 
        let newStatus = isDone ? 'Pending' : 'Selesai';
        
        if(!isDone) { 
            iconCont.removeClass('bg-light border text-muted').addClass('bg-success text-white');
            icon.removeClass('fa-stop').addClass('fa-check'); 
            text.addClass('task-done text-muted').removeClass('text-dark'); 
        } else { 
            iconCont.addClass('bg-light border text-muted').removeClass('bg-success text-white');
            icon.addClass('fa-stop').removeClass('fa-check'); 
            text.removeClass('task-done text-muted').addClass('text-dark'); 
        }
        
        $.post('index.php', {action:'update_task_status', id:id, status:newStatus, no_penjualan:nota}, function(res){ 
            if(res.status=='success') { 
                updateProgressBar(res.persen); 
                updateOrderStatusUI(res.new_order_status);
                
                if(res.persen >= 100) {
                    Swal.fire({icon:'success', title:'Semua Selesai!', text:'Status otomatis menjadi Selesai.', toast:true, position:'top-end', showConfirmButton:false, timer:1500}); 
                }
            } 
        }, 'json');
    }
    
    function updateProgressBar(persen) { $('#lblPersen').text(persen+'%'); $('#progressBar').css('width', persen+'%'); }
    
    function previewFile(url, ext) { 
        let c = $('#contPrev'); 
        c.empty(); 
        let modal = new bootstrap.Modal(document.getElementById('modPrev'));
        
        if (ext === 'pdf') {
            c.html('<iframe src="'+url+'" style="width:100%;height:85vh;border:0; border-radius: 0 0 16px 16px;"></iframe>'); 
            modal.show();
        } 
        else if (ext === 'docx') {
            c.html('<div class="d-flex flex-column justify-content-center align-items-center bg-dark" style="height:85vh; border-radius: 0 0 16px 16px;"><i class="fas fa-circle-notch fa-spin fa-3x text-light mb-3"></i><h5 class="text-white-50">Menyusun Kertas...</h5></div>');
            modal.show();
            fetch(url)
                .then(response => response.arrayBuffer())
                .then(arrayBuffer => mammoth.convertToHtml({arrayBuffer: arrayBuffer}))
                .then(result => {
                    let pageLayout = `
                        <style>
                            #word-preview-container::-webkit-scrollbar { width: 8px; }
                            #word-preview-container::-webkit-scrollbar-track { background: #525659; border-radius: 0 0 16px 0; }
                            #word-preview-container::-webkit-scrollbar-thumb { background: #7a7d80; border-radius: 4px; }
                            .docx-content { word-wrap: break-word; outline: none; border: none; }
                            .docx-content p { margin-bottom: 0.5rem; line-height: 1.6; }
                            .docx-content table { border-collapse: collapse; width: 100%; margin-bottom: 1rem; }
                            .docx-content td, .docx-content th { border: 1px dotted #ccc; padding: 8px; }
                            .docx-content img { max-width: 100%; height: auto; }
                            .docx-content hr { border: none; border-top: 2px dashed #ccc; margin: 2rem 0; }
                        </style>
                        <div id="word-preview-container" style="background-color: #525659; height: 85vh; overflow-y: auto; overflow-x: hidden; border-radius: 0 0 16px 16px; padding: 2rem 0; display: flex; flex-direction: column; align-items: center;">
                            <div class="docx-content shadow-lg" style="background: #ffffff; width: 100%; max-width: 21cm; min-height: 29.7cm; padding: 2.5cm; text-align: left; font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; box-sizing: border-box;">
                                ${result.value}
                            </div>
                        </div>
                    `;
                    c.html(pageLayout);
                })
                .catch(err => {
                    c.html('<div class="d-flex flex-column justify-content-center align-items-center bg-white text-dark p-4" style="height:85vh; border-radius: 0 0 16px 16px;"><i class="fas fa-exclamation-triangle fa-4x text-danger mb-3"></i><h5>Gagal Membaca File</h5><a href="'+url+'" download class="btn btn-primary mt-2">Download File Ini</a></div>');
                });
        }
        else if (ext === 'doc') {
            c.html(`
                <div class="d-flex flex-column justify-content-center align-items-center bg-white text-dark p-4" style="height:85vh; border-radius: 0 0 16px 16px;">
                    <i class="fas fa-file-word fa-5x text-secondary mb-4"></i>
                    <h4 class="fw-bolder">Format Dokumen Lama</h4>
                    <p class="text-muted text-center" style="max-width: 400px;">File ini berekstensi <b>.doc</b> yang tidak bisa di-preview secara Offline.</p>
                    <a href="${url}" download class="btn btn-primary fw-bold mt-2"><i class="fas fa-download me-2"></i>Download File</a>
                </div>
            `);
            modal.show();
        }
        else {
            c.html('<div class="d-flex align-items-center justify-content-center bg-dark" style="height:85vh; padding: 2rem; border-radius: 0 0 16px 16px;"><img src="'+url+'" class="img-fluid shadow-lg" style="max-height:80vh; border-radius: 8px;"></div>'); 
            modal.show();
        }
    }
    
    function kirimWaAjax(id) { 
        let st = $('select[name="status_order"]').val(); 
        Swal.fire({title:'Mengirim WA...', allowOutsideClick:false, didOpen:()=>{Swal.showLoading()}}); 
        $.ajax({
            url: 'index.php',
            method: 'POST',
            data: {action:'kirim_wa_ajax', id:id, status:st},
            dataType: 'json',
            timeout: 12000
        }).done(function(r){
            Swal.fire({icon: r.status, title: r.message, timer:2500, showConfirmButton:false});
        }).fail(function(xhr, textStatus){
            // Pastikan popup loading tidak nyangkut selamanya kalau respon gagal/rusak/timeout.
            let pesan = (textStatus === 'timeout')
                ? 'Waktu tunggu habis. WA Gateway tidak merespon.'
                : 'Gagal mengirim WA. Cek koneksi ke WA Gateway (server WhatsApp mungkin sedang mati).';
            Swal.fire({icon: 'error', title: pesan, showConfirmButton: true});
        });
    }


    let paClients = [];
    let paCurrentBtn = null;
    let paIsSplitBorderlessDuplex = false;
    let paPreferredPrinterPaperType = 'plain';
    function openPrintAgentModal(fileId, fileName, nota, fileSafe, pathFile, finishingText, orientationText, qtyTarget, printedCount, pageCount, pageRange, printGrayscale, printerPaperType, paperSize, btn) {
        paCurrentBtn = btn;
        qtyTarget = Math.max(1, parseInt(qtyTarget || '1', 10));
        printedCount = Math.max(0, parseInt(printedCount || '0', 10));
        const remainingQty = Math.max(1, qtyTarget - printedCount);
        $('#paFileId').val(fileId);
        $('#paNota').val(nota);
        $('#paFileSafe').val(fileSafe);
        $('#paPathFile').val(pathFile);
        $('#paFinishing').val(finishingText || '');
        const ov = String(orientationText || 'auto').toLowerCase();
        $('#paOrientation').val((ov === 'landscape' || ov === 'portrait') ? ov : 'auto');
        $('#paOrientationLabel').text(($('#paOrientation').val() === 'landscape') ? 'Landscape' : ($('#paOrientation').val() === 'portrait' ? 'Portrait' : 'Auto'));
        $('#paQtyTarget').val(qtyTarget);
        $('#paPrintedCount').val(printedCount);
        $('#paRemainingQty').val(remainingQty);
        pageCount = Math.max(0, parseInt(pageCount || '0', 10));
        $('#paPageCount').val(pageCount);
        $('#paPageRange').val(pageRange || 'All');
        const ps = String(paperSize || '').toUpperCase();
        $('#paPaperSize').val(ps || '');
        $('#paPaperSizeInfo').text(ps || '-');
        $('#paGrayscale').prop('checked', parseInt(printGrayscale || '0', 10) === 1);
        const ppt = paCleanPaperType(printerPaperType || 'plain');
        paPreferredPrinterPaperType = ppt;
        $('#paPrinterPaperType').val(ppt);
        $('#paPrinterPaperTypeInfo').text(paPaperTypeLabel(ppt));
        $('#paFileName').text(fileName);
        $('#paQtyInfo').text(qtyTarget + ' qty total, sudah diprint ' + printedCount + ', sisa ' + remainingQty);
        if (pageCount > 0) {
            const rangeText = (pageRange && !/^(all|semua|semua halaman)$/i.test(String(pageRange).trim())) ? ' (range: ' + $('<div>').text(pageRange).html() + ')' : '';
            $('#paPageCountInfo').html('<b>' + pageCount + ' halaman</b> yang akan diprint' + rangeText);
        } else {
            $('#paPageCountInfo').html('<span class="text-muted">Total halaman belum tersedia</span>');
        }
        if (qtyTarget > 1 && remainingQty > 1) {
            $('#paCopiesBox').show();
            $('#paCopiesAllLabel').text('Print semua sisa qty (' + remainingQty + ' lembar/copy)');
            $('#paCopiesSingleLabel').text('Print satuan saja (1 lembar/copy)');
            $('#paCopiesAll').prop('checked', true);
        } else {
            $('#paCopiesBox').hide();
            $('#paCopiesAll').prop('checked', true);
        }
        const ftxt = String(finishingText || '').toLowerCase();
        paIsSplitBorderlessDuplex = ftxt.includes('2') && ftxt.includes('borderless');
        $('#paSplitBox').toggle(paIsSplitBorderlessDuplex);
        if (paIsSplitBorderlessDuplex) {
            $('#paSplitInfo').html('<span class="text-warning fw-bold">Mode 2 sisi borderless:</span> gunakan tombol <b>Print Genap</b> lalu <b>Print Ganjil</b>.');
            $('#paBtnSend').html('<i class="fas fa-paper-plane me-1"></i>Print Semua');
            setTimeout(paUpdateSplitButtons, 0);
        } else {
            $('#paSplitInfo').text('');
            $('#paBtnSend').html('<i class="fas fa-paper-plane me-1"></i>Print Otomatis');
        }
        $('#paStatus').text('Memuat daftar client aktif...');
        $('#paJobMonitor').hide().html('');
        $('#paClient').html('<option value="">Memuat...</option>');
        $('#paPrinter').html('<option value="">Pilih printer...</option>');
        const modal = new bootstrap.Modal(document.getElementById('printAgentModal'));
        modal.show();
        $.post('index.php', {action:'print_agent_list'}, function(res){
            if(!res || res.status !== 'success') {
                $('#paStatus').html('<span class="text-danger">Gagal memuat client print.</span>');
                return;
            }
            paClients = res.clients || [];
            if(paClients.length === 0) {
                $('#paClient').html('<option value="">Tidak ada client aktif</option>');
                $('#paStatus').html('<span class="text-danger">Tidak ada Print Agent aktif. Jalankan Addinta Print Agent di komputer client.</span>');
                return;
            }
            let html = '';
            let selectedId = '';
            paClients.forEach(c => {
                if(c.is_current_ip && !selectedId) selectedId = c.id;
                html += `<option value="${c.id}">${c.client_name} (${c.ip_address || '-'})${c.is_current_ip ? ' - komputer ini' : ''}</option>`;
            });
            $('#paClient').html(html);
            if(selectedId) $('#paClient').val(selectedId);
            paRenderPrinters();
            $('#paStatus').html('<span class="text-success">Client aktif ditemukan.</span>');
        }, 'json').fail(function(){
            $('#paStatus').html('<span class="text-danger">Gagal menghubungi server.</span>');
        });
    }
    function paCleanPaperType(v) {
        return String(v || '').trim().replace(/[^a-zA-Z0-9 _\-.\/()+]/g, '').substring(0, 120) || 'plain';
    }
    function paPaperTypeLabel(v) {
        const raw = paCleanPaperType(v);
        const key = raw.toLowerCase();
        const map = {
            'plain':'Plain Paper',
            'auto':'Auto Select',
            'autoselect':'Auto Select',
            'photo_glossy':'Photo Paper Glossy',
            'photoglossy':'Photo Paper Glossy',
            'photographicglossy':'Photo Paper Glossy',
            'photographic':'Photo Paper',
            'glossy':'Glossy',
            'transparency':'Transparency',
            'label':'Label',
            'envelope':'Envelope',
            'cardstock':'Cardstock',
            'recycled':'Recycled Paper'
        };
        if (map[key]) return map[key];
        return raw.replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2').replace(/\s+/g, ' ').trim();
    }
    function paFallbackPaperTypes() {
        return [
            {value:'plain', label:'Plain Paper'},
            {value:'photo_glossy', label:'Photo Paper Glossy'}
        ];
    }
    function paSelectedPrinterObj() {
        const clientId = $('#paClient').val();
        const printerName = $('#paPrinter').val();
        const client = paClients.find(c => String(c.id) === String(clientId));
        if (!client || !Array.isArray(client.printers)) return null;
        return client.printers.find(p => String(p.name) === String(printerName)) || null;
    }
    function paRenderPaperTypes() {
        const printer = paSelectedPrinterObj();
        let types = printer && Array.isArray(printer.paper_types) ? printer.paper_types : [];
        if (!types.length) types = paFallbackPaperTypes();
        const wanted = paCleanPaperType($('#paPrinterPaperType').val() || paPreferredPrinterPaperType || 'plain');
        const seen = new Set();
        const normalized = [];
        types.forEach(t => {
            const value = paCleanPaperType(typeof t === 'object' ? (t.value || t.name || t.label) : t);
            if (!value || seen.has(value.toLowerCase())) return;
            seen.add(value.toLowerCase());
            const label = typeof t === 'object' && t.label ? String(t.label) : paPaperTypeLabel(value);
            normalized.push({value, label});
        });
        if (!seen.has(wanted.toLowerCase())) {
            normalized.unshift({value:wanted, label:paPaperTypeLabel(wanted) + ' (dari Profile Harga)'});
        }
        let html = '';
        normalized.forEach(t => {
            html += `<option value="${$('<div>').text(t.value).html()}">${$('<div>').text(t.label).html()}</option>`;
        });
        $('#paPrinterPaperType').html(html).val(wanted);
        if (!$('#paPrinterPaperType').val() && normalized.length) $('#paPrinterPaperType').val(normalized[0].value);
        const selected = $('#paPrinterPaperType').val() || wanted;
        $('#paPrinterPaperTypeInfo').text(paPaperTypeLabel(selected));
        if (printer && printer.paper_types && printer.paper_types.length) {
            $('#paPrinterPaperTypeHint').html('Daftar Paper Type diambil dari driver printer: <b>' + $('<div>').text(printer.name || '').html() + '</b>.');
        } else {
            $('#paPrinterPaperTypeHint').html('Driver printer belum mengirim daftar Paper Type. Dipakai pilihan fallback/manual.');
        }
    }

    function paRenderPrinters() {
        const clientId = $('#paClient').val();
        const client = paClients.find(c => String(c.id) === String(clientId));
        if(!client || !client.printers || client.printers.length === 0) {
            $('#paPrinter').html('<option value="">Printer tidak ditemukan</option>');
            $('#paDefaultPrinterHint').html('<span class="text-danger">Default printer Windows belum terdeteksi. Pastikan Print Agent aktif dan printer sudah dipasang.</span>');
            paRenderPaperTypes();
            return;
        }

        const printers = [...client.printers].sort((a,b) => {
            const ad = a.is_default ? 1 : 0;
            const bd = b.is_default ? 1 : 0;
            if (ad !== bd) return bd - ad;
            return String(a.name || '').localeCompare(String(b.name || ''));
        });

        let html = '';
        let selected = '';
        printers.forEach(p => {
            if(p.is_default && !selected) selected = p.name;
            html += `<option value="${$('<div>').text(p.name).html()}">${p.name}${p.is_default ? ' - default Windows' : ''}</option>`;
        });
        if(!selected && printers.length === 1) selected = printers[0].name;

        $('#paPrinter').html(html);
        if(selected) {
            $('#paPrinter').val(selected);
            $('#paDefaultPrinterHint').html('<span class="text-success"><i class="fas fa-check-circle me-1"></i>Otomatis memakai default printer komputer ini: <b>'+ $('<div>').text(selected).html() +'</b></span>');
        } else {
            $('#paDefaultPrinterHint').html('<span class="text-warning">Komputer ini belum mengirim default printer. Pilih printer manual atau set default printer di Windows.</span>');
        }
        paRenderPaperTypes();
    }

    function paSplitStorageKey() {
        return 'addinta_pa_even_done_' + String($('#paFileId').val() || '') + '_' + String($('#paPathFile').val() || '').replace(/[^a-zA-Z0-9_-]/g, '_');
    }
    function paIsEvenPrinted() {
        try { return localStorage.getItem(paSplitStorageKey()) === '1'; } catch(e) { return false; }
    }
    function paSetEvenPrinted(done) {
        try {
            if (done) localStorage.setItem(paSplitStorageKey(), '1');
            else localStorage.removeItem(paSplitStorageKey());
        } catch(e) {}
        paUpdateSplitButtons();
    }
    function paUpdateSplitButtons() {
        if (!paIsSplitBorderlessDuplex) return;
        const done = paIsEvenPrinted();
        if (done) {
            $('#paBtnPrintEven')
              .removeClass('btn-outline-dark')
              .addClass('btn-success')
              .html('<i class="fas fa-check me-1"></i>Genap Sudah Diprint')
              .prop('disabled', true);
            $('#paBtnPrintOdd')
              .removeClass('btn-outline-dark')
              .addClass('btn-primary')
              .prop('disabled', false);
            $('#paEvenDoneNotice').html('<span class="badge bg-success"><i class="fas fa-check me-1"></i>Halaman genap sudah diprint</span><div class="small text-muted mt-1">Silakan balik kertas sesuai alur printer, lalu klik <b>Print Ganjil</b>.</div>');
        } else {
            $('#paBtnPrintEven')
              .removeClass('btn-success')
              .addClass('btn-outline-dark')
              .html('<i class="fas fa-print me-1"></i>Print Genap')
              .prop('disabled', false);
            $('#paBtnPrintOdd')
              .removeClass('btn-primary')
              .addClass('btn-outline-dark')
              .prop('disabled', false);
            $('#paEvenDoneNotice').html('');
        }
    }
    function paEscapeHtml(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    function paRenderJobMonitor(data) {
        if (!data) return;
        const st = String(data.job_status || 'pending').toLowerCase();
        const badge = st === 'done' ? 'success' : (st === 'failed' ? 'danger' : (st === 'processing' ? 'info text-dark' : 'secondary'));
        const spin = st === 'processing' || st === 'pending' ? ' <i class="fas fa-spinner fa-spin"></i>' : '';
        const msg = data.status_message || data.error_message || '';
        const pageData = data.page_data || data.last_print_page_data || '-';
        const html = `
            <div class="alert alert-light border rounded-3 small mb-0">
                <div class="fw-bold mb-1">Status Print Client ${spin}</div>
                <div><span class="badge bg-${badge}">${paEscapeHtml(data.job_status_label || st)}</span></div>
                <div class="mt-2"><i class="fas fa-desktop me-1"></i><b>Client:</b> ${paEscapeHtml(data.client_name || '-')} ${data.client_ip ? '('+paEscapeHtml(data.client_ip)+')' : ''}</div>
                <div><i class="fas fa-print me-1"></i><b>Printer:</b> ${paEscapeHtml(data.printer_name || '-')}</div>
                <div><i class="fas fa-file-alt me-1"></i><b>Page data:</b> ${paEscapeHtml(pageData)}</div>
                ${msg ? '<div class="mt-1 '+(st === 'failed' ? 'text-danger' : 'text-muted')+'"><i class="fas fa-info-circle me-1"></i>'+paEscapeHtml(msg)+'</div>' : ''}
            </div>`;
        $('#paJobMonitor').show().html(html);
    }
    function paUpdateFileUiAfterRemoteDone(data, printInfo) {
        const fileSafe = $('#paFileSafe').val();
        const target = Math.max(1, parseInt(data.target || $('#paQtyTarget').val() || '1', 10));
        const current = Math.max(0, parseInt(data.current || '0', 10));
        const isDone = current >= target;
        const clientName = printInfo.client_name || data.client_name || '';
        const printerName = printInfo.printer_name || data.printer_name || '';
        const pageData = data.page_data || data.last_print_page_data || '';
        const infoHtml = '<div class="mt-1 small text-muted lh-sm" style="font-size:0.68rem;" id="print-info-'+fileSafe+'">'
            + '<span class="badge bg-success mb-1"><i class="fas fa-check-circle me-1"></i>Print berhasil</span><br>'
            + (clientName ? '<i class="fas fa-desktop me-1"></i>'+paEscapeHtml(clientName) : '')
            + (printerName ? '<br><i class="fas fa-print me-1"></i>'+paEscapeHtml(printerName) : '')
            + (pageData ? '<br><i class="fas fa-file-alt me-1"></i>'+paEscapeHtml(pageData).substring(0, 120) : '')
            + '<br><i class="fas fa-clock me-1"></i>Baru saja</div>';
        if (isDone) {
            $('#badge-file-'+fileSafe).html('<span class="badge bg-success ms-1 rounded-pill" style="font-size:0.7rem;">Selesai ('+current+'/'+target+')</span>' + infoHtml);
            if (paCurrentBtn) $(paCurrentBtn).removeClass('btn-dark btn-success disabled').addClass('btn-warning text-dark').attr('title','Reprint file ini').html('<i class="fas fa-redo"></i>');
        } else {
            $('#badge-file-'+fileSafe).html('<span class="badge bg-warning text-dark ms-1 rounded-pill" style="font-size:0.7rem;">Diprint ('+current+'/'+target+')</span>' + infoHtml);
        }
    }
    function paUpdateFileUiAfterRemoteFailed(data) {
        const fileSafe = $('#paFileSafe').val();
        const err = data.error_message || data.status_message || 'Print gagal di komputer client';
        const clientName = data.client_name || '';
        const printerName = data.printer_name || '';
        const infoHtml = '<div class="mt-1 small lh-sm text-danger" style="font-size:0.68rem;" id="print-info-'+fileSafe+'">'
            + '<span class="badge bg-danger mb-1"><i class="fas fa-times-circle me-1"></i>Print gagal</span><br>'
            + (clientName ? '<i class="fas fa-desktop me-1"></i>'+paEscapeHtml(clientName) : '')
            + (printerName ? '<br><i class="fas fa-print me-1"></i>'+paEscapeHtml(printerName) : '')
            + '<br><i class="fas fa-exclamation-triangle me-1"></i>'+paEscapeHtml(err).substring(0, 160) + '</div>';
        $('#badge-file-'+fileSafe).append(infoHtml);
    }
    function paMonitorJob(jobId, printPart, printIncrement, printInfo) {
        let tries = 0;
        let stopped = false;
        const pollOnce = function(){
            if (stopped) return;
            tries++;
            $.post('index.php', {action:'print_agent_job_status', job_id:jobId}, function(res){
                if(!res || res.status !== 'success') {
                    $('#paStatus').html('<span class="text-danger">Gagal membaca status job print.</span>');
                    if (tries < 180) setTimeout(pollOnce, 2000);
                    return;
                }
                paRenderJobMonitor(res);
                const st = String(res.job_status || '').toLowerCase();
                if(st === 'done') {
                    stopped = true;
                    if (typeof updateProgressBar === 'function') updateProgressBar(res.persen || 0);
                    if (typeof updateOrderStatusUI === 'function' && res.new_order_status) updateOrderStatusUI(res.new_order_status);
                    const btnLabelDone = paIsSplitBorderlessDuplex ? 'Print Semua' : 'Print Otomatis';
                    $('#paBtnSend').prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>' + btnLabelDone);
                    $('#paStatus').html('<span class="text-success"><i class="fas fa-check-circle me-1"></i>Print berhasil di komputer client.</span>');
                    if (printPart === 'even') {
                        paSetEvenPrinted(true);
                        updateFilePrintPhase($('#paFileSafe').val(), $('#paPathFile').val(), $('#paNota').val(), 'even');
                        $('#paStatus').append('<div class="mt-1 text-muted">Halaman genap berhasil diprint. Balik kertas, lalu klik <b>Print Ganjil</b>.</div>');
                    } else {
                        if (printPart === 'odd') { paSetEvenPrinted(false); }
                        paUpdateFileUiAfterRemoteDone(res, printInfo || {});
                        setTimeout(() => { $('#printAgentModal').modal('hide'); }, 1200);
                    }
                    return;
                }
                if(st === 'failed') {
                    stopped = true;
                    const btnLabelFail = paIsSplitBorderlessDuplex ? 'Print Semua' : 'Print Otomatis';
                    $('#paBtnSend').prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>' + btnLabelFail);
                    $('#paStatus').html('<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Print gagal di komputer client. File tidak ditandai selesai.</span>');
                    paUpdateFileUiAfterRemoteFailed(res);
                    return;
                }
                if (tries < 180) setTimeout(pollOnce, 2000);
                else $('#paStatus').html('<span class="text-warning">Job belum selesai. Cek komputer client / Print Agent.</span>');
            }, 'json').fail(function(xhr){
                $('#paStatus').html('<span class="text-danger">Gagal cek status job: '+xhr.status+'</span>');
                if (tries < 180) setTimeout(pollOnce, 3000);
            });
        };
        pollOnce();
    }
    function paSendJob(printPart = 'all') {
        const fileId = $('#paFileId').val();
        const clientId = $('#paClient').val();
        const printer = $('#paPrinter').val();
        if(!fileId || !clientId || !printer) {
            $('#paStatus').html('<span class="text-danger">Pilih client dan printer dulu.</span>');
            return;
        }
        $('#paBtnSend').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Mengirim...');
        const scaleMode = $('input[name=paScaleMode]:checked').val() || 'fit';
        const fitToPage = scaleMode === 'fit' ? 1 : 0;
        const customScale = parseInt($('#paCustomScale').val() || '100', 10);
        const orientation = $('#paOrientation').val() || 'auto';
        const printGrayscale = $('#paGrayscale').is(':checked') ? 1 : 0;
        const printerPaperType = paCleanPaperType($('#paPrinterPaperType').val() || paPreferredPrinterPaperType || 'plain');
        const paperSize = ($('#paPaperSize').val() || '').trim().toUpperCase();
        const remainingQty = Math.max(1, parseInt($('#paRemainingQty').val() || '1', 10));
        const copiesMode = ($('input[name=paCopiesMode]:checked').val() === 'single') ? 'single' : 'all_remaining';
        const printIncrement = (copiesMode === 'single') ? 1 : remainingQty;
        printPart = ['all','even','odd'].includes(printPart) ? printPart : 'all';
        // Split genap/ganjil hanya berlaku untuk mode 2 sisi + borderless.
        // Selain itu selalu print otomatis semua halaman.
        if (!paIsSplitBorderlessDuplex) {
            printPart = 'all';
        }
        const labelPart = printPart === 'even' ? 'halaman genap' : (printPart === 'odd' ? 'halaman ganjil' : 'semua halaman');
        const labelCopies = copiesMode === 'single' ? 'print satuan 1 qty' : ('print semua sisa ' + remainingQty + ' qty');
        $.post('index.php', {action:'print_agent_enqueue', file_id:fileId, client_id:clientId, printer_name:printer, paper_size:paperSize, fit_to_page:fitToPage, scale_mode:scaleMode, custom_scale:customScale, print_part:printPart, orientation:orientation, print_grayscale:printGrayscale, printer_paper_type:printerPaperType, copies_mode:copiesMode}, function(res){
            if(res && res.status === 'success') {
                $('#paStatus').html('<span class="text-primary"><i class="fas fa-spinner fa-spin me-1"></i>'+res.message+' ('+labelPart+', '+labelCopies+'). Menunggu hasil dari komputer client...</span>');
                $('#paJobMonitor').show().html('<div class="alert alert-light border rounded-3 small mb-0"><i class="fas fa-clock me-1"></i>Job #'+res.job_id+' sudah dikirim ke antrian. Menunggu Print Agent client mengambil job.</div>');
                if (printPart === 'even') {
                    updateFilePrintPhase($('#paFileSafe').val(), $('#paPathFile').val(), $('#paNota').val(), 'even');
                }
                paMonitorJob(res.job_id, printPart, printIncrement, res);
            } else {
                $('#paStatus').html('<span class="text-danger">'+(res.message || 'Gagal mengirim job print')+'</span>');
            }
        }, 'json').fail(function(xhr){
            $('#paStatus').html('<span class="text-danger">Gagal AJAX: '+xhr.status+'</span>');
        }).always(function(){
            if (!$('#paJobMonitor').is(':visible')) {
                const btnLabel = paIsSplitBorderlessDuplex ? 'Print Semua' : 'Print Otomatis';
                $('#paBtnSend').prop('disabled', false).html('<i class="fas fa-paper-plane me-1"></i>' + btnLabel);
            }
        });
    }
$(document).on('input change', '#paCustomScale', function(){ $('#paScaleCustom').prop('checked', true); });


    // Sementara: jika operator download file, file langsung ditandai sudah terprint.
    document.addEventListener('click', function(e){
        const link = e.target.closest('.btn-download-mark');
        if(!link) return;
        const fileId = link.getAttribute('data-file-id') || '';
        const fileSafe = link.getAttribute('data-file-safe') || '';
        const nota = link.getAttribute('data-nota') || '';
        const target = parseInt(link.getAttribute('data-target') || '1', 10) || 1;
        try {
            const body = new URLSearchParams();
            body.append('action', 'download_mark_printed');
            body.append('file_id', fileId);
            body.append('no_penjualan', nota);
            fetch('index.php', { method:'POST', body:body, keepalive:true, credentials:'same-origin' })
                .then(r => r.json()).then(res => {
                    if(res && res.status === 'success') {
                        updateProgressBar(res.persen || 0);
                        updateOrderStatusUI(res.new_order_status || 'Proses');
                        $('#badge-file-'+fileSafe).html('<span class="badge bg-success ms-1 rounded-pill" style="font-size:0.7rem;">Selesai ('+target+'/'+target+')</span><div class="mt-1 small text-muted lh-sm" style="font-size:0.68rem;"><i class="fas fa-download me-1"></i>Download Manual<br><i class="fas fa-clock me-1"></i>Baru saja</div>');
                    }
                }).catch(()=>{});
        } catch(err) {}
        // Jangan preventDefault agar download tetap jalan.
    }, true);
</script>


<!-- Modal Print Agent -->
<div class="modal fade" id="printAgentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-dark text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fas fa-print me-2"></i>Print Langsung ke Komputer/Printer</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="paFileId">
        <input type="hidden" id="paNota">
        <input type="hidden" id="paFileSafe">
        <input type="hidden" id="paPathFile">
        <input type="hidden" id="paFinishing">
        <input type="hidden" id="paOrientation" value="auto">
        <input type="hidden" id="paQtyTarget" value="1">
        <input type="hidden" id="paPrintedCount" value="0">
        <input type="hidden" id="paRemainingQty" value="1">
        <input type="hidden" id="paPageCount" value="0">
        <input type="hidden" id="paPageRange" value="All">
        <div class="alert alert-info small mb-3">
          Pilih komputer dan printer yang aktif. Jika Print Agent berjalan di komputer yang sedang dipakai, sistem akan memilihnya otomatis.
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">File</label>
          <div class="form-control bg-light" id="paFileName">-</div>
          <div class="form-text">Orientasi dari Print Analyzer: <b id="paOrientationLabel">Portrait</b></div>
          <div class="form-text">Qty: <b id="paQtyInfo">1 qty total, sudah diprint 0, sisa 1</b></div>
          <div class="form-text">Total halaman: <span id="paPageCountInfo">Belum tersedia</span></div>
          <div class="form-text">Paper Type dari Profile Harga: <b id="paPrinterPaperTypeInfo">Plain Paper</b></div>
        </div>
        <div class="mb-3 p-3 bg-light rounded-3 border">
          <label class="form-label fw-bold" for="paPaperSize"><i class="fas fa-file-alt me-1"></i>Paper Size</label>
          <select id="paPaperSize" class="form-select" onchange="$('#paPaperSizeInfo').text(this.value || '-')">
            <option value="">Ikuti ukuran file/order</option>
            <option value="A3">A3</option>
            <option value="A4">A4</option>
            <option value="A5">A5</option>
            <option value="F4">F4</option>
            <option value="B5">B5</option>
            <option value="LETTER">Letter</option>
            <option value="LEGAL">Legal</option>
          </select>
          <div class="form-text">Default dari order: <b id="paPaperSizeInfo">-</b>. Bisa diubah sebelum dikirim ke Print Klien.</div>
        </div>
        <div id="paCopiesBox" class="mb-3 p-3 bg-light rounded-3 border" style="display:none;">
          <div class="fw-bold mb-2"><i class="fas fa-copy me-1"></i>Pilihan Qty Print</div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="paCopiesMode" id="paCopiesAll" value="all_remaining" checked>
            <label class="form-check-label" for="paCopiesAll" id="paCopiesAllLabel">Print semua sisa qty</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="paCopiesMode" id="paCopiesSingle" value="single">
            <label class="form-check-label" for="paCopiesSingle" id="paCopiesSingleLabel">Print satuan saja (1 lembar/copy)</label>
          </div>
          <div class="form-text mt-2">Dipakai saat qty file lebih dari satu. Pilih satuan jika hanya ingin test print / cetak 1 lembar dulu.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Komputer Client</label>
          <select id="paClient" class="form-select" onchange="paRenderPrinters()">
            <option value="">Memuat client...</option>
          </select>
          <div class="form-text">Client dianggap aktif jika agent melakukan heartbeat dalam 2 menit terakhir.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-bold">Printer</label>
          <select id="paPrinter" class="form-select" onchange="paRenderPaperTypes()">
            <option value="">Pilih printer...</option>
          </select>
          <div class="form-text" id="paDefaultPrinterHint">Default printer akan mengikuti default printer Windows di komputer client.</div>
        </div>
        <div class="form-check form-switch mb-3 p-3 bg-light rounded-3 border">
          <input class="form-check-input" type="checkbox" id="paGrayscale" value="1">
          <label class="form-check-label fw-bold" for="paGrayscale"><i class="fas fa-adjust me-1"></i>Print Grayscale</label>
          <div class="form-text">Jika dari Print Analyzer dicentang Grayscale, pilihan ini otomatis aktif. Bisa dimatikan/diaktifkan ulang sebelum kirim ke Print Agent.</div>
        </div>

        <div class="mb-3 p-3 bg-light rounded-3 border">
          <label class="form-label fw-bold" for="paPrinterPaperType"><i class="fas fa-layer-group me-1"></i>Paper Type</label>
          <select id="paPrinterPaperType" class="form-select" onchange="$('#paPrinterPaperTypeInfo').text(paPaperTypeLabel(this.value));">
            <option value="plain">Plain Paper</option>
            <option value="photo_glossy">Photo Paper Glossy</option>
          </select>
          <div class="form-text" id="paPrinterPaperTypeHint">Otomatis mengikuti Profile Harga, lalu disesuaikan dengan daftar Paper Type printer client jika tersedia.</div>
        </div>
        <div class="mb-3 p-3 bg-light rounded-3 border">
          <div class="fw-bold mb-2"><i class="fas fa-expand-arrows-alt me-1"></i>Skala Cetak</div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="paScaleMode" id="paScaleFit" value="fit" checked>
            <label class="form-check-label" for="paScaleFit">Fit to Page / sesuaikan kertas</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="paScaleMode" id="paScaleNo" value="noscale">
            <label class="form-check-label" for="paScaleNo">Actual Size / ukuran asli</label>
          </div>
          <div class="form-check d-flex align-items-center gap-2 flex-wrap">
            <input class="form-check-input" type="radio" name="paScaleMode" id="paScaleCustom" value="custom">
            <label class="form-check-label" for="paScaleCustom">Custom Scale</label>
            <div class="input-group input-group-sm" style="width:120px;">
              <input type="number" min="10" max="400" step="1" class="form-control" id="paCustomScale" value="100">
              <span class="input-group-text">%</span>
            </div>
          </div>
          <div class="form-text mt-2">Contoh: 95% untuk diperkecil sedikit, 100% ukuran normal. Jika printer/SumatraPDF tidak mendukung custom scale, gunakan Fit to Page atau Actual Size.</div>
        </div>
        <div id="paSplitBox" class="mb-3 p-3 rounded-3 border border-warning bg-warning bg-opacity-10" style="display:none;">
          <div class="fw-bold text-dark mb-1"><i class="fas fa-layer-group me-1"></i>Print 2 Sisi Borderless Bertahap</div>
          <div id="paSplitInfo" class="small mb-2"></div>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" id="paBtnPrintEven" class="btn btn-outline-dark btn-sm fw-bold" onclick="paSendJob('even')">
              <i class="fas fa-print me-1"></i>Print Genap
            </button>
            <button type="button" id="paBtnPrintOdd" class="btn btn-outline-dark btn-sm fw-bold" onclick="paSendJob('odd')">
              <i class="fas fa-print me-1"></i>Print Ganjil
            </button>
          </div>
          <div id="paEvenDoneNotice" class="mt-2"></div>
          <div class="form-text mt-2">Setelah <b>Print Genap</b> berhasil dikirim, tombolnya akan berubah menjadi centang agar operator tahu tahap genap sudah selesai. Tombol genap/ganjil hanya muncul untuk borderless 2 sisi.</div>
        </div>
        <div id="paStatus" class="small text-muted"></div>
        <div id="paJobMonitor" class="mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary fw-bold" onclick="paSendJob('all')" id="paBtnSend"><i class="fas fa-paper-plane me-1"></i>Print Otomatis</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>