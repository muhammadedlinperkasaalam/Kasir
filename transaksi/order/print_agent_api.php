<?php
// API untuk Addinta Print Agent client.
// Endpoint dipanggil oleh aplikasi agent di komputer klien.
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/database.php';
date_default_timezone_set('Asia/Jakarta');

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data ?: [];
}
function ensure_tables(PDO $pdo): void {
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
    try { $pdo->query("SELECT last_print_client_id FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_client_id INT NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_client_name FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_client_name VARCHAR(150) NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_printer_name FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_printer_name VARCHAR(255) NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_job_id FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_job_id INT NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_at FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_at DATETIME NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_status FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_status VARCHAR(30) NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_error FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_error TEXT NULL"); } catch (Exception $x) {} }
    try { $pdo->query("SELECT last_print_page_data FROM order_files LIMIT 1"); } catch (Exception $e) { try { $pdo->exec("ALTER TABLE order_files ADD COLUMN last_print_page_data TEXT NULL"); } catch (Exception $x) {} }
}

function order_view_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($scheme . '://' . $host . $script, '/');
}

function update_order_progress(PDO $pdo, string $noPenjualan): array {
    if ($noPenjualan === '') return ['persen'=>0,'status'=>'Proses'];
    $t = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_task = 'Selesai' THEN 1 ELSE 0 END) as selesai FROM order_tasks WHERE no_penjualan = ?");
    $t->execute([$noPenjualan]);
    $dt = $t->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
    $f = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status_baca = 'Sudah' THEN 1 ELSE 0 END) as selesai FROM order_files WHERE no_penjualan = ?");
    $f->execute([$noPenjualan]);
    $df = $f->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'selesai'=>0];
    $tot = (int)($dt['total'] ?? 0) + (int)($df['total'] ?? 0);
    $done = (int)($dt['selesai'] ?? 0) + (int)($df['selesai'] ?? 0);
    $pct = ($tot > 0) ? (int)round(($done / $tot) * 100) : 0;
    $newStatus = ($pct >= 100) ? 'Selesai' : 'Proses';
    $cek = $pdo->prepare("SELECT no_penjualan FROM order_pekerjaan WHERE no_penjualan = ?");
    $cek->execute([$noPenjualan]);
    if ($cek->rowCount() > 0) {
        $pdo->prepare("UPDATE order_pekerjaan SET status_order = ? WHERE no_penjualan = ?")->execute([$newStatus, $noPenjualan]);
    } else {
        $pdo->prepare("INSERT INTO order_pekerjaan (no_penjualan, status_order) VALUES (?, ?)")->execute([$noPenjualan, $newStatus]);
    }
    return ['persen'=>$pct,'status'=>$newStatus];
}

function mark_order_file_from_job(PDO $pdo, array $job, int $clientId, string $clientName, string $status, string $pageData, string $err): void {
    $fileId = (int)($job['order_file_id'] ?? 0);
    if ($fileId <= 0) return;
    $printer = (string)($job['printer_name'] ?? '');
    $jobId = (int)($job['id'] ?? 0);
    if ($status === 'processing') {
        $pdo->prepare("UPDATE order_files SET last_print_client_id=?, last_print_client_name=?, last_print_printer_name=?, last_print_job_id=?, last_print_at=NOW(), last_print_status='processing', last_print_error=NULL, last_print_page_data=? WHERE id=?")
            ->execute([$clientId, $clientName, $printer, $jobId, $pageData, $fileId]);
        return;
    }
    if ($status === 'failed') {
        $pdo->prepare("UPDATE order_files SET last_print_client_id=?, last_print_client_name=?, last_print_printer_name=?, last_print_job_id=?, last_print_at=NOW(), last_print_status='failed', last_print_error=?, last_print_page_data=? WHERE id=?")
            ->execute([$clientId, $clientName, $printer, $jobId, $err, $pageData, $fileId]);
        return;
    }
    if ($status !== 'done') return;
    $part = strtolower((string)($job['print_part'] ?? 'all'));
    if ($part === 'even') {
        $pdo->prepare("UPDATE order_files SET status_baca='Proses Print Halaman Genap', last_print_client_id=?, last_print_client_name=?, last_print_printer_name=?, last_print_job_id=?, last_print_at=NOW(), last_print_status='done', last_print_error=NULL, last_print_page_data=? WHERE id=?")
            ->execute([$clientId, $clientName, $printer, $jobId, $pageData, $fileId]);
        return;
    }
    $copies = max(1, (int)($job['copies'] ?? 1));
    $get = $pdo->prepare("SELECT id, no_penjualan, qty_cetak, printed_count FROM order_files WHERE id=? LIMIT 1");
    $get->execute([$fileId]);
    $of = $get->fetch(PDO::FETCH_ASSOC);
    if (!$of) return;
    $target = max(1, (int)($of['qty_cetak'] ?? 1));
    $curr = min($target, max(0, (int)($of['printed_count'] ?? 0)) + $copies);
    $fileStatus = ($curr >= $target) ? 'Sudah' : 'Belum';
    $pdo->prepare("UPDATE order_files SET printed_count=?, status_baca=?, last_print_client_id=?, last_print_client_name=?, last_print_printer_name=?, last_print_job_id=?, last_print_at=NOW(), last_print_status='done', last_print_error=NULL, last_print_page_data=? WHERE id=?")
        ->execute([$curr, $fileStatus, $clientId, $clientName, $printer, $jobId, $pageData, $fileId]);
    update_order_progress($pdo, (string)($of['no_penjualan'] ?? ''));
}

function require_token(array $data): string {
    $token = trim($data['client_token'] ?? $_GET['client_token'] ?? '');
    if ($token === '') throw new Exception('client_token kosong');
    return $token;
}

try {
    ensure_tables($pdo);
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
    $data = json_input();

    if ($action === 'register' || $action === 'heartbeat') {
        $token = require_token($data);
        $clientName = trim($data['client_name'] ?? gethostname() ?: 'Client');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $printers = $data['printers'] ?? [];
        if (!is_array($printers)) $printers = [];

        $stmt = $pdo->prepare("INSERT INTO print_clients (client_token, client_name, ip_address, last_seen, is_active)
                               VALUES (?, ?, ?, NOW(), 1)
                               ON DUPLICATE KEY UPDATE client_name=VALUES(client_name), ip_address=VALUES(ip_address), last_seen=NOW(), is_active=1");
        $stmt->execute([$token, $clientName, $ip]);
        $cid = (int)$pdo->query("SELECT id FROM print_clients WHERE client_token=" . $pdo->quote($token))->fetchColumn();

        if (!empty($printers)) {
            $pdo->prepare("UPDATE print_client_printers SET is_active=0 WHERE client_id=?")->execute([$cid]);
            $ins = $pdo->prepare("INSERT INTO print_client_printers (client_id, printer_name, is_default, is_active, media_types_json)
                                  VALUES (?, ?, ?, 1, ?)
                                  ON DUPLICATE KEY UPDATE is_default=VALUES(is_default), is_active=1, media_types_json=VALUES(media_types_json)");
            foreach ($printers as $p) {
                $name = trim(is_array($p) ? ($p['name'] ?? '') : (string)$p);
                if ($name === '') continue;
                $def = (int)(is_array($p) ? !empty($p['is_default']) : 0);
                $media = [];
                if (is_array($p) && isset($p['paper_types']) && is_array($p['paper_types'])) $media = $p['paper_types'];
                elseif (is_array($p) && isset($p['media_types']) && is_array($p['media_types'])) $media = $p['media_types'];
                $safeMedia = [];
                foreach ($media as $m) {
                    $value = trim((string)(is_array($m) ? ($m['value'] ?? $m['name'] ?? $m['label'] ?? '') : $m));
                    $label = trim((string)(is_array($m) ? ($m['label'] ?? $value) : $value));
                    $value = preg_replace('/[^a-zA-Z0-9 _\-.\/()+]/', '', $value);
                    $label = preg_replace('/[^a-zA-Z0-9 _\-.\/()+]/', '', $label);
                    if ($value === '') continue;
                    $safeMedia[] = ['value'=>substr($value,0,120), 'label'=>substr($label ?: $value,0,160)];
                }
                $ins->execute([$cid, $name, $def, json_encode($safeMedia, JSON_UNESCAPED_UNICODE)]);
            }
        }
        echo json_encode(['status'=>'success','client_id'=>$cid,'server_time'=>date('Y-m-d H:i:s')]);
        exit;
    }

    if ($action === 'poll') {
        $token = require_token($data);
        $c = $pdo->prepare("SELECT id FROM print_clients WHERE client_token=? AND is_active=1 LIMIT 1");
        $c->execute([$token]);
        $cid = (int)$c->fetchColumn();
        if (!$cid) throw new Exception('Client belum terdaftar');
        $pdo->prepare("UPDATE print_clients SET last_seen=NOW(), ip_address=? WHERE id=?")->execute([$_SERVER['REMOTE_ADDR'] ?? '', $cid]);
        $stmt = $pdo->prepare("SELECT j.*, of.ukuran_kertas AS order_paper_size
                               FROM print_agent_jobs j
                               LEFT JOIN order_files of ON of.id = j.order_file_id
                               WHERE j.client_id=? AND j.status='pending'
                               ORDER BY j.id ASC LIMIT 5");
        $stmt->execute([$cid]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $base = order_view_base_url();
        foreach ($jobs as &$job) {
            $ps = strtoupper(trim((string)($job['paper_size'] ?? '')));
            if ($ps === '' || $ps === 'IKUTI' || $ps === 'AUTO') {
                $ps = strtoupper(trim((string)($job['order_paper_size'] ?? '')));
            }
            $ps = preg_replace('/[^A-Z0-9+ _.-]/', '', $ps);
            $job['target_paper_size'] = substr($ps, 0, 50);
            if (empty($job['view_token'])) {
                $job['view_token'] = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE print_agent_jobs SET view_token=? WHERE id=?")->execute([$job['view_token'], $job['id']]);
            }
            $job['order_view_url'] = $base . '/client_order_view.php?job_id=' . urlencode((string)$job['id']) . '&token=' . urlencode((string)$job['view_token']);
            $createdIp = trim((string)($job['created_by_ip'] ?? ''));
            $job['is_remote_job'] = ($createdIp !== '' && $clientIp !== '' && $createdIp !== $clientIp) ? 1 : 0;
        }
        unset($job);
        echo json_encode(['status'=>'success','jobs'=>$jobs]);
        exit;
    }

    if ($action === 'status') {
        $token = require_token($data);
        $jobId = (int)($data['job_id'] ?? 0);
        $status = trim($data['status'] ?? '');
        $err = trim((string)($data['error_message'] ?? ''));
        $pageData = trim((string)($data['page_data'] ?? $data['printed_pages'] ?? ''));
        $statusMessage = trim((string)($data['status_message'] ?? ''));
        if (!$jobId || !in_array($status, ['processing','done','failed'], true)) throw new Exception('Status job tidak valid');
        $c = $pdo->prepare("SELECT id, client_name FROM print_clients WHERE client_token=? LIMIT 1");
        $c->execute([$token]);
        $clientRow = $c->fetch(PDO::FETCH_ASSOC);
        $cid = (int)($clientRow['id'] ?? 0);
        $clientName = trim((string)($clientRow['client_name'] ?? ''));
        if (!$cid) throw new Exception('Client belum terdaftar');

        $j = $pdo->prepare("SELECT * FROM print_agent_jobs WHERE id=? AND client_id=? LIMIT 1");
        $j->execute([$jobId, $cid]);
        $job = $j->fetch(PDO::FETCH_ASSOC);
        if (!$job) throw new Exception('Job tidak ditemukan untuk client ini');
        $prevStatus = strtolower((string)($job['status'] ?? 'pending'));
        if ($pageData === '') $pageData = (string)($job['page_data'] ?? '');
        if ($statusMessage === '') {
            $statusMessage = $status === 'processing' ? 'Job sedang diproses di komputer client.' : ($status === 'done' ? 'Print berhasil diproses oleh komputer client.' : 'Print gagal di komputer client.');
        }

        if ($status === 'processing') {
            $q = $pdo->prepare("UPDATE print_agent_jobs SET status='processing', started_at=IFNULL(started_at, NOW()), error_message=NULL, page_data=NULLIF(?,''), status_message=NULLIF(?,''), updated_at=NOW() WHERE id=? AND client_id=?");
            $q->execute([$pageData, $statusMessage, $jobId, $cid]);
            mark_order_file_from_job($pdo, $job, $cid, $clientName, 'processing', $pageData, '');
        } elseif ($status === 'done') {
            $q = $pdo->prepare("UPDATE print_agent_jobs SET status='done', finished_at=NOW(), error_message=NULL, page_data=NULLIF(?,''), status_message=NULLIF(?,''), updated_at=NOW() WHERE id=? AND client_id=?");
            $q->execute([$pageData, $statusMessage, $jobId, $cid]);
            if ($prevStatus !== 'done') {
                mark_order_file_from_job($pdo, $job, $cid, $clientName, 'done', $pageData, '');
            }
        } else {
            $q = $pdo->prepare("UPDATE print_agent_jobs SET status='failed', finished_at=NOW(), error_message=?, page_data=NULLIF(?,''), status_message=NULLIF(?,''), updated_at=NOW() WHERE id=? AND client_id=?");
            $q->execute([$err, $pageData, $statusMessage, $jobId, $cid]);
            mark_order_file_from_job($pdo, $job, $cid, $clientName, 'failed', $pageData, $err);
        }
        echo json_encode(['status'=>'success']);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Action tidak dikenal']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
