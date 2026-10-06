<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- PESAN NOTIFIKASI ---
$pesan_error = "";
$pesan_sukses = "";

// =========================================================================
// 1. LOGIKA UPDATE KE QRIS (AJAX) - AMAN UNTUK CICILAN & SPLIT PAYMENT
// =========================================================================
if (isset($_POST['ajax_update_qris'])) {
    ob_clean(); 
    header('Content-Type: application/json');
    $ids_to_update = explode(',', $_POST['target_ids']); 
    $tgl_verif = isset($_POST['tgl_verif']) ? $_POST['tgl_verif'] : date('Y-m-d');
    $count = 0;
    
    foreach ($ids_to_update as $target) {
        $target = trim($target);
        if (empty($target)) continue;
        
        list($sumber, $id_faktur) = explode('|', $target);

        if ($sumber == 'penjualan') {
            $stmtGet = $pdo->prepare("SELECT keterangan, nominal_deposit, metode_pembayaran,
                CASE WHEN total_omzet > 0 THEN total_omzet ELSE (SELECT COALESCE(SUM(subtotal), 0) FROM penjualan_item WHERE no_penjualan = p.no_penjualan) END as nilai_real
                FROM penjualan p WHERE no_penjualan = ?");
            $stmtGet->execute([$id_faktur]);
            $nota = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($nota) {
                $ket_baru = isset($nota['keterangan']) ? $nota['keterangan'] : '';
                $metode_lama = strtolower(trim($nota['metode_pembayaran'] ?? ''));
                
                // Kalkulasi Deposit yang benar
                $nominal_deposit = (float)($nota['nominal_deposit'] ?? 0);
                if ($nominal_deposit == 0 && stripos($ket_baru, 'depo') !== false) {
                    if (preg_match('/(?:Depo|Deposit).*?([0-9.,]+)/i', $ket_baru, $md)) {
                        $nominal_deposit = (float)str_replace(['.', ','], ['', '.'], $md[1]);
                    } elseif (strtolower(trim($ket_baru)) == 'deposit') {
                        $nominal_deposit = (float)$nota['nilai_real'];
                    }
                }
                
                $uang_lunas = (float)$nota['nilai_real'] - $nominal_deposit;

                if (strpos($ket_baru, '[Verif:') === false) $ket_baru .= " [Verif:QRIS]";

                // Update penjualan
                $sql_upd = "UPDATE penjualan SET keterangan = ?, is_verif_qris = 'Y', tgl_verif_qris = ?, uang_bayar = ?, pelunasan = 'Y' WHERE no_penjualan = ?";
                $pdo->prepare($sql_upd)->execute([$ket_baru, $tgl_verif, $uang_lunas, $id_faktur]);
                
                $pdo->prepare("UPDATE penjualan_item SET pelunasan = 'Y' WHERE no_penjualan = ?")->execute([$id_faktur]);

                // Insert ke arus kas
                if ($uang_lunas > 0) {
                    $ket_arus_kas = "Pelunasan Otomatis via Verifikasi [Verif:QRIS]";
                    $cek_ak = $pdo->prepare("SELECT id FROM arus_kas WHERE no_penjualan = ? AND tanggal = ? AND jumlah_masuk = ?");
                    $cek_ak->execute([$id_faktur, $tgl_verif, $uang_lunas]);
                    if ($cek_ak->rowCount() == 0) {
                        $ins_ak = $pdo->prepare("INSERT INTO arus_kas (tanggal, jenis, keterangan, no_penjualan, metode_pembayaran, jumlah_masuk, jumlah_keluar, kode_user, created_at) VALUES (?, 'Pemasukan', ?, ?, 'QRIS', ?, 0, ?, NOW())");
                        $user_log = isset($_SESSION['kode_user']) ? $_SESSION['kode_user'] : 'SYSTEM';
                        $ins_ak->execute([$tgl_verif, $ket_arus_kas, $id_faktur, $uang_lunas, $user_log]);
                    }
                }
                $count++;
            }
        } else if ($sumber == 'arus_kas') {
            $stmtGet = $pdo->prepare("SELECT no_penjualan, keterangan FROM arus_kas WHERE id = ?");
            $stmtGet->execute([$id_faktur]);
            $row = $stmtGet->fetch();
            
            if ($row) {
                $no_penjualan_parent = $row['no_penjualan'];
                $ket_baru = isset($row['keterangan']) ? $row['keterangan'] : '';
                
                if (strpos($ket_baru, '[Verif:') === false) $ket_baru .= " [Verif:QRIS]";

                $pdo->prepare("UPDATE arus_kas SET keterangan = ? WHERE id = ?")
                    ->execute([$ket_baru, $id_faktur]);

                $pdo->prepare("UPDATE penjualan SET is_verif_qris = 'Y', tgl_verif_qris = ? WHERE no_penjualan = ?")
                    ->execute([$tgl_verif, $no_penjualan_parent]);
                    
                $count++;
            }
        }
    }
    
    echo json_encode(['status' => 'success', 'message' => "Berhasil! $count Transaksi diverifikasi keabsahan QRIS-nya."]);
    exit;
}

$laporan = [];
$stat = ['match' => 0, 'combined' => 0, 'missing' => 0];
$used_db_ids = []; 
$total_nominal_csv = 0;
$total_nominal_db = 0;
$default_tgl = isset($_POST['tgl_verifikasi']) ? $_POST['tgl_verifikasi'] : date('Y-m-d');

// --- HELPERS ---
function cleanNum($str) {
    $str = preg_replace('/[^0-9,.-]/', '', $str); 
    if (strpos($str, ',') !== false && strpos($str, '.') !== false) {
        if (strpos($str, '.') < strpos($str, ',')) { 
            $str = str_replace('.', '', $str); $str = str_replace(',', '.', $str);
        } else { 
            $str = str_replace(',', '', $str); 
        }
    } elseif (strpos($str, ',') !== false) {
        if (strlen(substr($str, strpos($str, ',') + 1)) == 2) $str = str_replace(',', '.', $str); 
        else $str = str_replace(',', '', $str); 
    }
    return (float)$str;
}

function cleanID($str) { return trim($str, " \t\n\r\0\x0B'\"="); }

function findCombinationOnly($items, $target) {
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $sum = $items[$i]['val'] + $items[$j]['val'];
            if (abs($sum - $target) < 10) return [$items[$i], $items[$j]];
        }
    }
    if ($count <= 25) { 
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $sum = $items[$i]['val'] + $items[$j]['val'] + $items[$k]['val'];
                    if (abs($sum - $target) < 10) return [$items[$i], $items[$j], $items[$k]];
                }
            }
        }
    }
    return null;
}

// --- PROSES UPLOAD ---
if (isset($_POST['upload'])) {
    
    if (isset($_FILES['file_csv']['tmp_name']) && is_uploaded_file($_FILES['file_csv']['tmp_name'])) {
        $handle = fopen($_FILES['file_csv']['tmp_name'], "r");
    } else {
        $handle = FALSE; $pesan_error = "Gagal upload file. Pastikan format CSV valid.";
    }

    if ($handle) {
        $header = fgetcsv($handle, 2000, ","); 
        
        $idx_id = 4; $idx_tgl = 1; $idx_status = 3; $idx_val = 6; $idx_promo = 8; 

        if ($header) {
            foreach ($header as $i => $col) {
                $col = strtolower(trim($col));
                if (in_array($col, ['no. transaksi', 'transaction id', 'id', 'no. ref', 'no referensi'])) $idx_id = $i;
                if (in_array($col, ['harga', 'amount', 'gross amount', 'nilai'])) $idx_val = $i;
                if (in_array($col, ['transaksi dibuat', 'date', 'tanggal', 'waktu'])) $idx_tgl = $i;
                if (in_array($col, ['status', 'status transaksi'])) $idx_status = $i;
                if (in_array($col, ['pengeluaran promo', 'promo', 'discount'])) $idx_promo = $i;
            }
        }

        $global_date = $_POST['tgl_verifikasi'];

        // =========================================================================
        // PULLING DATA TUNGGAL
        // =========================================================================
        $kandidat_global = []; 
        $id_nota_di_arus_kas = []; 

        // 1. Tarik dari Arus Kas
        $sql_ak = "SELECT ak.id as id_ak, ak.no_penjualan, ak.jumlah_masuk, ak.metode_pembayaran as metode_ak, 
                          p.pelunasan, p.is_verif_qris, p.tgl_verif_qris, pl.nama_pelanggan, pl.kode_pelanggan,
                          'arus_kas' AS sumber, ak.tanggal as tgl_trx, ak.keterangan as ket_ak
                   FROM arus_kas ak
                   JOIN penjualan p ON ak.no_penjualan = p.no_penjualan
                   LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                   WHERE ak.tanggal = ? AND ak.jenis = 'Pemasukan'";
        $stmt_ak = $pdo->prepare($sql_ak);
        $stmt_ak->execute([$global_date]);
        
        while ($row = $stmt_ak->fetch(PDO::FETCH_ASSOC)) {
            $metode_cek = strtolower(trim($row['metode_ak'] ?? ''));
            if ($metode_cek === 'cash' || $metode_cek === 'tunai') continue;

            $is_verif = ($row['is_verif_qris'] == 'Y' || strpos($row['ket_ak'] ?? '', '[Verif:') !== false) ? 'Y' : 'N';
            $row['is_verif_qris'] = $is_verif;

            $real_id = $row['no_penjualan'];
            $kandidat_id = 'AK_' . $row['id_ak'];
            
            $kandidat_global[$kandidat_id] = [
                'id' => $real_id,
                'kandidat_id' => $kandidat_id,
                'val' => (float)$row['jumlah_masuk'],
                'data' => $row,
                'tipe' => ($row['pelunasan'] == 'N' && $is_verif == 'N') ? 'CICILAN_PIUTANG' : 'NOTA_CASH',
                'sumber' => 'arus_kas',
                'tgl_kandidat' => $row['tgl_trx']
            ];
            $id_nota_di_arus_kas[] = $real_id;
        }

        // 2. Tarik dari Penjualan 
        $sql_pj = "SELECT p.*, pl.nama_pelanggan, pl.kode_pelanggan, 'penjualan' AS sumber,
                          CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END as nilai_transaksi_real
                   FROM penjualan p
                   LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                   WHERE (p.tgl_penjualan = ? OR p.tgl_verif_qris = ? OR p.pelunasan = 'N')";
        $stmt_pj = $pdo->prepare($sql_pj);
        $stmt_pj->execute([$global_date, $global_date]);
        $penjualan_raw = $stmt_pj->fetchAll(PDO::FETCH_ASSOC);

        foreach ($penjualan_raw as $row) {
            $real_id = $row['no_penjualan'];
            $kandidat_id = 'PJ_' . $real_id;

            $metode_cek = strtolower(trim($row['metode_pembayaran'] ?? ''));
            // HANYA BLOKIR CASH/TUNAI. UTANG DIIZINKAN MASUK.
            if ($metode_cek === 'cash' || $metode_cek === 'tunai') continue;

            $grand_total = (float)$row['nilai_transaksi_real'];
            $dp = (float)$row['uang_bayar'];

            $nominal_deposit = (float)($row['nominal_deposit'] ?? 0);
            if ($nominal_deposit == 0 && stripos($row['keterangan'] ?? '', 'depo') !== false) {
                if (preg_match('/(?:Depo|Deposit).*?([0-9.,]+)/i', $row['keterangan'], $md)) {
                    $nominal_deposit = (float)str_replace(['.', ','], ['', '.'], $md[1]);
                } elseif (strtolower(trim($row['keterangan'])) == 'deposit') {
                    $nominal_deposit = $grand_total;
                }
            }

            $is_verif_qris = ($row['is_verif_qris'] === 'Y');
            $tgl_qris = $row['tgl_verif_qris'] ?: $row['tgl_penjualan'];

            if (!$is_verif_qris && strpos($row['keterangan'] ?? '', '[Verif:QRIS') !== false) {
                $is_verif_qris = true;
                if (preg_match('/\[Verif:QRIS(?::(.*?))?\]/i', $row['keterangan'], $vq)) {
                    $tgl_qris = !empty($vq[1]) ? trim($vq[1]) : $row['tgl_penjualan'];
                }
            }
            $row['is_verif_qris'] = $is_verif_qris ? 'Y' : 'N';

            $val_to_use = 0;
            $tipe_kandidat = 'NOTA_BARU';
            $tgl_kandidat = $row['tgl_penjualan'];

            if ($is_verif_qris) {
                $val_to_use = $grand_total - $nominal_deposit; 
                $tgl_kandidat = $tgl_qris; 
                $tipe_kandidat = 'NOTA_BARU';
            } else {
                if ($row['pelunasan'] == 'N') {
                    $uang_pelunasan_utang = (float)($row['uang_pelunasan_utang'] ?? 0);
                    $sisa_tagihan = $grand_total - $nominal_deposit - $dp - $uang_pelunasan_utang;
                    
                    if ($sisa_tagihan > 100) { 
                        $row['pelunasan'] = 'N'; 
                        $val_to_use = $sisa_tagihan;
                        $tipe_kandidat = 'SISA_UTANG';
                    } else {
                        $row['pelunasan'] = 'Y';
                        $val_to_use = $dp;
                    }
                } else {
                    $val_to_use = $dp;
                }
            }

            if (in_array($real_id, $id_nota_di_arus_kas) && $tipe_kandidat != 'SISA_UTANG') {
                continue; 
            }

            if ($val_to_use > 0) {
                $row['nilai_real'] = $grand_total; 
                $kandidat_global[$kandidat_id] = [
                    'id' => $real_id,
                    'kandidat_id' => $kandidat_id,
                    'val' => $val_to_use,
                    'data' => $row,
                    'tipe' => $tipe_kandidat,
                    'sumber' => 'penjualan',
                    'tgl_kandidat' => $tgl_kandidat
                ];
            }
        }

        // =========================================================================
        // PROSES PENCOCOKAN DENGAN CSV
        // =========================================================================
        $csv_rows = [];
        while (($row = fgetcsv($handle, 2000, ",")) !== FALSE) {
            if (count($row) < 3) continue;

            if ($idx_status >= 0 && isset($row[$idx_status])) {
                $st = strtolower(trim($row[$idx_status]));
                if (in_array($st, ['gagal', 'failed', 'expired', 'batal', 'cancelled'])) continue;
            }

            $csv_id = isset($row[$idx_id]) ? cleanID($row[$idx_id]) : 'N/A';
            $raw_price = isset($row[$idx_val]) ? cleanNum($row[$idx_val]) : 0;
            $promo_val = ($idx_promo >= 0 && isset($row[$idx_promo])) ? cleanNum($row[$idx_promo]) : 0;
            
            $csv_val = $raw_price - $promo_val;
            if ($csv_val <= 0) continue; 
            
            $raw_tgl = isset($row[$idx_tgl]) ? $row[$idx_tgl] : '';
            
            $csv_rows[] = [
                'csv_id' => $csv_id,
                'csv_val' => $csv_val,
                'raw_tgl' => $raw_tgl
            ];
        }
        fclose($handle);

        usort($csv_rows, function($a, $b) {
            return $b['csv_val'] <=> $a['csv_val'];
        });

        foreach ($csv_rows as $csv_item) {
            $csv_id = $csv_item['csv_id'];
            $csv_val = $csv_item['csv_val'];
            $raw_tgl = $csv_item['raw_tgl'];

            $total_nominal_csv += $csv_val;
            $status = 'MISSING';
            $db_val_found = 0;
            $matched_db = [];
            $is_already_verified = false;

            // FILTER KETAT: Hanya Nota yg Tanggalnya SAMA dengan Form Tanggal Pencocokan
            $kandidat_relevan = [];
            foreach ($kandidat_global as $k_id => $k) {
                if (in_array($k_id, $used_db_ids)) continue;
                
                // Mencegah nota beda hari ikut tertarik
                if ($k['tgl_kandidat'] == $global_date) {
                    $kandidat_relevan[$k_id] = $k;
                }
            }

            uasort($kandidat_relevan, function($a, $b) {
                $vA = ($a['data']['is_verif_qris'] === 'Y') ? 1 : 0;
                $vB = ($b['data']['is_verif_qris'] === 'Y') ? 1 : 0;
                return $vA <=> $vB;
            });

            // PHASE 1: Cocokkan langsung (1 banding 1)
            foreach ($kandidat_relevan as $k_id => $kandidat) {
                if (abs($kandidat['val'] - $csv_val) < 10) {
                    $matched_db[] = $kandidat;
                    $db_val_found = $kandidat['val'];
                    $used_db_ids[] = $k_id;
                    $status = 'MATCH';
                    break;
                }
            }

            // PHASE 2: Split Bill (Ketat Berdasarkan Kode Pelanggan Saja)
            if ($status == 'MISSING') {
                $combo_result = null;
                $grouped_items = [];
                foreach ($kandidat_relevan as $ai) {
                    // Gunakan Kode Pelanggan agar tidak salah gabung antar pelanggan
                    $kd = !empty($ai['data']['kode_pelanggan']) ? trim($ai['data']['kode_pelanggan']) : 'UMUM'; 
                    if ($kd == '') $kd = 'UMUM';
                    $grouped_items[$kd][] = $ai;
                }

                foreach ($grouped_items as $kd_plg => $items_per_pelanggan) {
                    if (count($items_per_pelanggan) > 1) { 
                        $res = findCombinationOnly($items_per_pelanggan, $csv_val);
                        if ($res) {
                            $combo_result = $res;
                            break; 
                        }
                    }
                }

                // DI HAPUS: Pencarian Global Ekstrim yang menggabungkan pelanggan berbeda

                if ($combo_result) {
                    $status = 'COMBINED'; 
                    $stat['combined']++;
                    foreach ($combo_result as $cr) {
                        $used_db_ids[] = $cr['kandidat_id']; 
                        $db_val_found += $cr['val'];
                        $matched_db[] = $cr;
                    }
                }
            }

            if ($status == 'MATCH' || $status == 'COMBINED') { 
                $total_nominal_db += $db_val_found; 
                
                foreach ($matched_db as $md) {
                    if ($md['data']['is_verif_qris'] === 'Y') {
                        $is_already_verified = true;
                        break;
                    }
                }
                
                if ($status == 'MATCH') $stat['match']++;
            } else { 
                $stat['missing']++; 
            }

            $final_matches = [];
            if (!empty($matched_db)) {
                $group = [];
                foreach ($matched_db as $md) {
                    $dt = $md['data'];
                    $dt['no_penjualan'] = $md['id'];
                    $dt['sumber'] = $md['sumber'];
                    $dt['nilai_real'] = $md['val']; 
                    $dt['id_update'] = ($md['sumber'] == 'arus_kas') ? $md['data']['id_ak'] : $md['id'];
                    $dt['tipe'] = $md['tipe'];
                    $group[] = $dt;
                }
                $final_matches = $group; 
            }

            $laporan[] = [
                'csv_id'  => $csv_id, 
                'csv_val' => $csv_val, 
                'csv_tgl' => ($raw_tgl) ? $raw_tgl : '<small class="text-muted">('.date('d/m', strtotime($global_date)).')</small>', 
                'matched_data' => $final_matches, 
                'db_val_total' => $db_val_found, 
                'status'  => $status,
                'is_already_verified' => $is_already_verified
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rekonsiliasi Bank CSV | POS System</title>
    
    <link rel="icon" type="image/png" href="../assets/img/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
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
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); overflow: hidden; }
        .wrapper { height: 100vh; width: 100vw; display: flex; }
        .main-content { display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: var(--bg-color); flex-grow: 1; }
        .header-top { height: 70px; background-color: #fff; border-bottom: 1px solid var(--border-color); flex-shrink: 0; z-index: 1020; display: flex; align-items: center; justify-content: space-between; }
        .content-scrollable { flex-grow: 1; overflow-y: auto; padding: 1.5rem; }
        .card-custom { border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .card-stat { border-radius: 16px; border: 0; padding: 1.5rem; height: 100%; color: white;}
        .stat-icon { position: absolute; right: 1rem; top: 1rem; opacity: 0.2; font-size: 3rem; }
        .upload-area { border: 2px dashed #93c5fd; background-color: #eff6ff; transition: 0.2s; cursor: pointer; border-radius: 8px;}
        .upload-area:hover { background-color: #dbeafe; border-color: var(--primary-color); }
        .upload-area input[type=file] { padding: 10px; font-weight: 600; color: var(--primary-color); }
        .upload-area input[type=file]::file-selector-button { background: var(--primary-color); color: white; border: none; border-radius: 6px; padding: 5px 12px; font-weight: bold; cursor: pointer; margin-right: 15px; transition: 0.2s;}
        .upload-area input[type=file]::file-selector-button:hover { background: #1e40af; }
        table.dataTable thead th { background-color: #f8f9fa !important; border-bottom: 1px solid var(--border-color) !important; color: var(--text-dark); font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;}
        table.dataTable tbody td { vertical-align: middle; font-size: 0.9rem; border-bottom: 1px solid var(--border-color); }
        .dataTables_wrapper .dataTables_filter input { border-radius: 8px; border: 1px solid var(--border-color); padding: 0.4rem 0.8rem; }
        .nominal-big { font-size: 1.1rem; font-weight: 800; letter-spacing: -0.5px; }
        .badge-multi { font-size: 0.75rem; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; margin-bottom: 4px; display: inline-block; padding: 4px 8px; border-radius: 12px;}
    </style>
</head>
<body>

<div class="wrapper">
    <?php 
        $base_dir = '../'; 
        include '../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="header-top px-3 px-md-4 shadow-sm">
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Verifikasi Mutasi Bank</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Otomatisasi Validasi QRIS & Transfer Bank</small>
                </div>
            </div>
            
            <div class="d-none d-sm-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                <img src="../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                <div class="d-flex flex-column ms-2 me-3 justify-content-center">
                    <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                    <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'ADMIN') ?></span>
                </div>
            </div>
        </header>

        <div class="content-scrollable">
            <div class="container-fluid p-0 pb-4">
                
                <?php if(!empty($pesan_error)): ?>
                    <div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4 rounded-3">
                        <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                        <div class="fw-bold"><?= $pesan_error ?></div>
                    </div>
                <?php endif; ?>

                <div class="card card-custom bg-white border-0 shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="mb-0 fw-bolder text-primary"><i class="fas fa-cloud-upload-alt me-2"></i>Upload Laporan CSV Bank</h6>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="row align-items-center g-4">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small text-muted text-uppercase mb-2" style="letter-spacing: 0.5px;">Tanggal Pencocokan</label>
                                    <input type="date" name="tgl_verifikasi" id="tgl_verifikasi_input" class="form-control form-control-lg bg-light border-0 shadow-sm" style="border-radius: 8px;" value="<?= $default_tgl ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted text-uppercase mb-2" style="letter-spacing: 0.5px;">File Mutasi (.CSV)</label>
                                    <div class="upload-area shadow-sm">
                                        <input type="file" name="file_csv" class="form-control form-control-lg border-0 bg-transparent w-100" required accept=".csv">
                                    </div>
                                    <div class="form-text small mt-2"><i class="fas fa-info-circle me-1 text-primary"></i> Unggah file dari GoPay, BCA, atau layanan merchant bank Anda.</div>
                                </div>
                                <div class="col-md-3 mt-4 mt-md-0 pt-md-4">
                                    <button type="submit" name="upload" class="btn btn-primary btn-lg w-100 fw-bolder shadow-sm" style="border-radius: 8px; letter-spacing: 0.5px;">
                                        <i class="fas fa-cogs me-2"></i> PROSES DATA
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($laporan)): ?>
                    <div class="row mb-4 g-3">
                        <div class="col-md-3">
                            <div class="card-stat bg-primary position-relative overflow-hidden shadow-sm">
                                <i class="fas fa-university stat-icon"></i>
                                <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Uang di Bank (CSV)</h6>
                                <h3 class="fw-bolder mb-0">Rp <?= number_format($total_nominal_csv, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card-stat bg-success position-relative overflow-hidden shadow-sm">
                                <i class="fas fa-check-double stat-icon"></i>
                                <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Cocok dgn Sistem</h6>
                                <h3 class="fw-bolder mb-0">Rp <?= number_format($total_nominal_db, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card-stat bg-info position-relative overflow-hidden shadow-sm text-dark">
                                <i class="fas fa-layer-group stat-icon text-dark opacity-10"></i>
                                <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Kombinasi Transaksi</h6>
                                <h3 class="fw-bolder mb-0"><?= $stat['combined'] ?> Data</h3>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <?php 
                                $selisih = $total_nominal_db - $total_nominal_csv;
                                $bg_selisih = ($selisih == 0) ? 'bg-secondary' : 'bg-danger';
                            ?>
                            <div class="card-stat <?= $bg_selisih ?> position-relative overflow-hidden shadow-sm">
                                <i class="fas fa-exclamation-triangle stat-icon"></i>
                                <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Selisih Nominal</h6>
                                <h3 class="fw-bolder mb-0">Rp <?= number_format($selisih, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="card card-custom bg-white">
                        <div class="card-body p-0">
                            <div class="table-responsive p-3">
                                <table class="table table-hover w-100" id="tableResult">
                                    <thead>
                                        <tr>
                                            <th style="width: 25%;">Mutasi Bank (CSV)</th>
                                            <th style="width: 15%; text-align:right;">Nominal Masuk</th>
                                            <th style="width: 5%; text-align:center;"></th>
                                            <th style="width: 25%;">Catatan Sistem Kasir</th>
                                            <th style="width: 10%; text-align:center;">Status</th>
                                            <th style="width: 20%; text-align:center;">Tindakan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($laporan as $row): ?>
                                            <tr class="<?= ($row['status'] == 'MISSING') ? 'bg-white' : 'table-success bg-opacity-25' ?>">
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <span class="fw-bold text-dark font-monospace small" title="<?= htmlspecialchars($row['csv_id']) ?>">
                                                            <?= htmlspecialchars($row['csv_id']) ?>
                                                        </span>
                                                        <span class="text-muted" style="font-size: 0.75rem;"><i class="far fa-clock me-1"></i><?= $row['csv_tgl'] ?></span>
                                                    </div>
                                                </td>
                                                <td class="text-end">
                                                    <span class="nominal-big text-primary">Rp <?= number_format($row['csv_val'], 0, ',', '.') ?></span>
                                                </td>
                                                <td class="text-center text-muted opacity-50">
                                                    <i class="fas fa-arrow-right"></i>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $butuh_update_qris = false; 
                                                    $ids_string = '';
                                                    $nama_plg_tampil = '';
                                                    
                                                    if(!empty($row['matched_data'])): ?>
                                                        <div class="d-flex flex-column align-items-start">
                                                            <?php 
                                                            $ids_arr = [];
                                                            foreach($row['matched_data'] as $md): 
                                                                $id_nota = $md['no_penjualan']; 
                                                                $dt_asli = $md;
                                                                $ids_arr[] = $md['sumber'] . '|' . $md['id_update'];
                                                                
                                                                if ($nama_plg_tampil == '' && !empty($dt_asli['nama_pelanggan'])) {
                                                                    $nama_plg_tampil = htmlspecialchars($dt_asli['nama_pelanggan']);
                                                                }
                                                                
                                                                if ($dt_asli['is_verif_qris'] == 'Y') {
                                                                    $badge_text = "Sudah Valid";
                                                                    $badge_color = "bg-secondary";
                                                                } else if ($md['tipe'] == 'CICILAN_PIUTANG') {
                                                                    $badge_text = "Cicilan Piutang";
                                                                    $badge_color = "bg-warning text-dark";
                                                                } else if ($md['tipe'] == 'SISA_UTANG') {
                                                                    $badge_text = "Nota Piutang";
                                                                    $badge_color = "bg-danger";
                                                                } else {
                                                                    $badge_text = "Nota Kasir";
                                                                    $badge_color = "bg-primary";
                                                                }
                                                                
                                                                if ($dt_asli['is_verif_qris'] == 'N') {
                                                                    $butuh_update_qris = true; 
                                                                }
                                                            ?>
                                                                <span class="badge badge-multi">
                                                                    <i class="fas fa-receipt text-muted me-1"></i><?= $id_nota ?> 
                                                                    <span class="badge <?= $badge_color ?> rounded-pill ms-1 py-1" style="font-size:0.6rem;"><?= $badge_text ?></span>
                                                                </span>
                                                            <?php endforeach; 
                                                            $ids_string = implode(',', $ids_arr);
                                                            ?>
                                                            
                                                            <?php if($row['status'] == 'COMBINED' && $nama_plg_tampil != ''): ?>
                                                                <span class="text-secondary fw-semibold mt-1" style="font-size:0.75rem;"><i class="fas fa-user-circle me-1"></i> <?= $nama_plg_tampil ?></span>
                                                            <?php endif; ?>
                                                            <span class="text-success fw-bolder mt-1" style="font-size:0.8rem;">
                                                                Total: Rp <?= number_format($row['db_val_total'], 0, ',', '.') ?>
                                                            </span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-danger small fst-italic"><i class="fas fa-search-minus me-1"></i>Tidak ditemukan</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center td-status">
                                                    <?php if ($row['status'] == 'MATCH'): ?>
                                                        <?php if ($row['is_already_verified']): ?>
                                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-2" title="Sudah pernah diverifikasi"><i class="fas fa-check-double me-1"></i> VERIFIED</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2"><i class="fas fa-check-circle me-1"></i> MATCH</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($row['status'] == 'COMBINED'): ?>
                                                        <?php if ($row['is_already_verified']): ?>
                                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-2" title="Sudah pernah diverifikasi"><i class="fas fa-layer-group me-1"></i> VERIFIED</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-50 px-3 py-2"><i class="fas fa-layer-group me-1"></i> KANDIDAT</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2"><i class="fas fa-times-circle me-1"></i> MISSING</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="td-aksi text-center">
                                                    <?php 
                                                    if (($row['status'] == 'COMBINED' || $row['status'] == 'MATCH') && !empty($ids_string)): 
                                                        if ($butuh_update_qris):
                                                    ?>
                                                            <button type="button" class="btn btn-sm btn-outline-primary w-100 fw-bold shadow-sm" style="border-radius:6px;" onclick="simpanKeQrisAjax(this, '<?= $ids_string ?>')">
                                                                <i class="fas fa-check-double me-1"></i> Setujui Valid
                                                            </button>
                                                        <?php else: ?>
                                                            <div class="badge bg-secondary w-100 py-2 text-white shadow-sm" style="border-radius:6px;"><i class="fas fa-lock me-1"></i> Sudah Diverifikasi</div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="text-muted small fst-italic opacity-50">-</div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#tableResult').DataTable({
            "pageLength": 50,
            "ordering": false, 
            "language": { "search": "Filter Data:", "lengthMenu": "Tampilkan _MENU_ baris" },
            "dom": '<"row mb-3"<"col-md-6"l><"col-md-6"f>>rt<"row mt-3"<"col-md-6"i><"col-md-6"p>>'
        });
    });

    function simpanKeQrisAjax(btnElement, targetIds) {
        Swal.fire({
            title: 'Konfirmasi Mutasi',
            text: `Data ini akan disahkan sebagai dana QRIS/Transfer yang valid di database. Lanjutkan?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#1a56db',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Sahkan!'
        }).then((result) => {
            if (result.isConfirmed) {
                let $btn = $(btnElement);
                let originalText = $btn.html();
                $btn.html('<i class="fas fa-spinner fa-spin me-1"></i> Proses...');
                $btn.prop('disabled', true);
                
                let targetDate = document.getElementById('tgl_verifikasi_input').value;

                $.ajax({
                    url: 'verifikasi_csv.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { 
                        ajax_update_qris: 1, 
                        target_ids: targetIds,
                        tgl_verif: targetDate
                    },
                    success: function(res) {
                        if (res.status === 'success') {
                            Swal.fire({ icon: 'success', title: 'Data Disahkan', text: res.message, timer: 1500, showConfirmButton: false });
                            let $tr = $btn.closest('tr');
                            $tr.removeClass('bg-white').addClass('table-success bg-opacity-25');
                            $tr.find('.td-status').html('<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-2"><i class="fas fa-check-double me-1"></i> VERIFIED</span>');
                            $tr.find('.td-aksi').html('<div class="badge bg-secondary w-100 py-2 text-white shadow-sm" style="border-radius:6px;"><i class="fas fa-lock me-1"></i> Sudah Diverifikasi</div>');
                        } else {
                            Swal.fire('Gagal', res.message, 'error');
                            $btn.html(originalText); $btn.prop('disabled', false);
                        }
                    },
                    error: function() {
                        Swal.fire('Koneksi Error', 'Gagal menghubungi server.', 'error');
                        $btn.html(originalText); $btn.prop('disabled', false);
                    }
                });
            }
        });
    }
</script>
</body>
</html>