<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// --- INIT SESSION KERANJANG ---
if (!isset($_SESSION['verif_cart'])) { $_SESSION['verif_cart'] = []; }

// --- SETTING STICKY INPUT ---
$sticky_tgl    = isset($_SESSION['sticky_tgl']) ? $_SESSION['sticky_tgl'] : date('Y-m-d');
$sticky_metode = isset($_SESSION['sticky_metode']) ? $_SESSION['sticky_metode'] : 'Transfer';

// --- HELPER FUNCTION ---
function cleanNum($str) {
    $str = preg_replace('/[^0-9,.]/', '', $str);
    if (strpos($str, '.') !== false && strpos($str, ',') !== false) {
        $str = str_replace('.', '', $str); $str = str_replace(',', '.', $str);
    } elseif (strpos($str, '.') !== false) {
        if (substr_count($str, '.') > 1 || strlen(explode('.', $str)[1]) == 3) $str = str_replace('.', '', $str);
    }
    return (float)$str;
}

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


function normalizeMetodeManual($method) {
    $m = strtoupper(trim((string)$method));
    if (preg_match('/QRIS|QR|GOPAY|GO-PAY|DANA|OVO|SHOPEE|LINKAJA/', $m)) return 'QRIS';
    if (preg_match('/DEBIT|EDC|CARD|KARTU/', $m)) return 'Debit';
    if (preg_match('/TRANSFER|TRF|TF|BANK|BCA|BRI|BNI|MANDIRI|BSI|CIMB|JAGO|SEABANK/', $m)) return 'Transfer';
    if (preg_match('/CASH|TUNAI/', $m)) return 'Cash';
    return trim((string)$method) !== '' ? trim((string)$method) : 'Cash';
}

function isQrisMetodeManual($method) {
    return normalizeMetodeManual($method) === 'QRIS';
}

function buildVerifTag($method, $tgl) {
    $method = normalizeMetodeManual($method);
    $tgl = trim((string)$tgl);
    if ($method === 'QRIS') {
        return '[Verif:QRIS' . ($tgl !== '' ? ':' . $tgl : '') . ']';
    }
    return '[Verif:' . $method . ($tgl !== '' ? ':' . $tgl : '') . ']';
}

function stripOldVerifTags($text) {
    return trim(preg_replace('/\s*\[Verif:[^\]]*\]/i', '', (string)$text));
}

function getRiwayatKasirQrisTotalForDates(PDO $pdo, array $dates) {
    $dates = array_values(array_filter(array_unique($dates)));
    if (empty($dates)) return 0.0;

    $grandTotalQris = 0.0;

    // Penting:
    // Fungsi ini sengaja meniru rumus ringkasan QRIS Masuk di:
    // transaksi/penjualan/riwayat.php
    // supaya angka Verifikasi Manual sama dengan angka di Riwayat Kasir.
    foreach ($dates as $tanggal) {
        $tgl_awal = $tanggal;
        $tgl_akhir = $tanggal;

        $params = [];
        $sql = "SELECT p.*, pl.nama_pelanggan, pl.no_telepon, pl.wa_lid, o.nama_user,
                CASE WHEN p.total_omzet > 0 THEN p.total_omzet
                     ELSE (SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0)
                           FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan)
                END as nilai_transaksi_real
                FROM penjualan p
                LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                LEFT JOIN operator o ON p.kode_user = o.kode_user
                WHERE 1=1 ";

        $stmt_ak = $pdo->prepare("SELECT DISTINCT no_penjualan
                                  FROM arus_kas
                                  WHERE tanggal BETWEEN ? AND ?
                                    AND jenis = 'Pemasukan'
                                    AND no_penjualan IS NOT NULL");
        $stmt_ak->execute([$tgl_awal, $tgl_akhir]);
        $ids_ak = $stmt_ak->fetchAll(PDO::FETCH_COLUMN);

        $sql .= " AND ( p.tgl_penjualan BETWEEN ? AND ? OR (p.is_verif_qris = 'Y' AND p.tgl_verif_qris BETWEEN ? AND ?) ";
        array_push($params, $tgl_awal, $tgl_akhir, $tgl_awal, $tgl_akhir);

        if (count($ids_ak) > 0) {
            $in = implode(',', array_fill(0, count($ids_ak), '?'));
            $sql .= " OR p.no_penjualan IN ($in) ";
            $params = array_merge($params, $ids_ak);
        }
        $sql .= " ) ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $no_penjualan_list = array_column($raw_data, 'no_penjualan');
        $cicilan_map = [];
        if (!empty($no_penjualan_list)) {
            $inQuery = implode(',', array_fill(0, count($no_penjualan_list), '?'));
            $sql_arus = "SELECT no_penjualan, tanggal, metode_pembayaran, jumlah_masuk
                         FROM arus_kas
                         WHERE jenis = 'Pemasukan' AND no_penjualan IN ($inQuery)";
            $stmt_arus = $pdo->prepare($sql_arus);
            $stmt_arus->execute($no_penjualan_list);

            foreach ($stmt_arus->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cicilan_map[$c['no_penjualan']][] = $c;
            }
        }

        $sum_qris = 0.0;

        foreach ($raw_data as $row) {
            $grand_total = (float)$row['nilai_transaksi_real'];
            $tgl_trx_db  = $row['tgl_penjualan'];
            $is_nota_in_range = ($tgl_trx_db >= $tgl_awal && $tgl_trx_db <= $tgl_akhir);

            $cicilan_db = $cicilan_map[$row['no_penjualan']] ?? [];
            $total_sudah_dicicil = 0.0;

            // A. PROSES CICILAN MURNI DARI DB
            foreach ($cicilan_db as $c) {
                $tgl_cicil     = $c['tanggal'];
                $metode_cicil  = $c['metode_pembayaran'] ?: 'Cash';
                $nominal_cicil = (float)$c['jumlah_masuk'];
                $total_sudah_dicicil += $nominal_cicil;

                if ($tgl_cicil >= $tgl_awal && $tgl_cicil <= $tgl_akhir) {
                    if ($metode_cicil == 'QRIS') {
                        $sum_qris += $nominal_cicil;
                    }
                }
            }

            // B. CEK DEPOSIT, sama seperti riwayat.php
            $nominal_deposit = (float)($row['nominal_deposit'] ?? 0);
            if ($nominal_deposit == 0 && stripos($row['keterangan'] ?? '', 'depo') !== false) {
                if (preg_match('/(?:Depo|Deposit).*?([0-9.,]+)/i', $row['keterangan'], $md)) {
                    $nominal_deposit = (float)str_replace(['.', ','], ['', '.'], $md[1]);
                } elseif (strtolower(trim($row['keterangan'])) == 'deposit') {
                    $nominal_deposit = $grand_total;
                }
            }

            // C. CEK AUTO-VERIF QRIS, termasuk fallback tag lama di keterangan
            $is_verif_qris = ($row['is_verif_qris'] === 'Y');
            $tgl_qris = $row['tgl_verif_qris'] ?: $tgl_trx_db;

            if (!$is_verif_qris && strpos($row['keterangan'] ?? '', '[Verif:QRIS') !== false) {
                if (preg_match('/\[Verif:QRIS(?::(.*?))?\]/i', $row['keterangan'], $vq)) {
                    $is_verif_qris = true;
                    $tgl_qris = !empty($vq[1]) ? trim($vq[1]) : $tgl_trx_db;
                }
            }

            if ($is_verif_qris) {
                $sisa_sebelum_verif = $grand_total - $nominal_deposit - $total_sudah_dicicil;
                if ($sisa_sebelum_verif < 0) $sisa_sebelum_verif = 0;

                $nominal_verif_qris = ($row['pelunasan'] == 'Y') ? $sisa_sebelum_verif : 0;

                if ($tgl_qris >= $tgl_awal && $tgl_qris <= $tgl_akhir) {
                    $sum_qris += $nominal_verif_qris;
                }
            }

            // D. PROSES NOTA ASLI, sama seperti riwayat.php
            if ($is_nota_in_range) {
                $dp_awal = 0.0;
                if (!$is_verif_qris) {
                    $dp_awal = (float)$row['uang_bayar'] - $total_sudah_dicicil;
                    if ($dp_awal < 0) $dp_awal = 0;

                    $metode_dp = $row['metode_pembayaran'] ?: 'Cash';

                    if ($dp_awal > 0 && $metode_dp == 'QRIS') {
                        $sum_qris += $dp_awal;
                    }
                }
            }
        }

        $grandTotalQris += $sum_qris;
    }

    return $grandTotalQris;
}

// --- 1. HANDLE AKSI TAMBAH KE KERANJANG ---
if (isset($_POST['act_add_cart'])) {
    $_SESSION['sticky_tgl'] = $_POST['tgl_trx'];
    $_SESSION['sticky_metode'] = $_POST['metode_bayar'];
    
    $nominal = cleanNum($_POST['nominal']);
    if ($nominal > 0) {
        $_SESSION['verif_cart'][] = [
            'id' => uniqid(),
            'tgl' => $_POST['tgl_trx'],
            'metode' => $_POST['metode_bayar'],
            'nominal' => $nominal
        ];
    }
    header("Location: verifikasi_manual.php"); 
    exit;
}

// --- 2. HAPUS ITEM DARI KERANJANG ---
if (isset($_GET['del_cart'])) {
    $id_del = $_GET['del_cart'];
    foreach ($_SESSION['verif_cart'] as $k => $v) {
        if ($v['id'] == $id_del) { unset($_SESSION['verif_cart'][$k]); break; }
    }
    $_SESSION['verif_cart'] = array_values($_SESSION['verif_cart']); 
    header("Location: verifikasi_manual.php");
    exit;
}

if (isset($_GET['reset_cart'])) {
    $_SESSION['verif_cart'] = [];
    header("Location: verifikasi_manual.php");
    exit;
}

// --- 3. HANDLE PROSES VERIFIKASI / UPDATE DATABASE ---
if (isset($_POST['act_save_db'])) {
    $is_ajax_request = (
        (!empty($_POST['ajax']) && $_POST['ajax'] == '1') ||
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    );

    $target_ids = explode(',', $_POST['target_ids']); 
    $action_type = $_POST['action_type']; 
    $new_method = normalizeMetodeManual($_POST['new_method'] ?? 'Cash');
    $tgl_verif = isset($_POST['tgl_verif']) ? $_POST['tgl_verif'] : date('Y-m-d');
    $is_qris_method = isQrisMetodeManual($new_method);
    $count = 0;

    foreach ($target_ids as $target) {
        if (empty($target)) continue;
        
        list($sumber, $id_faktur) = explode('|', $target);
        
        if ($sumber == 'penjualan') {
            $stmtGet = $pdo->prepare("SELECT keterangan, nominal_deposit,
                CASE WHEN total_omzet > 0 THEN total_omzet ELSE (SELECT COALESCE(SUM((harga_jual * jumlah) - diskon), 0) FROM penjualan_item WHERE no_penjualan = p.no_penjualan) END as nilai_real
                FROM penjualan p WHERE no_penjualan = ?");
            $stmtGet->execute([$id_faktur]);
            $nota = $stmtGet->fetch(PDO::FETCH_ASSOC);

            if ($nota) {
                $ket_baru = isset($nota['keterangan']) ? $nota['keterangan'] : '';

                if ($action_type == 'update') {
                    $pola = '/(Cash|Tunai|QRIS|Transfer|Debit|Bank)/i';
                    $ket_temp = preg_replace($pola, $new_method, $ket_baru);
                    if ($ket_temp == $ket_baru && stripos($ket_baru, $new_method) === false) {
                        $ket_baru = $new_method . " | " . $ket_baru;
                    } else {
                        $ket_baru = $ket_temp;
                    }
                }
                
                $ket_baru = stripOldVerifTags($ket_baru);
                $ket_baru = trim($ket_baru . ' ' . buildVerifTag($new_method, $tgl_verif));

                $uang_lunas = (float)$nota['nilai_real'] - (float)$nota['nominal_deposit'];
                if ($uang_lunas < 0) $uang_lunas = 0;

                $sql_upd = "UPDATE penjualan 
                            SET keterangan = ?, 
                                metode_pembayaran = ?, 
                                is_verif_qris = ?, 
                                tgl_verif_qris = ?, 
                                uang_bayar = ?, 
                                pelunasan = 'Y',
                                tgl_transaksi = ?
                            WHERE no_penjualan = ?";
                $stmtUpd = $pdo->prepare($sql_upd);
                $stmtUpd->execute([
                    $ket_baru,
                    $new_method,
                    $is_qris_method ? 'Y' : 'N',
                    $is_qris_method ? $tgl_verif : null,
                    $uang_lunas,
                    $tgl_verif,
                    $id_faktur
                ]);

                $stmtItem = $pdo->prepare("UPDATE penjualan_item SET pelunasan = 'Y' WHERE no_penjualan = ?");
                $stmtItem->execute([$id_faktur]);
            }

        } else if ($sumber == 'arus_kas') {
            $stmtGet = $pdo->prepare("SELECT keterangan FROM arus_kas WHERE id = ?");
            $stmtGet->execute([$id_faktur]);
            $row = $stmtGet->fetch();
            $ket_baru = isset($row['keterangan']) ? $row['keterangan'] : '';

            if ($action_type == 'update') {
                $pola = '/(Cash|Tunai|QRIS|Transfer|Debit|Bank)/i';
                $ket_temp = preg_replace($pola, $new_method, $ket_baru);
                if ($ket_temp == $ket_baru && stripos($ket_baru, $new_method) === false) {
                    $ket_baru = $new_method . " | " . $ket_baru;
                } else {
                    $ket_baru = $ket_temp;
                }
            }
            
            $ket_baru = stripOldVerifTags($ket_baru);
            $ket_baru = trim($ket_baru . ' ' . buildVerifTag($new_method, $tgl_verif));

            $stmtUpd = $pdo->prepare("UPDATE arus_kas SET keterangan = ?, metode_pembayaran = ? WHERE id = ?");
            $stmtUpd->execute([$ket_baru, $new_method, $id_faktur]);
        }
        $count++;
    }

    if ($count > 0) {
        // Data input manual tetap dipertahankan di list supaya hasil verifikasi tetap terlihat.
        // Setelah reload, transaksi yang tadinya Piutang akan tampil kembali sebagai Lunas.
        if ($is_ajax_request) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Data berhasil diverifikasi. Status transaksi diubah menjadi Lunas.',
                'count' => $count,
                'target_ids' => $target_ids,
                'method' => $new_method,
                'tgl_verif' => $tgl_verif
            ]);
            exit;
        }

        header("Location: verifikasi_manual.php?act=process&msg=success");
        exit;
    }

    if ($is_ajax_request) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Tidak ada data yang diproses.'
        ]);
        exit;
    }
}

// --- 4. LOGIKA PENCARIAN MATCHING ---
$results = [];
$used_db_ids = []; 

if (isset($_GET['act']) && $_GET['act'] == 'process' && !empty($_SESSION['verif_cart'])) {
    
    $dates = array_unique(array_column($_SESSION['verif_cart'], 'tgl'));
    $db_candidates = [];

    foreach ($dates as $d) {
        $sql_jual = "SELECT p.no_penjualan, p.tgl_penjualan, p.jam, p.keterangan, p.kode_pelanggan, pl.nama_pelanggan, 
                            p.pelunasan, p.uang_bayar, p.nominal_deposit, p.is_verif_qris, p.tgl_verif_qris, 'penjualan' AS sumber,
                            CASE WHEN p.total_omzet > 0 THEN p.total_omzet ELSE (SELECT COALESCE(SUM(pi.subtotal), 0) FROM penjualan_item pi WHERE pi.no_penjualan = p.no_penjualan) END as nilai_real
                     FROM penjualan p 
                     LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                     WHERE p.tgl_penjualan = ?
                     ORDER BY p.pelunasan ASC, p.jam ASC";
        $stmt_jual = $pdo->prepare($sql_jual);
        $stmt_jual->execute([$d]);
        $data_jual = $stmt_jual->fetchAll(PDO::FETCH_ASSOC);

        $sql_kas = "SELECT CAST(ak.id AS CHAR) as no_penjualan, ak.tanggal as tgl_penjualan, '00:00:00' as jam, 
                           ak.keterangan, COALESCE(pl.nama_pelanggan, 'Umum / Arus Kas') as nama_pelanggan, NULL as kode_pelanggan,
                           'Y' as pelunasan, ak.jumlah_masuk as uang_bayar, 0 as nominal_deposit, NULL as is_verif_qris, NULL as tgl_verif_qris, 'arus_kas' AS sumber, ak.jumlah_masuk as nilai_real
                    FROM arus_kas ak
                    LEFT JOIN penjualan p ON ak.no_penjualan = p.no_penjualan
                    LEFT JOIN pelanggan pl ON p.kode_pelanggan = pl.kode_pelanggan
                    WHERE ak.tanggal = ? AND ak.jumlah_masuk > 0";
        $stmt_kas = $pdo->prepare($sql_kas);
        $stmt_kas->execute([$d]);
        $data_kas = $stmt_kas->fetchAll(PDO::FETCH_ASSOC);

        $db_candidates[$d] = array_merge($data_jual, $data_kas);
    }

    foreach ($_SESSION['verif_cart'] as $item) {
        $tgl = $item['tgl'];
        $target = $item['nominal'];
        $pool = $db_candidates[$tgl] ?? [];
        
        $status = 'MISSING';
        $matched_db = [];

        foreach ($pool as $cand) {
            $unik_id = $cand['sumber'] . '|' . $cand['no_penjualan'];
            if (in_array($unik_id, $used_db_ids)) continue;

            $nilai_real = (float)$cand['nilai_real'];
            $dp = (float)$cand['uang_bayar'];
            $depo = (float)$cand['nominal_deposit'];
            $sisa_utang = $nilai_real - $depo - $dp;
            
            if (abs($nilai_real - $target) < 10 || ($cand['pelunasan'] == 'N' && abs($sisa_utang - $target) < 10) || ($cand['pelunasan'] == 'Y' && abs($dp - $target) < 10 && $dp > 0)) {
                $matched_db = [[ 'id' => $cand['no_penjualan'], 'val' => $target, 'data' => $cand, 'tipe' => 'FULL' ]];
                $used_db_ids[] = $unik_id;
                $status = 'MATCH';
                break;
            }
        }

        if ($status == 'MISSING') {
            $available_items = [];
            
            foreach ($pool as $dt) {
                $unik_id = $dt['sumber'] . '|' . $dt['no_penjualan'];
                if (in_array($unik_id, $used_db_ids)) continue;
                
                $nilai_real = (float)$dt['nilai_real'];
                $dp = (float)$dt['uang_bayar'];
                $depo = (float)$dt['nominal_deposit'];
                $item_to_add = null;

                if ($dt['pelunasan'] == 'N') {
                    $sisa_utang = $nilai_real - $depo - $dp;
                    if ($sisa_utang >= 10 && $sisa_utang <= ($target + 50)) {
                        $item_to_add = [ 'id' => $dt['no_penjualan'], 'val' => $sisa_utang, 'data' => $dt, 'unik_id' => $unik_id ];
                    }
                } else if ($dt['pelunasan'] == 'Y') {
                    if ($dp >= 10 && $dp <= ($target + 50)) {
                        $item_to_add = [ 'id' => $dt['no_penjualan'], 'val' => $dp, 'data' => $dt, 'unik_id' => $unik_id ];
                    }
                }

                if ($item_to_add) {
                    $item_to_add['kode_pelanggan'] = $dt['kode_pelanggan'];
                    $item_to_add['nama_pelanggan'] = $dt['nama_pelanggan'];
                    $available_items[] = $item_to_add;
                }
            }

            $combo_result = null;

            $grouped_items = [];
            foreach ($available_items as $ai) {
                $kd = !empty($ai['kode_pelanggan']) ? $ai['kode_pelanggan'] : 'UMUM_'.$ai['id']; 
                $grouped_items[$kd][] = $ai;
            }

            foreach ($grouped_items as $kd_plg => $items_per_pelanggan) {
                if (count($items_per_pelanggan) > 1) { 
                    $res = findCombinationOnly($items_per_pelanggan, $target);
                    if ($res) {
                        $combo_result = $res;
                        break; 
                    }
                }
            }

            if ($combo_result) {
                foreach ($combo_result as $cr) {
                    $used_db_ids[] = $cr['unik_id'];
                    $matched_db[] = $cr;
                }
                $status = 'COMBINED';
            }
        }

        $matches = [];
        $db_val_total = 0;
        if (!empty($matched_db)) {
            $group = [];
            foreach ($matched_db as $md) {
                $dt = $md['data'];
                $dt['nilai_real'] = $md['val']; 
                $db_val_total += $md['val'];
                $group[] = $dt;
            }
            $matches = [$group];
        }

        $results[] = [
            'cart_item' => $item,
            'matches' => $matches,
            'status' => $status,
            'db_val_total' => $db_val_total
        ];
    }
}

$total_input_calc = 0;
$total_match_candidate_calc = 0;
$total_db_calc = 0;
$count_combined = 0;

if (!empty($results)) {
    foreach ($results as $r) {
        $total_input_calc += $r['cart_item']['nominal'];
        $total_match_candidate_calc += $r['db_val_total'];
        if ($r['status'] == 'COMBINED') $count_combined++;
    }
}

$cart_dates_for_sync = !empty($_SESSION['verif_cart']) ? array_column($_SESSION['verif_cart'], 'tgl') : [];
$cart_methods_for_sync = !empty($_SESSION['verif_cart']) ? array_map('normalizeMetodeManual', array_column($_SESSION['verif_cart'], 'metode')) : [];
$has_qris_input = in_array('QRIS', $cart_methods_for_sync, true);

// Untuk QRIS/GoPay, angka ini mengikuti rumus QRIS Masuk di halaman Riwayat Kasir.
// Jadi kartu "Cocok dgn Kasir" tidak lagi menghitung kandidat mentah yang belum benar-benar masuk QRIS.
$total_db_calc = $has_qris_input ? getRiwayatKasirQrisTotalForDates($pdo, $cart_dates_for_sync) : $total_match_candidate_calc;
$selisih_calc = $total_db_calc - $total_input_calc;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Verifikasi Manual (Smart Engine)</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root { --border-color: #e5e7eb; }
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        
        .form-control:focus, .form-select:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
        .cart-table th { background-color: #f8f9fa; font-size: 0.85rem; }
        
        .card-custom { border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 2px 10px rgba(0,0,0,0.02); overflow: hidden; }
        .card-stat { border-radius: 16px; border: 0; padding: 1.5rem; height: 100%; color: white;}
        .stat-icon { position: absolute; right: 1rem; top: 1rem; opacity: 0.2; font-size: 3rem; }
        
        table.dataTable thead th { background-color: #f8f9fa !important; border-bottom: 1px solid var(--border-color) !important; color: #111827; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;}
        table.dataTable tbody td { vertical-align: middle; font-size: 0.9rem; border-bottom: 1px solid var(--border-color); }
        .dataTables_wrapper .dataTables_filter input { border-radius: 8px; border: 1px solid var(--border-color); padding: 0.4rem 0.8rem; }
        
        .nominal-big { font-size: 1.1rem; font-weight: 800; letter-spacing: -0.5px; }
        .badge-multi { font-size: 0.75rem; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; margin-bottom: 4px; display: inline-block; padding: 4px 8px; border-radius: 12px;}
        .verif-row-processing { position: relative; opacity: .72; transition: opacity .2s ease, background-color .2s ease; }
        .verif-row-verified { animation: verifiedPulse .85s ease; }
        @keyframes verifiedPulse { 0% { box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, .18); } 100% { box-shadow: inset 0 0 0 0 rgba(25, 135, 84, 0); } }
        .btn-loading { pointer-events: none; opacity: .85; }
        .floating-verif-alert { position: fixed; right: 24px; bottom: 24px; z-index: 9999; max-width: 420px; display: none; }
        .verif-inline-note { font-size: .75rem; margin-top: .35rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-secondary mb-4 shadow-sm border-bottom">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="../index.php"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
        <span class="navbar-text text-white fw-semibold" style="letter-spacing: 0.5px;">Verifikasi Manual (Smart Match)</span>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">
    
    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'success'): ?>
    <div class="alert alert-success alert-dismissible fade show mb-4 shadow-sm border-0 d-flex align-items-center rounded-3" role="alert">
        <i class="fas fa-check-circle fs-4 me-3"></i> 
        <div class="fw-bold">Data berhasil diverifikasi. Data input tetap ditampilkan, dan status transaksi berubah menjadi Lunas.</div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-xl-3 col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 mb-3">
                <div class="card-header bg-primary text-white py-3 border-0">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-keyboard me-2"></i>Input Data Mutasi</h6>
                </div>
                <div class="card-body p-3 bg-white">
                    <form method="POST">
                        <div class="mb-2">
                            <label class="small fw-bold text-muted">Tanggal Mutasi</label>
                            <input type="date" name="tgl_trx" class="form-control bg-light border-0 shadow-sm" style="border-radius: 8px;" value="<?= $sticky_tgl ?>" required>
                        </div>
                        <div class="mb-2">
                            <label class="small fw-bold text-muted">Metode Bank</label>
                            <select name="metode_bayar" class="form-select bg-light border-0 shadow-sm" style="border-radius: 8px;">
                                <option value="Transfer" <?= $sticky_metode == 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                                <option value="QRIS" <?= $sticky_metode == 'QRIS' ? 'selected' : '' ?>>QRIS</option>
                                <option value="Debit" <?= $sticky_metode == 'Debit' ? 'selected' : '' ?>>Debit</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold text-muted">Nominal (Rp)</label>
                            <input type="text" name="nominal" id="inputNominal" class="form-control form-control-lg fw-bolder text-primary border-0 shadow-sm bg-light" placeholder="0" autocomplete="off" required autofocus style="border-radius: 8px;">
                        </div>
                        <button type="submit" name="act_add_cart" class="btn btn-primary w-100 fw-bold shadow-sm" style="border-radius: 8px;"><i class="fas fa-arrow-down me-2"></i> Simpan ke List</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-list-check me-2"></i>Daftar Input (<?= count($_SESSION['verif_cart']) ?>)</h6>
                    <?php if(count($_SESSION['verif_cart']) > 0): ?>
                    <a href="?reset_cart=1" class="btn btn-sm btn-outline-danger py-1 px-2" onclick="return confirm('Hapus semua list?')" style="border-radius: 6px;"><i class="fas fa-trash"></i></a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0 cart-table align-middle">
                            <thead class="sticky-top">
                                <tr>
                                    <th class="ps-3 py-2">Nominal</th>
                                    <th class="py-2">Metode</th>
                                    <th class="text-end pe-3 py-2">#</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($_SESSION['verif_cart'])): ?>
                                    <tr><td colspan="3" class="text-center py-5 text-muted small"><i class="fas fa-box-open fs-3 mb-2 opacity-25"></i><br>Belum ada data input.</td></tr>
                                <?php else: ?>
                                    <?php foreach($_SESSION['verif_cart'] as $item): ?>
                                    <tr>
                                        <td class="fw-bold text-dark ps-3 py-2">Rp <?= number_format($item['nominal'], 0, ',', '.') ?></td>
                                        <td class="small text-muted py-2"><?= $item['metode'] ?></td>
                                        <td class="text-end pe-3 py-2">
                                            <a href="?del_cart=<?= $item['id'] ?>" class="text-danger opacity-75 hover-opacity-100"><i class="fas fa-times-circle"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <?php if(count($_SESSION['verif_cart']) > 0): 
                    $rekap_metode = [];
                    $grand_total = 0;
                    foreach($_SESSION['verif_cart'] as $item) {
                        $m = $item['metode'];
                        if(!isset($rekap_metode[$m])) $rekap_metode[$m] = 0;
                        $rekap_metode[$m] += $item['nominal'];
                        $grand_total += $item['nominal'];
                    }
                ?>
                <div class="bg-light p-3 border-top">
                    <div class="d-flex flex-column gap-1 small">
                        <?php foreach($rekap_metode as $m => $tot): ?>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted fw-semibold"><?= $m ?></span>
                            <span class="fw-bold text-dark">Rp <?= number_format($tot, 0, ',', '.') ?></span>
                        </div>
                        <?php endforeach; ?>
                        <hr class="my-2 border-secondary opacity-25">
                        <div class="d-flex justify-content-between text-primary mt-1 align-items-center">
                            <span class="fw-bold text-uppercase" style="font-size: 0.75rem;">Total Keseluruhan</span>
                            <span class="fw-bolder fs-6">Rp <?= number_format($grand_total, 0, ',', '.') ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="card-footer bg-white p-3 border-0">
                    <a href="?act=process" class="btn btn-success w-100 fw-bolder shadow-sm btn-lg" style="border-radius: 8px; letter-spacing: 0.5px;">
                        <i class="fas fa-search me-2"></i> PROSES DATA
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-9 col-lg-8">
            
            <?php if(isset($_GET['act']) && $_GET['act'] == 'process'): ?>
                
                <div class="row mb-4 g-3">
                    <div class="col-sm-6 col-xl-3">
                        <div class="card-stat bg-primary position-relative overflow-hidden shadow-sm">
                            <i class="fas fa-keyboard stat-icon"></i>
                            <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Total Input Manual</h6>
                            <h3 class="fw-bolder mb-0">Rp <?= number_format($total_input_calc, 0, ',', '.') ?></h3>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="card-stat bg-success position-relative overflow-hidden shadow-sm">
                            <i class="fas fa-cash-register stat-icon"></i>
                            <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Cocok dgn Kasir</h6>
                            <h3 class="fw-bolder mb-0">Rp <?= number_format($total_db_calc, 0, ',', '.') ?></h3>
                            <?php if($has_qris_input): ?>
                                <div class="small opacity-75 mt-1">Mengikuti QRIS Masuk di Riwayat Kasir</div>
                                <div class="small opacity-75">Kandidat match: Rp <?= number_format($total_match_candidate_calc, 0, ',', '.') ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="card-stat bg-info position-relative overflow-hidden shadow-sm text-dark">
                            <i class="fas fa-layer-group stat-icon text-dark opacity-10"></i>
                            <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Kombinasi / Kandidat</h6>
                            <h3 class="fw-bolder mb-0"><?= $count_combined ?> Transaksi</h3>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <?php 
                            $bg_selisih = ($selisih_calc == 0) ? 'bg-secondary' : 'bg-danger';
                        ?>
                        <div class="card-stat <?= $bg_selisih ?> position-relative overflow-hidden shadow-sm">
                            <i class="fas fa-balance-scale stat-icon"></i>
                            <h6 class="text-uppercase small fw-bold opacity-75 mb-1" style="letter-spacing:1px;">Selisih Sistem</h6>
                            <h3 class="fw-bolder mb-0">Rp <?= number_format($selisih_calc, 0, ',', '.') ?></h3>
                        </div>
                    </div>
                </div>

                <div class="alert bg-white border shadow-sm rounded-4 mb-4 px-4 py-3" style="border-left: 5px solid #0d6efd !important;">
                    <h6 class="fw-bold mb-2 text-dark"><i class="fas fa-info-circle text-primary me-2"></i>Panduan Aksi Eksekusi</h6>
                    <div class="row g-3 small text-muted">
                        <div class="col-md-6">
                            <div class="d-flex align-items-start">
                                <div class="me-2 mt-1"><button class="btn btn-sm btn-outline-success px-2 py-0" style="border-radius:4px; pointer-events:none;"><i class="fas fa-check"></i></button></div>
                                <div>
                                    <strong class="text-dark">Verif Standar:</strong> Sistem akan melunasi nota dan mencatatnya sebagai transaksi sah <b>tanpa merombak</b> teks asli catatan kasir. Cocok jika catatan kasir sudah benar.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-start">
                                <div class="me-2 mt-1"><button class="btn btn-sm btn-primary px-2 py-0" style="border-radius:4px; pointer-events:none;"><i class="fas fa-pen-to-square"></i></button></div>
                                <div>
                                    <strong class="text-dark">Ubah Teks & Verif:</strong> Selain melunasi nota, sistem akan <b>otomatis mendeteksi dan mengganti</b> teks lama (cth: Cash/Tunai) di catatan kasir menjadi metode mutasi yang baru.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-custom bg-white">
                    <div class="card-body p-0">
                        <div class="table-responsive p-3">
                            <table class="table table-hover w-100" id="tableResult">
                                <thead>
                                    <tr>
                                        <th style="width: 25%;">Data Input Manual</th>
                                        <th style="width: 15%; text-align:right;">Nominal Input</th>
                                        <th style="width: 5%; text-align:center;"></th>
                                        <th style="width: 25%;">Data Kasir (Database)</th>
                                        <th style="width: 10%; text-align:center;">Status</th>
                                        <th style="width: 20%; text-align:center;">Aksi Eksekusi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $idx => $res): 
                                        $item = $res['cart_item'];
                                        $status = $res['status'];
                                        $matches = $res['matches'];
                                    ?>
                                        <tr class="verif-result-row <?= ($status == 'MISSING') ? 'bg-white' : 'table-success bg-opacity-25' ?>" data-row-index="<?= $idx ?>">
                                            
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <span class="fw-bold text-dark font-monospace small">
                                                        <span class="badge bg-secondary opacity-75 py-1 px-2 me-1">#<?= $idx+1 ?></span> <?= $item['metode'] ?>
                                                    </span>
                                                    <span class="text-muted mt-1" style="font-size: 0.75rem;"><i class="far fa-calendar-alt me-1"></i><?= date('d M Y', strtotime($item['tgl'])) ?></span>
                                                </div>
                                            </td>
                                            
                                            <td class="text-end">
                                                <span class="nominal-big text-primary">Rp <?= number_format($item['nominal'], 0, ',', '.') ?></span>
                                            </td>
                                            
                                            <td class="text-center text-muted opacity-50">
                                                <i class="fas fa-arrow-right"></i>
                                            </td>

                                            <td>
                                                <?php 
                                                $ids_string = '';
                                                $nama_plg_tampil = '';
                                                
                                                if(!empty($matches)): 
                                                    $group = $matches[0];
                                                    $ids_with_source = array_map(function($g) { return $g['sumber'] . '|' . $g['no_penjualan']; }, $group);
                                                    $ids_string = implode(',', $ids_with_source);
                                                    $row_sudah_verif = false;
                                                    foreach ($group as $cekVerif) {
                                                        $cekSudahVerif = (isset($cekVerif['is_verif_qris']) && $cekVerif['is_verif_qris'] === 'Y') || (isset($cekVerif['keterangan']) && stripos($cekVerif['keterangan'], 'Verif') !== false);
                                                        if ($cekSudahVerif) {
                                                            $row_sudah_verif = true;
                                                            break;
                                                        }
                                                    }
                                                ?>
                                                    <div class="d-flex flex-column align-items-start">
                                                        <?php foreach($group as $g): 
                                                            if ($nama_plg_tampil == '' && !empty($g['nama_pelanggan'])) {
                                                                $nama_plg_tampil = htmlspecialchars($g['nama_pelanggan']);
                                                            }
                                                            $badge_text = ($g['pelunasan'] == 'N') ? "Piutang" : "Lunas";
                                                            $badge_color = ($g['pelunasan'] == 'N') ? "bg-danger" : "bg-primary";
                                                            $sudah_verif = (isset($g['is_verif_qris']) && $g['is_verif_qris'] === 'Y') || (isset($g['keterangan']) && stripos($g['keterangan'], 'Verif') !== false);
                                                            $nama_plg_row = trim((string)($g['nama_pelanggan'] ?? ''));
                                                            if ($nama_plg_row === '') {
                                                                $nama_plg_row = 'Umum / Tanpa Nama';
                                                            }
                                                        ?>
                                                            <div class="db-match-item mb-2 pb-2 border-bottom border-light">
                                                                <span class="badge badge-multi">
                                                                    <i class="fas fa-receipt text-muted me-1"></i><?= htmlspecialchars($g['no_penjualan']) ?> 
                                                                    <span class="badge <?= $badge_color ?> rounded-pill ms-1 py-1 js-paid-status-badge" style="font-size:0.6rem;"><?= $badge_text ?></span>
                                                                    <?php if($sudah_verif): ?>
                                                                        <span class="badge bg-success rounded-pill ms-1 py-1 js-verif-badge" style="font-size:0.6rem;">Terverif</span>
                                                                    <?php endif; ?>
                                                                </span>
                                                                <div class="text-secondary fw-semibold mt-1" style="font-size:0.76rem;">
                                                                    <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($nama_plg_row) ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        
                                                        <span class="text-success fw-bolder mt-1" style="font-size:0.8rem;">
                                                            Total: Rp <?= number_format($res['db_val_total'], 0, ',', '.') ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-danger small fst-italic"><i class="fas fa-search-minus me-1"></i>Tidak ada di kasir</span>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <td class="text-center">
                                                <?php if (!empty($row_sudah_verif)): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2"><i class="fas fa-check-circle me-1"></i> TERVERIF</span>
                                                <?php elseif ($status == 'MATCH'): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2"><i class="fas fa-check-circle me-1"></i> MATCH</span>
                                                <?php elseif ($status == 'COMBINED'): ?>
                                                    <span class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-50 px-3 py-2"><i class="fas fa-layer-group me-1"></i> KANDIDAT</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2"><i class="fas fa-times-circle me-1"></i> MISSING</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <?php if (!empty($row_sudah_verif)): ?>
                                                    <button type="button" class="btn btn-sm btn-secondary w-100 fw-bold shadow-sm" style="border-radius:6px;" disabled title="Transaksi ini sudah diverifikasi">
                                                        <i class="fas fa-lock me-1"></i> Sudah diverifikasi
                                                    </button>
                                                    <div class="text-success small fw-semibold mt-1">Tidak bisa diverifikasi ulang</div>
                                                <?php elseif (($status == 'COMBINED' || $status == 'MATCH') && !empty($ids_string)): ?>
                                                    <div class="d-flex flex-column gap-2">
                                                        <form method="POST" class="w-100 m-0 ajax-verif-form">
                                                            <input type="hidden" name="ajax" value="1">
                                                            <input type="hidden" name="act_save_db" value="1">
                                                            <input type="hidden" name="cart_item_id" value="<?= $item['id'] ?>">
                                                            <input type="hidden" name="target_ids" value="<?= $ids_string ?>">
                                                            <input type="hidden" name="new_method" value="<?= $item['metode'] ?>">
                                                            <input type="hidden" name="tgl_verif" value="<?= $item['tgl'] ?>"> 
                                                            <input type="hidden" name="action_type" value="verif">
                                                            <button type="submit" name="act_save_db" class="btn btn-sm btn-outline-success w-100 fw-bold shadow-sm js-verif-button" style="border-radius:6px;" data-confirm="Peringatan: Verifikasi akan mencatat LUNAS. Lanjutkan?" title="Verif & Lunasi tanpa merubah teks keterangan">
                                                                <i class="fas fa-check"></i> Verif Standar
                                                            </button>
                                                        </form>

                                                        <form method="POST" class="w-100 m-0 ajax-verif-form">
                                                            <input type="hidden" name="ajax" value="1">
                                                            <input type="hidden" name="act_save_db" value="1">
                                                            <input type="hidden" name="cart_item_id" value="<?= $item['id'] ?>">
                                                            <input type="hidden" name="target_ids" value="<?= $ids_string ?>">
                                                            <input type="hidden" name="new_method" value="<?= $item['metode'] ?>">
                                                            <input type="hidden" name="tgl_verif" value="<?= $item['tgl'] ?>"> 
                                                            <input type="hidden" name="action_type" value="update">
                                                            <button type="submit" name="act_save_db" class="btn btn-sm btn-primary w-100 fw-bold shadow-sm js-verif-button" style="border-radius:6px;" data-confirm="Ubah otomatis teks (Cash/Transfer/dll) menjadi <?= htmlspecialchars($item['metode']) ?>, Verif, dan otomatis ubah status jadi LUNAS?" title="Ubah Teks, Verif & Lunasi">
                                                                <i class="fas fa-pen-to-square"></i> Ubah Teks & Verif
                                                            </button>
                                                        </form>
                                                    </div>
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
            
            <?php else: ?>
                <div class="d-flex flex-column align-items-center justify-content-center h-100 text-center py-5 mt-5">
                    <div class="bg-white p-5 rounded-circle shadow-sm border mb-4" style="width: 150px; height: 150px; display:flex; align-items:center; justify-content:center;">
                        <i class="fas fa-search-dollar text-primary opacity-25" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="text-dark fw-bold">Siap Memverifikasi</h4>
                    <p class="text-muted mt-2 max-w-md mx-auto">Silahkan inputkan nominal mutasi yang Anda dapatkan dari Bank ke dalam form di sebelah kiri, lalu klik <b class="text-dark">PROSES DATA</b> untuk membiarkan sistem mencocokkannya dengan database kasir.</p>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<div id="floatingVerifAlert" class="floating-verif-alert alert alert-success shadow-lg rounded-4 border-0 mb-0" role="alert">
    <div class="d-flex align-items-start">
        <i class="fas fa-check-circle fs-4 me-3 mt-1"></i>
        <div>
            <div class="fw-bold">Berhasil</div>
            <div class="small" id="floatingVerifAlertText">Data berhasil diverifikasi.</div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputNominal = document.getElementById('inputNominal');
        if(inputNominal) {
            inputNominal.focus();
            inputNominal.select(); 
        }
    });

    $(document).ready(function() {
        let tableResult = null;
        if ($('#tableResult').length) {
            tableResult = $('#tableResult').DataTable({
                "pageLength": 50,
                "ordering": false, 
                "language": { 
                    "search": "Filter Data:", 
                    "lengthMenu": "Tampilkan _MENU_ baris",
                    "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data"
                },
                "dom": '<"row mb-3"<"col-md-6"l><"col-md-6"f>>rt<"row mt-3"<"col-md-6"i><"col-md-6"p>>'
            });
        }

        function showFloatingAlert(message, type) {
            const box = $('#floatingVerifAlert');
            const icon = box.find('i');
            box.removeClass('alert-success alert-danger alert-warning').addClass('alert-' + type);
            icon.removeClass('fa-check-circle fa-triangle-exclamation').addClass(type === 'success' ? 'fa-check-circle' : 'fa-triangle-exclamation');
            $('#floatingVerifAlertText').text(message);
            box.stop(true, true).fadeIn(160).delay(2200).fadeOut(350);
        }

        function markRowAsVerified($row, message) {
            $row.removeClass('bg-white').addClass('table-success bg-opacity-25 verif-row-verified');
            setTimeout(function() { $row.removeClass('verif-row-verified'); }, 950);

            $row.find('.js-paid-status-badge').each(function() {
                $(this).removeClass('bg-danger').addClass('bg-primary').text('Lunas');
            });

            $row.find('.badge-multi').each(function() {
                if ($(this).find('.js-verif-badge').length === 0) {
                    $(this).append(' <span class="badge bg-success rounded-pill ms-1 py-1 js-verif-badge" style="font-size:0.6rem;">Terverif</span>');
                }
            });

            const $statusCell = $row.find('td').eq(4);
            $statusCell.html('<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2"><i class="fas fa-check-circle me-1"></i> TERVERIF</span><div class="verif-inline-note text-success fw-semibold"><i class="fas fa-bolt me-1"></i>' + message + '</div>');
        }

        $(document).on('submit', '.ajax-verif-form', function(e) {
            e.preventDefault();

            const form = this;
            const $form = $(form);
            const $row = $form.closest('tr');
            const $button = $form.find('button[type="submit"]');
            const confirmText = $button.data('confirm') || 'Proses verifikasi data ini?';
            const originalHtml = $button.html();

            if (!confirm(confirmText)) return;

            $row.addClass('verif-row-processing');
            $button.addClass('btn-loading').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...');
            $form.closest('td').find('button').not($button).prop('disabled', true);

            $.ajax({
                url: window.location.pathname,
                method: 'POST',
                data: $form.serialize() + '&act_save_db=1',
                dataType: 'json'
            }).done(function(resp) {
                if (resp && resp.success) {
                    markRowAsVerified($row, resp.message || 'Berhasil diverifikasi.');
                    showFloatingAlert(resp.message || 'Data berhasil diverifikasi.', 'success');
                    $form.closest('td').html('<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2"><i class="fas fa-check-circle me-1"></i> Sudah diverifikasi</span>');
                } else {
                    showFloatingAlert((resp && resp.message) ? resp.message : 'Verifikasi gagal diproses.', 'danger');
                    $button.removeClass('btn-loading').prop('disabled', false).html(originalHtml);
                    $form.closest('td').find('button').prop('disabled', false);
                }
            }).fail(function(xhr) {
                let msg = 'Verifikasi gagal. Silakan coba lagi.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    const clean = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                    if (clean) msg = 'Verifikasi gagal: ' + clean.substring(0, 160);
                }
                showFloatingAlert(msg, 'danger');
                $button.removeClass('btn-loading').prop('disabled', false).html(originalHtml);
                $form.closest('td').find('button').prop('disabled', false);
            }).always(function() {
                $row.removeClass('verif-row-processing');
            });
        });
    });
</script>
</body>
</html>