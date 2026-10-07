<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// ================================================================
// SETUP DIREKTORI & PATH
// ================================================================
$wa_download_dir = realpath(__DIR__ . '/../../whatsapp/wa-engine/downloads'); 
if (!$wa_download_dir) { $wa_download_dir = __DIR__ . '/../../whatsapp/wa-engine/downloads'; }

if (!function_exists('kasirWaDownloadFilePath')) {
    function kasirWaDownloadFilePath($dir, $file) {
        $file = basename((string)$file);
        return rtrim((string)$dir, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $file;
    }
}
if (!function_exists('kasirWaSafeFilemtime')) {
    function kasirWaSafeFilemtime($dir, $file) {
        $path = kasirWaDownloadFilePath($dir, $file);
        if (!is_file($path)) {
            return false;
        }
        $time = @filemtime($path);
        return $time ?: false;
    }
}

$gs_command = 'gswin64c'; 
$soffice_command = '"C:\Program Files\LibreOffice\program\soffice.exe"'; 
$wa_date_filter = isset($_GET['wa_date']) ? $_GET['wa_date'] : date('Y-m-d');


// ================================================================
// HELPER PIUTANG AKTIF: sumber utang resmi adalah nota penjualan
// yang masih pelunasan='N'. Kolom pelanggan.sisa_utang hanya cache
// lama dan tidak dipakai untuk jalur File WhatsApp.
// ================================================================
function kasirAmbilMapSisaUtangAktif(PDO $pdo): array {
    try {
        $sql = "SELECT kode_pelanggan, COALESCE(SUM(sisa_utang), 0) AS sisa_utang
                FROM (
                    SELECT
                        p.no_penjualan,
                        p.kode_pelanggan,
                        (
                            CASE
                                WHEN COALESCE(p.total_omzet, 0) > 0 THEN COALESCE(p.total_omzet, 0)
                                ELSE (
                                    SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0)
                                    FROM penjualan_item pi
                                    WHERE pi.no_penjualan = p.no_penjualan
                                )
                            END
                        ) - COALESCE(p.uang_bayar, 0) - COALESCE(p.nominal_deposit, 0) AS sisa_utang
                    FROM penjualan p
                    WHERE p.pelunasan = 'N'
                      AND p.kode_pelanggan IS NOT NULL
                      AND p.kode_pelanggan <> ''
                      AND p.kode_pelanggan <> 'UMUM'
                ) x
                WHERE x.sisa_utang >= 100
                GROUP BY kode_pelanggan";
        $map = [];
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['kode_pelanggan']] = (float)$row['sisa_utang'];
        }
        return $map;
    } catch (Exception $e) {
        return [];
    }
}

function kasirHitungSisaUtangAktif(PDO $pdo, string $kode_pelanggan): float {
    if ($kode_pelanggan === '' || strtoupper($kode_pelanggan) === 'UMUM') return 0;
    try {
        $sql = "SELECT COALESCE(SUM(sisa_utang), 0) AS sisa_utang
                FROM (
                    SELECT
                        p.no_penjualan,
                        (
                            CASE
                                WHEN COALESCE(p.total_omzet, 0) > 0 THEN COALESCE(p.total_omzet, 0)
                                ELSE (
                                    SELECT COALESCE(SUM((pi.harga_jual * pi.jumlah) - pi.diskon), 0)
                                    FROM penjualan_item pi
                                    WHERE pi.no_penjualan = p.no_penjualan
                                )
                            END
                        ) - COALESCE(p.uang_bayar, 0) - COALESCE(p.nominal_deposit, 0) AS sisa_utang
                    FROM penjualan p
                    WHERE p.pelunasan = 'N'
                      AND p.kode_pelanggan = ?
                ) x
                WHERE x.sisa_utang >= 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kode_pelanggan]);
        return max(0, (float)$stmt->fetchColumn());
    } catch (Exception $e) {
        return 0;
    }
}


// ================================================================
// AJAX: AMBIL DATA PELANGGAN TERBARU DARI DATABASE
// Dipakai saat memilih pelanggan dari File Desain WhatsApp supaya
// saldo deposit / sisa utang tidak memakai data lama dari tombol/cache.
// ================================================================
if (isset($_POST['action']) && $_POST['action'] == 'get_pelanggan_latest') {
    header('Content-Type: application/json');
    $kode = trim($_POST['kode_pelanggan'] ?? '');

    if ($kode === '' || strtoupper($kode) === 'UMUM') {
        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => 'UMUM',
                'nama' => 'Pelanggan Umum',
                'deposit' => 0,
                'utang' => 0,
                'telp' => ''
            ]
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT kode_pelanggan, nama_pelanggan, no_telepon, COALESCE(saldo_deposit,0) AS saldo_deposit FROM pelanggan WHERE kode_pelanggan = ? LIMIT 1");
        $stmt->execute([$kode]);
        $dt = $stmt->fetch(PDO::FETCH_ASSOC);
        $utangAktif = kasirHitungSisaUtangAktif($pdo, $kode);

        if (!$dt) {
            echo json_encode(['status' => 'error', 'message' => 'Pelanggan tidak ditemukan.']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'id' => $dt['kode_pelanggan'],
                'nama' => $dt['nama_pelanggan'],
                'deposit' => (float)$dt['saldo_deposit'],
                'utang' => (float)$utangAktif,
                'telp' => $dt['no_telepon'] ?? ''
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ================================================================
// 1. LOGIKA SIMPAN PELANGGAN BARU (DIGABUNG KE SINI)
// ================================================================
if (isset($_POST['action']) && ($_POST['action'] == 'check_nama_pelanggan' || $_POST['action'] == 'simpan_pelanggan_baru' || $_POST['action'] == 'update_pelanggan_lama')) {
    header('Content-Type: application/json');
    
    // A. Cek Nama Kembar
    if ($_POST['action'] == 'check_nama_pelanggan') {
        $nama = trim($_POST['nama']);
        $stmt = $pdo->prepare("SELECT * FROM pelanggan WHERE nama_pelanggan = ?");
        $stmt->execute([$nama]);
        $exist = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($exist) {
            echo json_encode(['status' => 'exist', 'data' => $exist]);
        } else {
            echo json_encode(['status' => 'not_exist']);
        }
        exit;
    }

    // B. Simpan Baru (ANTI DOUBLE / DUPLICATE FIX)
    if ($_POST['action'] == 'simpan_pelanggan_baru') {
        $nama = trim($_POST['nama']);
        $telp = trim($_POST['telp']);
        $lid  = trim($_POST['wa_lid']);
        $alamat = trim($_POST['alamat']);
        
        $kode_baru = '';
        $is_unique = false;
        $attempt = 0;

        while (!$is_unique && $attempt < 5) {
            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(kode_pelanggan, 2) AS UNSIGNED)) as max_kode FROM pelanggan WHERE kode_pelanggan LIKE 'P%'");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $max_db = ($row['max_kode']) ? (int)$row['max_kode'] : 0;
            
            $next_urutan = $max_db + 1 + $attempt;
            $kode_baru = 'P' . str_pad($next_urutan, 5, '0', STR_PAD_LEFT);

            $cek = $pdo->prepare("SELECT count(*) FROM pelanggan WHERE kode_pelanggan = ?");
            $cek->execute([$kode_baru]);
            
            if ($cek->fetchColumn() == 0) {
                $is_unique = true; 
            } else {
                $attempt++; 
            }
        }

        if (!$is_unique) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal membuat ID Pelanggan Unik. Silakan coba simpan lagi.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO pelanggan (kode_pelanggan, nama_pelanggan, no_telepon, wa_lid, alamat, saldo_deposit, sisa_utang) VALUES (?, ?, ?, ?, ?, 0, 0)");
            $stmt->execute([$kode_baru, $nama, $telp, $lid, $alamat]);
            echo json_encode(['status' => 'success', 'id' => $kode_baru, 'nama' => $nama, 'deposit' => 0, 'utang' => 0, 'telp' => $telp]);
        } catch (PDOException $e) { 
            if ($e->getCode() == '23000') {
                echo json_encode(['status' => 'error', 'message' => 'Terjadi bentrok ID. Mohon tekan Simpan sekali lagi.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
            }
        }
        exit;
    }

    // C. Update Pelanggan Lama
    if ($_POST['action'] == 'update_pelanggan_lama') {
        $kode = $_POST['kode_pelanggan'];
        $telp = trim($_POST['telp']);
        $lid  = trim($_POST['wa_lid']);
        $alamat = trim($_POST['alamat']);

        try {
            $sql = "UPDATE pelanggan SET no_telepon = ?, wa_lid = ?, alamat = ? WHERE kode_pelanggan = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$telp, $lid, $alamat, $kode]);

            $stmtGet = $pdo->prepare("SELECT * FROM pelanggan WHERE kode_pelanggan = ?");
            $stmtGet->execute([$kode]);
            $dt = $stmtGet->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success', 
                'id' => $dt['kode_pelanggan'], 
                'nama' => $dt['nama_pelanggan'], 
                'deposit' => $dt['saldo_deposit'], 
                'utang' => kasirHitungSisaUtangAktif($pdo, $dt['kode_pelanggan']), 
                'telp' => $dt['no_telepon']
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// ================================================================
// FUNGSI DETEKSI TINTA & HALAMAN
// ================================================================
function convertToPdf($filepath, $outdir) { global $soffice_command; if (!file_exists($filepath)) return false; $cmd = "$soffice_command --headless --convert-to pdf " . escapeshellarg($filepath) . " --outdir " . escapeshellarg($outdir); shell_exec($cmd); $info = pathinfo($filepath); $pdf_result = rtrim($outdir, '/\\') . DIRECTORY_SEPARATOR . $info['filename'] . '.pdf'; return file_exists($pdf_result) ? $pdf_result : false; }

function deteksiWarnaPDF($filepath, $pages = '') { 
    global $gs_command; 
    if (!file_exists($filepath)) return ['error' => 'File tidak ditemukan']; 
    
    $page_cmd = '';
    if (!empty($pages)) { $page_cmd = "-sPageList=" . escapeshellarg($pages) . " "; }

    $cmd = "$gs_command -q -o - $page_cmd -sDEVICE=inkcov " . escapeshellarg($filepath); 
    $output = shell_exec($cmd); 
    if (!$output) return ['error' => 'Gagal mengeksekusi Ghostscript (atau format halaman salah).']; 
    
    $hasil = ['total_halaman' => 0, 'bw' => 0, 'color_25' => 0, 'color_50' => 0, 'color_75' => 0, 'color_100' => 0, 'detail' => []]; 
    $lines = explode("\n", trim($output)); 
    foreach ($lines as $line) { 
        if (preg_match('/([0-9\.]+)\s+([0-9\.]+)\s+([0-9\.]+)\s+([0-9\.]+)\s+CMYK OK/', $line, $matches)) { 
            $hasil['total_halaman']++; 
            $c = (float)$matches[1]; $m = (float)$matches[2]; $y = (float)$matches[3]; 
            $max = max($c, $m, $y) * 100; $tot = ($c + $m + $y) * 100; 
            if ($tot <= 3.5 || $max <= 1.5) { 
                $hasil['bw']++; 
                $hasil['detail'][] = ['teks' => 'B/W', 'badge' => 'bg-secondary', 'cov' => round($max, 2)]; 
            } else { 
                if ($max <= 12) { 
                    $hasil['color_25']++; 
                    $hasil['detail'][] = ['teks' => 'Color 25%', 'badge' => 'bg-info', 'cov' => round($max, 2)]; 
                } 
                elseif ($max <= 30) { 
                    $hasil['color_50']++; 
                    $hasil['detail'][] = ['teks' => 'Color 50%', 'badge' => 'bg-primary', 'cov' => round($max, 2)]; 
                } 
                elseif ($max <= 50) { 
                    $hasil['color_75']++; 
                    $hasil['detail'][] = ['teks' => 'Color 75%', 'badge' => 'bg-warning', 'cov' => round($max, 2)]; 
                } 
                else { 
                    $hasil['color_100']++; 
                    $hasil['detail'][] = ['teks' => 'Color Blok', 'badge' => 'bg-danger', 'cov' => round($max, 2)]; 
                } 
            }
        } 
    } 
    return $hasil; 
}

function deteksiWarnaGambar($filepath, $ekstensi) { if (!extension_loaded('gd')) return ['error' => 'Modul GD belum aktif.']; @ini_set('memory_limit', '-1'); $hasil = ['total_halaman' => 1, 'bw' => 0, 'color_25' => 0, 'color_50' => 0, 'color_75' => 0, 'color_100' => 0, 'detail' => []]; if ($ekstensi == 'jpg' || $ekstensi == 'jpeg') { $img = @imagecreatefromjpeg($filepath); } elseif ($ekstensi == 'png') { $img = @imagecreatefrompng($filepath); } else { return ['error' => 'Format tidak didukung.']; } if (!$img) return ['error' => 'Gagal baca gambar.']; $w = imagesx($img); $h = imagesy($img); if ($w == 0 || $h == 0) return ['error' => 'Dimensi invalid.']; $new_w = 250; $new_h = floor($h * ($new_w / $w)); if ($new_h <= 0) $new_h = 1; $thumb = imagecreatetruecolor($new_w, $new_h); $white = imagecolorallocate($thumb, 255, 255, 255); imagefilledrectangle($thumb, 0, 0, $new_w, $new_h, $white); imagecopyresampled($thumb, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h); imagedestroy($img); $tot_px = $new_w * $new_h; $ink_px = 0; $color_px = 0; for ($x = 0; $x < $new_w; $x++) { for ($y = 0; $y < $new_h; $y++) { $rgb = imagecolorat($thumb, $x, $y); $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF; if ($r < 240 || $g < 240 || $b < 240) { $ink_px++; $max = max($r, $g, $b); $min = min($r, $g, $b); if (($max - $min) > 35) { $color_px++; } } } } imagedestroy($thumb); $ink_cov = ($tot_px > 0) ? ($ink_px / $tot_px) * 100 : 0; $col_rat = ($ink_px > 0) ? ($color_px / $ink_px) : 0; 
    if ($col_rat < 0.08) { 
        $hasil['bw'] = 1; 
        $hasil['detail'][] = ['teks' => 'B/W', 'badge' => 'bg-secondary', 'cov' => round($ink_cov, 2)]; 
    } else { 
        if ($ink_cov <= 12) { 
            $hasil['color_25'] = 1; 
            $hasil['detail'][] = ['teks' => 'Color 25%', 'badge' => 'bg-info', 'cov' => round($ink_cov, 2)]; 
        } 
        elseif ($ink_cov <= 30) { 
            $hasil['color_50'] = 1; 
            $hasil['detail'][] = ['teks' => 'Color 50%', 'badge' => 'bg-primary', 'cov' => round($ink_cov, 2)]; 
        } 
        elseif ($ink_cov <= 50) { 
            $hasil['color_75'] = 1; 
            $hasil['detail'][] = ['teks' => 'Color 75%', 'badge' => 'bg-warning', 'cov' => round($ink_cov, 2)]; 
        } 
        else { 
            $hasil['color_100'] = 1; 
            $hasil['detail'][] = ['teks' => 'Color Blok', 'badge' => 'bg-danger', 'cov' => round($ink_cov, 2)]; 
        } 
    } 
    return $hasil; 
} 

// ================================================================
// AJAX HANDLE REQUEST
// ================================================================
if (isset($_POST['action']) && ($_POST['action'] == 'hitung_rekomendasi' || $_POST['action'] == 'hitung_rekomendasi_manual')) {
    header('Content-Type: application/json');
    $pages_req = isset($_POST['pages']) ? preg_replace('/[^0-9,\-]/', '', $_POST['pages']) : '';

    if($_POST['action'] == 'hitung_rekomendasi') { 
        $filename = $_POST['filename']; 
        $filepath = $wa_download_dir . '/' . $filename; 
        if(!file_exists($filepath)) exit(json_encode(['status'=>'error','message'=>'File 404'])); 
        $target=$filepath; 
    } else { 
        if (!isset($_FILES['manual_file']) || $_FILES['manual_file']['error'] != 0) exit(json_encode(['status'=>'error','message'=>'Gagal upload'])); 
        $filename = $_FILES['manual_file']['name']; 
        $target = $_FILES['manual_file']['tmp_name']; 
    }
    
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (in_array($ext, ['jpg','jpeg','png'])) { 
        $res = deteksiWarnaGambar($target, $ext); 
    } elseif (in_array($ext, ['doc','docx'])) { 
        $tmp = sys_get_temp_dir(); 
        $doc = $tmp.'/'.time().'_'.preg_replace("/[^a-zA-Z0-9.]/","_",$filename); 
        if(copy($target,$doc) || move_uploaded_file($target,$doc)){ 
            $pdf = convertToPdf($doc,$tmp); 
            @unlink($doc); 
            if($pdf && file_exists($pdf)){ 
                $res = deteksiWarnaPDF($pdf, $pages_req); 
                @unlink($pdf); 
            } else { 
                $res=['error'=>'Gagal convert Word']; 
            } 
        } 
    } elseif ($ext=='pdf') { 
        $res = deteksiWarnaPDF($target, $pages_req); 
    } else { 
        exit(json_encode(['status'=>'error','message'=>'Format salah'])); 
    }
    
    if(isset($res['error'])) exit(json_encode(['status'=>'error','message'=>$res['error']]));

    // --- TAMBAHAN LOGIKA N-UP (MULTIPLE PAGES PER SHEET) ---
    $n_up = isset($_POST['n_up']) ? (int)$_POST['n_up'] : 1;
    if ($n_up > 1 && isset($res['total_halaman']) && $res['total_halaman'] > 0) {
        $total_hal_asli = $res['total_halaman'];
        // Hitung total lembar fisik yang dibutuhkan
        $total_lembar = ceil($total_hal_asli / $n_up);
        
        // Sesuaikan proporsi persentase tinta secara merata
        $ratio = $total_lembar / $total_hal_asli;
        $res['bw'] = (int)round($res['bw'] * $ratio);
        $res['color_25'] = (int)round($res['color_25'] * $ratio);
        $res['color_50'] = (int)round($res['color_50'] * $ratio);
        $res['color_75'] = (int)round($res['color_75'] * $ratio);
        $res['color_100'] = (int)round($res['color_100'] * $ratio);
        
        // Koreksi pembulatan agar totalnya pas persis dengan $total_lembar
        $sum_adjusted = $res['bw'] + $res['color_25'] + $res['color_50'] + $res['color_75'] + $res['color_100'];
        $diff = $total_lembar - $sum_adjusted;
        if ($diff != 0) {
            // Jika ada sisa selisih kertas akibat pembulatan, masukkan ke BW (karena paling umum)
            if ($res['bw'] >= 0) { $res['bw'] += $diff; } 
            else { $res['color_100'] += $diff; }
        }
        
        // Pastikan tidak ada angka minus
        foreach(['bw', 'color_25', 'color_50', 'color_75', 'color_100'] as $k) {
            if($res[$k] < 0) $res[$k] = 0;
        }
        
        $res['total_halaman'] = $total_lembar; // Timpa total halaman jadi total kertas fisik
        $res['is_nup'] = true;
        $res['nup_val'] = $n_up;
        $res['total_asli'] = $total_hal_asli;
    } else {
        $res['is_nup'] = false;
    }
    // ---------------------------------------------------------
    
    $db_barang=[]; 
    $stmt=$pdo->query("SELECT * FROM barang WHERE nama_barang LIKE 'print%' OR nama_barang LIKE 'fc%' OR nama_barang LIKE 'fotocopy%'"); 
    while($r=$stmt->fetch(PDO::FETCH_ASSOC)){ $r['grosir']=[]; $db_barang[strtolower(trim($r['nama_barang']))]=$r; }
    
    try{ 
        $sg=$pdo->query("SELECT * FROM barang_grosir"); 
        while($g=$sg->fetch(PDO::FETCH_ASSOC)){ 
            foreach($db_barang as $n=>$d){ 
                if($d['kode_barang']==$g['kode_barang']) $db_barang[$n]['grosir'][]=$g; 
            } 
        } 
    } catch(Exception $e){}
    
    $kertas=[
        ['kode'=>'A4 70','nama'=>'Kertas A4 70'],
        ['kode'=>'A4 80','nama'=>'Kertas A4 80'],
        ['kode'=>'F4 70','nama'=>'Kertas F4 70'],
        ['kode'=>'F4 80','nama'=>'Kertas F4 80'],
        ['kode'=>'A5 70','nama'=>'Kertas A5 70'], 
        ['kode'=>'A5 80','nama'=>'Kertas A5 80'],
        ['kode'=>'B5 70','nama'=>'Kertas B5 70']
    ];
     
    $rekom = [];
    $rekom_bw = []; // Wadah baru khusus tab Hitam Putih
    $rekom_fc = [];
    $total_hal = $res['total_halaman'] ?? 1;

    foreach($kertas as $uk){ 
        $k=strtolower($uk['kode']); 
        
        // 1. Tab Print (Auto Deteksi Campur Warna)
        $pl=[]; $tot=0; 
        $tinta=[
            ['q'=>$res['bw'],'n'=>"print hitam $k"],
            ['q'=>$res['color_25'],'n'=>"print warna 25% $k"],
            ['q'=>$res['color_50'],'n'=>"print warna 50% $k"],
            ['q'=>$res['color_75'],'n'=>"print warna 75% $k"],
            ['q'=>$res['color_100'],'n'=>"print warna 100% $k"]
        ];
        foreach($tinta as $jt){ 
            if($jt['q']>0 && isset($db_barang[$jt['n']])){ 
                $b=$db_barang[$jt['n']]; 
                $pr=(float)$b['harga_jual']; 
                $sub=$jt['q']*$pr; $tot+=$sub; 
                $pl[]=['kode'=>$b['kode_barang'],'nama'=>$b['nama_barang'],'qty_base'=>$jt['q'],'data_barang'=>$b]; 
            } 
        } 
        if($tot>0) {
            $rekom[]=[
                'nama_kertas' => $uk['nama'],
                'total_rp_base' => $tot,
                'cart_items_json' => json_encode($pl) 
            ]; 
        }

        // 2. Tab Print B/W (Hitam Putih Semua Halaman)
        $n_bw = "print hitam $k";
        if(isset($db_barang[$n_bw]) && $total_hal > 0) {
            $b_bw = $db_barang[$n_bw];
            $tot_bw = $total_hal * (float)$b_bw['harga_jual'];
            $pl_bw = [['kode'=>$b_bw['kode_barang'],'nama'=>$b_bw['nama_barang'],'qty_base'=>$total_hal,'data_barang'=>$b_bw]];
            
            $rekom_bw[]=[
                'nama_kertas' => $uk['nama'] . ' (B/W)',
                'total_rp_base' => $tot_bw,
                'cart_items_json' => json_encode($pl_bw)
            ];
        }

        // 3. Tab Fotocopy
        $n_fc_1 = "fotocopy $k";
        $n_fc_2 = "fotocopy $k dupleks";
        
        if(isset($db_barang[$n_fc_1]) && $total_hal > 0) {
            $b1 = $db_barang[$n_fc_1];
            $tot_fc_1 = $total_hal * (float)$b1['harga_jual'];
            $pl_fc_1 = [['kode'=>$b1['kode_barang'],'nama'=>$b1['nama_barang'],'qty_base'=>$total_hal,'data_barang'=>$b1]];
            
            $tot_fc_2 = 0;
            $pl_fc_2 = [];
            $ada_dupleks = false;
            
            if(isset($db_barang[$n_fc_2])) {
                $ada_dupleks = true;
                $b2 = $db_barang[$n_fc_2];
                $tot_fc_2 = $total_hal * (float)$b2['harga_jual'];
                $pl_fc_2 = [['kode'=>$b2['kode_barang'],'nama'=>$b2['nama_barang'],'qty_base'=>$total_hal,'data_barang'=>$b2]];
            }
            
            $rekom_fc[] = [
                'nama_kertas' => 'FC ' . strtoupper($k),
                'rp_1sisi' => $tot_fc_1,
                'json_1sisi' => json_encode($pl_fc_1),
                'ada_dupleks' => $ada_dupleks,
                'rp_2sisi' => $tot_fc_2,
                'json_2sisi' => json_encode($pl_fc_2)
            ];
        }
    }
    
    // Jangan lupa update response json_encode agar rekomendasi_bw terkirim
    echo json_encode(['status'=>'success','deteksi'=>$res,'rekomendasi'=>$rekom,'rekomendasi_bw'=>$rekom_bw,'rekomendasi_fc'=>$rekom_fc]); exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'simpan_ke_pelanggan_lama') {
    header('Content-Type: application/json');
    $kode_pelanggan = $_POST['kode_pelanggan'] ?? ''; $nomor_baru = $_POST['nomor_baru'] ?? '';
    if (empty($kode_pelanggan) || empty($nomor_baru)) { echo json_encode(['status' => 'error', 'message' => 'Data tidak lengkap.']); exit; }
    try {
        $clean_num = preg_replace('/[^0-9]/', '', $nomor_baru);
        if (strpos($nomor_baru, '@lid') !== false || strlen($clean_num) >= 15) {
            $stmt = $pdo->prepare("UPDATE pelanggan SET wa_lid = ? WHERE kode_pelanggan = ?"); $msg = "WA LID berhasil ditautkan.";
        } else {
            $stmt = $pdo->prepare("UPDATE pelanggan SET no_telepon = ? WHERE kode_pelanggan = ?"); $msg = "Nomor HP berhasil diperbarui.";
        }
        if ($stmt->execute([$clean_num, $kode_pelanggan])) { echo json_encode(['status' => 'success', 'message' => $msg]); } else { echo json_encode(['status' => 'error', 'message' => 'Gagal update database.']); }
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]); } exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'cek_wa_baru_html') {
    header('Content-Type: application/json');
    $filter_date = $_POST['wa_date'] ?? date('Y-m-d');

    // --- LOGIKA FIX: Ambil Waktu Transaksi TERAKHIR per Pelanggan Hari Ini ---
    $stmt_trx = $pdo->prepare("SELECT kode_pelanggan, MAX(jam) as max_jam FROM penjualan WHERE DATE(tgl_penjualan) = ? AND kode_pelanggan != 'UMUM' GROUP BY kode_pelanggan");
    $stmt_trx->execute([$filter_date]);
    $trx_today = [];
    while($row = $stmt_trx->fetch(PDO::FETCH_ASSOC)) {
        // Konversi ke timestamp agar bisa membandingkan jam/menit/detik
        $trx_today[$row['kode_pelanggan']] = strtotime($filter_date . ' ' . $row['max_jam']);
    }
     
    $db_map = []; 
    $utangAktifMap = kasirAmbilMapSisaUtangAktif($pdo);
    $stmt_hp = $pdo->query("SELECT kode_pelanggan, nama_pelanggan, no_telepon, wa_lid, COALESCE(saldo_deposit, 0) as saldo_deposit FROM pelanggan WHERE kode_pelanggan != 'UMUM'");
    while($r = $stmt_hp->fetch(PDO::FETCH_ASSOC)){
        $d = [
            'kode' => $r['kode_pelanggan'], 'nama' => $r['nama_pelanggan'], 
            'deposit' => (float)$r['saldo_deposit'], 'utang' => (float)($utangAktifMap[$r['kode_pelanggan']] ?? 0),
            'no_wa_asli' => $r['no_telepon'] 
        ];
        $hpc = preg_replace('/[^0-9]/', '', $r['no_telepon']); 
        if (substr($hpc, 0, 1) == '0') $hpc = '62' . substr($hpc, 1); 
        if (!empty($hpc)) $db_map[$hpc] = $d;
        if (!empty($r['wa_lid'])) { 
            $db_map[trim($r['wa_lid'])] = $d; 
            $lid_clean = preg_replace('/[^0-9]/', '', $r['wa_lid']); 
            if(!empty($lid_clean)) $db_map[$lid_clean] = $d; 
        }
    }

    $ajax_wa_files = [];
    if (is_dir($wa_download_dir)) {
        $scanned_wa = scandir($wa_download_dir);
        foreach ($scanned_wa as $file) {
            if ($file !== '.' && $file !== '..') {
                if (stripos($file, 'status') === false && stripos($file, 'broadcast') === false) {
                    $file_time = kasirWaSafeFilemtime($wa_download_dir, $file);
                    if ($file_time === false) { continue; }
                    if (date('Y-m-d', $file_time) === $filter_date) {
                        $parts = explode('_', $file);
                        if (count($parts) >= 3 && strtoupper($parts[0]) === 'WA') {
                            $identitas_raw = $parts[1];
                            $match_id = $identitas_raw;
                            $found_in_db = false;

                            if (isset($db_map[$identitas_raw])) {
                                $match_id = $identitas_raw;
                            } else {
                                $numeric_id = preg_replace('/[^0-9]/', '', $identitas_raw);
                                if (!empty($numeric_id) && isset($db_map[$numeric_id])) {
                                    $match_id = $numeric_id;
                                } elseif (!empty($numeric_id) && substr($numeric_id, 0, 1) == '0' && isset($db_map['62'.substr($numeric_id, 1)])) {
                                    $match_id = '62'.substr($numeric_id, 1);
                                }
                            }
                            $nama_asli = (count($parts) >= 4) ? implode('_', array_slice($parts, 3)) : $file;
                            $ajax_wa_files[$match_id][] = ['name' => $file, 'time' => $file_time, 'nama_asli' => $nama_asli];
                        }
                    }
                }
            }
        }
    }

    $ajax_chat_baru = [];
    foreach ($ajax_wa_files as $identitas => $files) {
        $is_reg = isset($db_map[$identitas]);
        $kode_pelanggan = $is_reg ? $db_map[$identitas]['kode'] : null;
        
        // --- LOGIKA FILTER FILE BARU ---
        $filtered_files = [];
        foreach ($files as $wf) {
            // Jika pelanggan sudah punya transaksi hari ini
            if ($is_reg && isset($trx_today[$kode_pelanggan])) {
                // File dimunculkan HANYA JIKA masuk setelah transaksi terakhir (+toleransi 60 detik)
                if ($wf['time'] > ($trx_today[$kode_pelanggan] - 60)) {
                    $filtered_files[] = $wf;
                }
            } else {
                // Belum ada transaksi hari ini, munculkan semua file
                $filtered_files[] = $wf;
            }
        }

        // Jika tidak ada file baru yang tersisa, lewati pelanggan ini
        if (empty($filtered_files)) {
            continue; 
        }
        // --------------------------------

        $ajax_chat_baru[] = [
            'nomor' => $identitas, 
            'nama' => $is_reg ? $db_map[$identitas]['nama'] : $identitas, 
            'kode_pelanggan' => $kode_pelanggan, 
            'deposit' => $is_reg ? $db_map[$identitas]['deposit'] : 0, 
            'utang' => $is_reg ? $db_map[$identitas]['utang'] : 0, 
            'no_wa_asli' => $is_reg ? $db_map[$identitas]['no_wa_asli'] : '',
            'is_registered' => $is_reg, 
            'total_file' => count($filtered_files), 
            'waktu_terbaru' => max(array_column($filtered_files, 'time')), 
            'files' => $filtered_files
        ];
    }
    usort($ajax_chat_baru, function($a, $b) { return $b['waktu_terbaru'] - $a['waktu_terbaru']; }); 
    $hash = md5(json_encode($ajax_chat_baru));

    ob_start();
    if(empty($ajax_chat_baru)) { 
        echo '<div class="p-4 text-center text-muted small"><i class="fas fa-inbox fa-2x mb-2 opacity-50"></i><p>Tidak ada chat file terbaru yang perlu diproses.</p></div>'; 
    } else {
        foreach($ajax_chat_baru as $idx => $cb) {
            // FIX STRING UNTUK JAVASCRIPT
            $id = isset($cb['kode_pelanggan']) ? $cb['kode_pelanggan'] : '';
            $telp = isset($cb['no_wa_asli']) ? $cb['no_wa_asli'] : '';
            $depo = (float)$cb['deposit'];
            $utang = (float)$cb['utang'];
            $nama_js = htmlspecialchars(addslashes(trim(preg_replace('/\s+/', ' ', $cb['nama']))), ENT_QUOTES);
            $nama_bersih = htmlspecialchars($cb['nama']);

            echo '<div class="accordion-item border-bottom" data-pa-wa-client="'.htmlspecialchars($id ?: $cb['nomor'], ENT_QUOTES).'" data-pa-wa-name="'.$nama_bersih.'"><h2 class="accordion-header" id="headingWaFront'.$idx.'"><button class="accordion-button collapsed px-3 py-2 bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWaFront'.$idx.'"><div class="d-flex justify-content-between w-100 me-2"><div><div class="fw-bold text-success" style="font-size:0.85rem;">'.$nama_bersih.'</div><small class="text-muted" style="font-size:0.7rem;">'.$cb['total_file'].' file baru ('.date('H:i', $cb['waktu_terbaru']).')</small></div></div></button></h2><div id="collapseWaFront'.$idx.'" class="accordion-collapse collapse" data-bs-parent="#accordionWaFilesFront"><div class="accordion-body p-2" style="background:#fafafa;"><div class="d-flex justify-content-between align-items-center mb-2 px-2">';
            
            if(!$cb['is_registered']) { 
                echo '<div class="btn-group w-100"><button type="button" class="btn btn-sm btn-success fw-bold shadow-sm" onclick="daftarkanNomorWa(\''.$cb['nomor'].'\', \''.$nama_js.'\')"><i class="fas fa-user-plus me-1"></i> Baru</button><button type="button" class="btn btn-sm btn-warning fw-bold shadow-sm" onclick="bukaModalSimpanKeLama(\''.$cb['nomor'].'\')"><i class="fas fa-link me-1"></i> Link</button></div>'; 
            } else { 
                echo "<button type='button' class='btn btn-sm btn-primary py-1 fw-bold shadow-sm' onclick='setPelangganAktif(\"{$id}\", \"{$nama_js}\", \"{$telp}\", {$depo}, {$utang})'><i class='fas fa-check-circle me-1'></i> Pilih Pelanggan</button>"; 
            }

            $groupKey = 'waGrp'.$idx;
            echo '<div class="d-flex gap-2 align-items-center px-2 mb-2">'
                .'<button type="button" class="btn btn-sm btn-outline-secondary fw-bold" onclick="waToggleGroup(\''.$groupKey.'\', true)"><i class="fas fa-check-square me-1"></i>Pilih Semua</button>'
                .'<button type="button" class="btn btn-sm btn-outline-secondary fw-bold" onclick="waToggleGroup(\''.$groupKey.'\', false)"><i class="far fa-square me-1"></i>Clear</button>'
                .'<button type="button" class="btn btn-sm btn-warning fw-bold ms-auto" onclick="openPrintAnalyzerSelectedWa(this, \''.$groupKey.'\', \''.$id.'\', \''.$nama_js.'\', \''.$telp.'\', '.$depo.', '.$utang.')"><i class="fas fa-layer-group me-1"></i>Analyzer Terpilih</button>'
                .'</div>';

            echo '</div><ul class="list-group list-group-flush border rounded shadow-sm">';
            $cb_files = $cb['files']; 
            usort($cb_files, function($a, $b) { return $b['time'] - $a['time']; });
            foreach($cb_files as $wf) { 
                $wfNameAttr = htmlspecialchars($wf['name'], ENT_QUOTES);
                $wfOrigAttr = htmlspecialchars($wf['nama_asli'], ENT_QUOTES);
                echo '<li class="list-group-item px-2 py-2 d-flex justify-content-between align-items-start">
                        <div class="d-flex align-items-start flex-grow-1 me-2">
                            <input class="form-check-input mt-1 me-2 wa-file-check wa-check-'.$groupKey.'" type="checkbox" data-group="'.$groupKey.'" data-filename="'.$wfNameAttr.'" data-original="'.$wfOrigAttr.'" title="Pilih untuk dianalisis bersama">
                            <div class="text-break flex-grow-1" style="font-size:0.8rem; line-height:1.25;">
                                <div><i class="fas fa-file-alt text-secondary me-1"></i>'.htmlspecialchars($wf['nama_asli']).'</div>
                                <div class="text-muted mt-1" style="font-size:0.72rem;"><i class="far fa-clock me-1"></i>Jam masuk: '.date('H:i', (int)$wf['time']).'</div>
                            </div>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button type="button" class="btn btn-sm btn-warning btn-rekom-wa fw-bold py-1 px-2" 
                                    data-filename="'.$wfNameAttr.'"
                                    title="Analisis file WhatsApp di Print Analyzer"
                                    onclick="openPrintAnalyzerFromWhatsApp(\''.htmlspecialchars(addslashes($wf['name'])).'\', \''.htmlspecialchars(addslashes($wf['nama_asli'])).'\', \''.$id.'\', \''.$nama_js.'\', \''.$telp.'\', '.$depo.', '.$utang.')">
                                <i class="fas fa-magic"></i> Analyzer
                            </button>
                            <a href="../../whatsapp/wa-engine/downloads/'.rawurlencode($wf['name']).'" target="_blank" class="btn btn-sm btn-info text-white py-1 px-3">File</a>
                        </div>
                    </li>'; 
            }
            echo '</ul></div></div></div>';
        }
    }
    $html_modal = ob_get_clean(); 
    echo json_encode(['status' => 'success', 'count' => count($ajax_chat_baru), 'html_modal' => $html_modal, 'hash' => $hash]); 
    exit;
}

// ================================================================
// LOGIKA LOAD AWAL (PHP UTAMA)
// ================================================================
$wa_date_filter = isset($_GET['wa_date']) ? $_GET['wa_date'] : date('Y-m-d');

// --- LOGIKA FIX AWAL: Ambil Waktu Transaksi TERAKHIR per Pelanggan Hari Ini ---
$stmt_trx_init = $pdo->prepare("SELECT kode_pelanggan, MAX(jam) as max_jam FROM penjualan WHERE DATE(tgl_penjualan) = ? AND kode_pelanggan != 'UMUM' GROUP BY kode_pelanggan");
$stmt_trx_init->execute([$wa_date_filter]);
$trx_today_init = [];
while($row = $stmt_trx_init->fetch(PDO::FETCH_ASSOC)) {
    $trx_today_init[$row['kode_pelanggan']] = strtotime($wa_date_filter . ' ' . $row['max_jam']);
}

$all_wa_files = []; 
if (is_dir($wa_download_dir)) { 
    $scanned_wa = scandir($wa_download_dir); 
    foreach ($scanned_wa as $file) { 
        if ($file !== '.' && $file !== '..') { 
            if (stripos($file, 'status') === false && stripos($file, 'broadcast') === false) { 
                $file_time = kasirWaSafeFilemtime($wa_download_dir, $file); 
                if ($file_time === false) { continue; }
                if (date('Y-m-d', $file_time) === $wa_date_filter) { 
                    $parts = explode('_', $file); 
                    if (count($parts) >= 3 && strtoupper($parts[0]) === 'WA') { 
                        $all_wa_files[] = ['name' => $file, 'time' => $file_time, 'parts' => $parts]; 
                    } 
                } 
            } 
        } 
    } 
}

$db_map_init = []; 
$utangAktifMapInit = kasirAmbilMapSisaUtangAktif($pdo);
$stmt_hp = $pdo->query("SELECT kode_pelanggan, nama_pelanggan, no_telepon, wa_lid, COALESCE(saldo_deposit, 0) as saldo_deposit FROM pelanggan WHERE kode_pelanggan != 'UMUM'");
while($r = $stmt_hp->fetch(PDO::FETCH_ASSOC)){ 
    $data_pelanggan = ['kode' => $r['kode_pelanggan'], 'nama' => $r['nama_pelanggan'], 'deposit' => (float)$r['saldo_deposit'], 'utang' => (float)($utangAktifMapInit[$r['kode_pelanggan']] ?? 0), 'no_wa_asli' => $r['no_telepon']]; 
    $hpc = preg_replace('/[^0-9]/', '', $r['no_telepon']); 
    if (substr($hpc, 0, 1) == '0') $hpc = '62' . substr($hpc, 1); 
    if (!empty($hpc)) $db_map_init[$hpc] = $data_pelanggan; 
    if (!empty($r['wa_lid'])) { 
        $db_map_init[trim($r['wa_lid'])] = $data_pelanggan;
        $lid_clean = preg_replace('/[^0-9]/', '', $r['wa_lid']); 
        if(!empty($lid_clean)) $db_map_init[$lid_clean] = $data_pelanggan; 
    } 
}

$grouped_files = []; 
foreach($all_wa_files as $f) { 
    $parts = $f['parts']; 
    $identitas_raw = $parts[1]; 
    $match_id = $identitas_raw;
    if (isset($db_map_init[$identitas_raw])) {
        $match_id = $identitas_raw;
    } else {
        $numeric_id = preg_replace('/[^0-9]/', '', $identitas_raw);
        if (!empty($numeric_id) && isset($db_map_init[$numeric_id])) {
            $match_id = $numeric_id;
        } elseif (!empty($numeric_id) && substr($numeric_id, 0, 1) == '0' && isset($db_map_init['62'.substr($numeric_id, 1)])) {
            $match_id = '62'.substr($numeric_id, 1);
        }
    }
    $nama_asli = (count($parts) >= 4) ? implode('_', array_slice($parts, 3)) : $f['name']; 
    $grouped_files[$match_id][] = ['name' => $f['name'], 'time' => $f['time'], 'nama_asli' => $nama_asli]; 
}

$list_chat_baru = []; 
foreach ($grouped_files as $identitas => $files) { 
    $is_reg = isset($db_map_init[$identitas]); 
    
    $kode_pel = $is_reg ? $db_map_init[$identitas]['kode'] : null;
    
    // --- LOGIKA FILTER FILE BARU (PHP AWAL) ---
    $filtered_files = [];
    foreach ($files as $wf) {
        if ($is_reg && isset($trx_today_init[$kode_pel])) {
            if ($wf['time'] > ($trx_today_init[$kode_pel] - 60)) {
                $filtered_files[] = $wf;
            }
        } else {
            $filtered_files[] = $wf;
        }
    }

    if (empty($filtered_files)) {
        continue; 
    }
    // --------------------------------

    $list_chat_baru[] = ['nomor' => $identitas, 'nama' => $is_reg ? $db_map_init[$identitas]['nama'] : $identitas, 'kode_pelanggan' => $kode_pel, 'deposit' => $is_reg ? $db_map_init[$identitas]['deposit'] : 0, 'utang' => $is_reg ? $db_map_init[$identitas]['utang'] : 0, 'no_wa_asli' => $is_reg ? $db_map_init[$identitas]['no_wa_asli'] : '', 'is_registered' => $is_reg, 'total_file' => count($filtered_files), 'waktu_terbaru' => max(array_column($filtered_files, 'time')), 'files' => $filtered_files]; 
}
usort($list_chat_baru, function($a, $b) { return $b['waktu_terbaru'] - $a['waktu_terbaru']; }); 
$total_chat_baru = count($list_chat_baru); 
$initial_hash = md5(json_encode($list_chat_baru));

$kategori = []; 
try { $stmt = $pdo->query("SELECT * FROM kategori ORDER BY nama_kategori ASC"); if($stmt) $kategori = $stmt->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) { }

$shortcuts = []; 
$map_listener = []; 
function normalizeShortcutKasir($label) {
    $label = strtoupper(trim((string)$label));
    if ($label === '' || $label === 'NONE') return '';
    $label = str_replace([' ', '-'], '', $label);
    if ($label === 'DEL') $label = 'DELETE';
    if ($label === 'INS') $label = 'INSERT';
    if ($label === 'PGUP') $label = 'PAGEUP';
    if ($label === 'PGDN') $label = 'PAGEDOWN';
    return $label;
}
try { 
    $stmt_sc = $pdo->query("SHOW TABLES LIKE 'settings_shortcut'"); 
    if($stmt_sc->rowCount() > 0) { 
        // KASIR & TRANSAKSI diprioritaskan agar F10 tidak kalah oleh shortcut lama seperti cek_stok.
        $stmt_data = $pdo->query("SELECT kode_aksi, tombol, kategori FROM settings_shortcut ORDER BY CASE WHEN kategori = 'KASIR & TRANSAKSI' THEN 1 ELSE 2 END, id ASC"); 
        while($row = $stmt_data->fetch(PDO::FETCH_ASSOC)) { 
            $shortcuts[$row['kode_aksi']] = $row['tombol']; 
            $tombolShortcut = normalizeShortcutKasir($row['tombol']);
            if ($tombolShortcut !== '' && !isset($map_listener[$tombolShortcut])) {
                $map_listener[$tombolShortcut] = $row['kode_aksi']; 
            }
        } 
    } 
} catch(Exception $e) { }

function getLabel($arr, $key, $def) { return isset($arr[$key]) ? $arr[$key] : $def; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kasir Modern | POS System</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intro.js@7.2.0/minified/introjs.min.css">

    <style>
        :root {
            --primary-color: #1a56db;
            --primary-hover: #1e40af;
            --bg-color: #f9fafb;
            --border-color: #e5e7eb;
            --text-dark: #111827;
            --text-muted: #6b7280;
            --radius-md: 12px;
            --radius-lg: 16px;
            --cart-width: 420px;
        }

        body { margin: 0; padding: 0; overflow: hidden; height: 100vh; background-color: var(--bg-color); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .wrapper { display: flex; width: 100vw; height: 100vh; overflow: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; height: 100vh; overflow: hidden; background: #fff;}
        
        /* HEADER MODERN */
        .header-top { height: 70px; background-color: #fff; padding: 0 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; z-index: 1020; }
        
        /* POS CONTAINER */
        .pos-container { flex: 1; display: flex; overflow: hidden; background: var(--bg-color); }
        
        /* KIRI: CATALOG SECTION */
        .catalog-section { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; background: #f0f2f5; }

        /* KANAN: CART SECTION */
        .cart-section { width: var(--cart-width); flex-shrink: 0; background: #fff; border-left: 1px solid var(--border-color); display: flex; flex-direction: column; height: 100%; z-index: 100; overflow-y: auto; overflow-x: hidden; overscroll-behavior: contain; }
        
        /* Kustomisasi Keranjang Kanan */
        .cart-header { padding: 15px; border-bottom: 1px solid var(--border-color); flex-shrink: 0; background: #fff; position: sticky; top: 0; z-index: 5; }
        .cart-items { flex: 0 0 auto; min-height: 38vh; overflow: visible; padding: 0; background-color: #fff; }
        .cart-footer { padding: 12px 15px; background: #f8f9fa; flex: 0 0 auto; border-top: 1px solid var(--border-color); max-height: none; overflow: visible; overscroll-behavior: auto; }
        .cart-section::-webkit-scrollbar { width: 8px; }
        .cart-section::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .cart-section::-webkit-scrollbar-track { background: #f1f5f9; }
        .cart-line-main { flex: 1 1 auto; min-width: 0; padding-right: 8px; }
        .cart-line-side { width: 142px; min-width: 142px; }
        .cart-product-title { font-size:0.82rem; line-height:1.18; word-break: break-word; overflow-wrap:anywhere; }
        /* Patch: scroll keranjang dibuat satu saja dari atas sampai form pembayaran */
        @media (min-width: 769px) {
            .cart-section { max-width: var(--cart-width); }
            .cart-items { flex-grow: 0; }
            .cart-footer { flex-shrink: 0; }
        }
        @media (max-height: 760px) and (min-width: 769px) {
            .cart-items { min-height: 32vh; }
        }


        /* Item di Keranjang */
        .list-group-item { border-left: 0; border-right: 0; border-radius: 0; border-bottom: 1px dashed var(--border-color); padding: 12px 15px;}
        .input-compact { font-size: 0.8rem; padding: 4px 8px; height: 30px; text-align: right; font-weight: bold; border-radius: 6px; border: 1px solid var(--border-color); outline: none;}
        .input-compact:focus { border-color: var(--primary-color); }
        .total-text { font-size: 1.5rem; font-weight: 800; color: var(--primary-color); text-align: right; line-height: 1.2; }
        
        /* Box Pencarian / Kategori Kiri */
        .card-search { background: #fff; border-radius: var(--radius-md); box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid var(--border-color); }
        .input-group-search input { background: #f9fafb; border-color: var(--border-color); }
        .input-group-search input:focus { border-color: var(--primary-color); background: #fff; box-shadow: none; }
        .input-group-search .input-group-text { background: #f9fafb; border-color: var(--border-color); color: var(--text-muted); }

        /* Grid Produk */
        .product-card { border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; transition: transform 0.1s, box-shadow 0.2s; cursor: pointer; background: #fff; display: flex; flex-direction: column; height: 100%; position: relative;}
        .product-card.active-key { transform: scale(0.95); border-color: #ffc107 !important; box-shadow: 0 0 10px rgba(255, 193, 7, 0.5); }
        .product-card:hover { border-color: var(--primary-color); box-shadow: 0 8px 15px rgba(0,0,0,0.05); transform: translateY(-2px); }
        
        .product-img-placeholder { height: 110px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: #d1d5db; position: relative;}
        .badge-cat { position: absolute; top: 8px; right: 8px; background: rgba(107, 114, 128, 0.85); color: #fff; font-size: 0.65rem; padding: 3px 8px; border-radius: 20px; font-weight: 600; }
        
        .card-body-custom { padding: 12px; display: flex; flex-direction: column; flex-grow: 1; }
        .product-name { font-weight: 700; font-size: 0.85rem; color: var(--text-dark); margin-bottom: 3px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; }
        .product-sku { font-size: 0.7rem; color: var(--text-muted); margin-bottom: 8px; }
        
        .product-bottom { display: flex; justify-content: space-between; align-items: flex-end; margin-top: auto; }
        .product-price { font-weight: 800; font-size: 0.95rem; color: var(--primary-color); margin-bottom: 0;}
        .product-stok { font-size: 0.7rem; color: var(--text-muted); }
        
        .btn-add-circle { width: 32px; height: 32px; border-radius: 8px; background: var(--primary-color); color: white; border: none; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; transition: background 0.2s; }
        .btn-add-circle:hover { background: var(--primary-hover); }

        .product-key { position: absolute; top: 8px; left: 8px; background: rgba(0,0,0,0.6); color: #ffc107; width: auto; height: auto; padding: 2px 6px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.7rem; z-index: 10; font-family: monospace; pointer-events: none; }
        
        /* List Mode View adjustments */
        #productContainer.view-list .col-6, #productContainer.view-list .col-md-4, #productContainer.view-list .col-lg-3 { width: 100% !important; }
        #productContainer.view-list .product-card { display: flex; flex-direction: row; align-items: center; padding: 8px; height: auto; }
        #productContainer.view-list .product-img-placeholder { width: 50px; height: 50px; border-radius: 8px; font-size: 1.5rem; margin-right: 15px; flex-shrink: 0; }
        #productContainer.view-list .badge-cat, #productContainer.view-list .product-key { display: none; }
        #productContainer.view-list .card-body-custom { padding: 0; flex-direction: row; justify-content: space-between; align-items: center; }
        #productContainer.view-list .product-name { margin-bottom: 0; }
        #productContainer.view-list .product-bottom { align-items: center; }
        #productContainer.view-list .product-price { margin-right: 15px; font-size: 1.1rem;}

        /* Select2 override */
        .select2-container--bootstrap-5 .select2-selection { border-color: var(--border-color); border-radius: 6px; font-size: 0.85rem; min-height: 32px; }
        
        /* Floating Notification Toast */
        #customToast { position: fixed; top: 90px; right: 20px; background: #e0e7ff; border-left: 4px solid var(--primary-color); border-radius: 8px; padding: 12px 20px; display: flex; align-items: center; gap: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); transform: translateX(150%); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 1050; }
        #customToast.show { transform: translateX(0); }
        #customToast .icon-circle { width: 24px; height: 24px; background: var(--primary-color); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; }
        #customToast .toast-text { font-weight: 600; font-size: 0.9rem; color: var(--text-dark); margin: 0; }

        /* Other Original Elements */
        .kbd-badge { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 0.7rem; font-weight: bold; background: rgba(0,0,0,0.05); color: #666; padding: 1px 5px; border-radius: 3px; pointer-events: none; z-index: 5; border: 1px solid #ccc; font-family: monospace; }
        .btn .kbd-badge { position: static; margin-left: 5px; transform: none; background: rgba(255,255,255,0.25); color: #fff; border: 1px solid rgba(255,255,255,0.4); font-size: 0.65rem; padding: 0px 4px; }
        #step-bayar .kbd-badge { left: 8px; right: auto; color: #888; }
        .btn .kbd-badge.shortcut-float {
            position: absolute;
            right: -8px;
            bottom: -8px;
            top: auto;
            transform: none;
            margin: 0;
            background: #fff;
            color: #475569;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 6px rgba(15, 23, 42, .15);
            border-radius: 5px;
            font-size: .52rem;
            line-height: 1;
            padding: 2px 4px;
            opacity: .95;
            white-space: nowrap;
            pointer-events: none;
        }
        .btn .kbd-badge.shortcut-inline {
            background: #f8fafc;
            color: #475569;
            border: 1px solid #cbd5e1;
            box-shadow: none;
            border-radius: 5px;
            font-size: .68rem;
            padding: 2px 6px;
            margin-left: 8px;
        }
        .info-pelanggan-box { display: none; font-size: 0.8rem; padding: 5px 8px; background: #fff3cd; color: #664d03; border-radius: 4px; margin-top: 5px; border: 1px solid #ffecb5; }
        .val-deposit { font-weight: bold; color: #198754; } 
        .val-utang { font-weight: bold; color: #dc3545; }
        .potongan-line { display: flex; justify-content: space-between; font-weight: bold; font-size: 0.8rem; padding: 2px 0; border-bottom: 1px dashed #ccc; margin-bottom: 2px; }
        .input-diskon-item { border: 1px dashed #ced4da; padding: 0 5px; font-size: 0.8rem; width: 60px; text-align: right; color: #dc3545; height: 24px; border-radius: 4px;}
        .input-qty { width: 40px; text-align: center; border: 1px solid #ced4da; padding: 0; font-weight: bold; font-size: 0.85rem; margin: 0 2px; height: 26px; border-radius: 4px; }
        .input-qty::-webkit-outer-spin-button, .input-qty::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        
        #dragDropOverlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(26, 86, 219, 0.9); z-index: 999999; display: none; align-items: center; justify-content: center; flex-direction: column; color: white; border: 10px dashed rgba(255,255,255,0.5); transition: all 0.3s ease; pointer-events: none; }
        #dragDropOverlay i { font-size: 6rem; margin-bottom: 20px; animation: bounce 1s infinite alternate; }
        #dragDropOverlay h1 { font-weight: 900; letter-spacing: 2px; text-align: center;}
        @keyframes bounce { from { transform: translateY(0); } to { transform: translateY(-20px); } }

        @media (max-width: 768px) {
            body { height: auto; overflow-y: auto; padding-bottom: 70px; }
            .wrapper { flex-direction: column; height: auto; overflow: visible;}
            .bg-white.border-end { display: none !important; }
            .main-content { height: auto; overflow: visible;}
            .pos-container { flex-direction: column; height: auto; overflow: visible;}
            .catalog-section { height: auto; overflow: visible; padding: 10px; width: 100%; display: block; }
            .cart-section { height: auto; width: 100%; border-left: none; display: none; overflow: visible; }
            .cart-header { position: static; }
            .cart-items { min-height: 220px; overflow: visible; }
            .cart-footer { max-height: none; overflow: visible; }
            .cart-line-side { width: 132px; min-width: 132px; }
            .cart-section.show-mobile { display: flex !important; }
            .catalog-section.hidden-mobile { display: none !important; }
            .mobile-bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; right: 0; height: 55px; background: white; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); z-index: 1050; justify-content: space-around; align-items: center; border-top: 1px solid var(--border-color);}
            .nav-item-mobile { text-decoration: none; color: #6c757d; font-size: 0.8rem; display: flex; flex-direction: column; align-items: center; position: relative; width: 50%; padding: 5px 0; }
            .nav-item-mobile.active { color: var(--primary-color); font-weight: bold; }
        }
    .pecahan-bayar-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:4px}.pecahan-bayar-grid .btn{width:100%;font-size:.62rem!important;padding:4px 2px!important;white-space:nowrap}.pecahan-bayar-summary-compact{line-height:1.25;word-break:break-word}@media(max-width:480px){.pecahan-bayar-grid{grid-template-columns:repeat(5,minmax(0,1fr));gap:3px}.pecahan-bayar-grid .btn{font-size:.56rem!important;padding:4px 1px!important}}
</style>
</head>
<body>

<div id="customToast">
    <div class="icon-circle"><i class="fas fa-check"></i></div>
    <p class="toast-text">Produk berhasil ditambahkan</p>
</div>

<div id="dragDropOverlay">
    <i class="fas fa-cloud-upload-alt"></i>
    <h1>LEPASKAN FILE DI SINI</h1>
    <p class="fs-5">Untuk langsung menghitung Rekomendasi Harga Cetak/Fotocopy</p>
</div>

<button id="btnHelp" onclick="startTour(true)" title="Panduan Kasir" style="position: fixed; bottom: 20px; right: calc(var(--cart-width) + 20px); z-index: 9999; width: 40px; height: 40px; border-radius: 50%; background: var(--primary-color); color: white; font-size: 18px; box-shadow: 0 4px 10px rgba(0,0,0,0.3); border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s;"><i class="fas fa-question"></i></button>

<div class="wrapper">
    <?php 
        $base_dir = '../../'; 
        include '../../sidebar.php'; 
    ?>

    <div class="main-content">
        <header class="bg-white border-bottom px-3 px-md-4 d-flex align-items-center justify-content-between" style="height: 70px; z-index: 1020; flex-shrink: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">
            
            <div class="d-flex align-items-center">
                <button class="btn btn-light d-md-none me-3 border shadow-sm" onclick="document.querySelector('.bg-white.border-end').style.display='flex';" style="border-radius: 8px;">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bolder text-dark" style="letter-spacing: -0.5px;">Kasir Penjualan</h5>
                    <small class="text-muted fw-semibold" style="font-size: 0.75rem;">Addinta Printing</small>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="d-none d-md-flex flex-column align-items-end text-end border-end pe-3">
                    <small class="text-muted fw-bold" style="font-size: 0.65rem; text-transform: uppercase;">Waktu Realtime</small>
                    <span id="realtimeClock" class="fw-bold text-primary" style="font-size: 0.9rem;">00:00:00 WIB</span>
                </div>
                
                <div class="d-flex align-items-center bg-light rounded-pill p-1 border" style="box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    <img src="../../assets/img/avatar.png" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($_SESSION['nama_lengkap'] ?? 'User') ?>&background=random'" alt="User" class="rounded-circle" style="width: 36px; height: 36px; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="d-none d-sm-flex flex-column ms-2 me-3 justify-content-center">
                        <span class="fw-bold text-dark" style="font-size: 0.85rem; line-height: 1.1;"><?= htmlspecialchars($_SESSION['nama_lengkap'] ?? 'User') ?></span>
                        <span class="text-primary fw-bold" style="font-size: 0.65rem; line-height: 1.1; margin-top: 2px;"><?= strtoupper($_SESSION['level'] ?? 'KASIR') ?></span>
                    </div>
                </div>
            </div>

        </header>

        <div class="pos-container">
            <div class="catalog-section" id="section-catalog">
                <div class="card card-search mb-3 sticky-top" style="z-index: 100;">
                    <div class="card-body p-2">
                        <div class="row g-2 align-items-center">
                            <div class="col-12 col-md-5" id="step-cari">
                                <div class="input-group input-group-search input-group-sm position-relative">
                                    <span class="input-group-text border-end-0"><i class="fas fa-search"></i></span>
                                    <input type="text" id="keyword" name="keyword" class="form-control border-start-0" placeholder="Ketik nama barang..." autocomplete="off">
                                    <span class="kbd-badge" id="hint_cari"><?= getLabel($shortcuts, 'fokus_cari', 'F1') ?></span>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 position-relative" id="step-kategori">
                                <select id="filterKategori" class="form-select form-select-sm select2-kategori">
                                    <option value="">Semua Kategori</option>
                                    <?php foreach($kategori as $k): ?>
                                        <option value="<?= $k['kode_kategori'] ?>"><?= htmlspecialchars($k['nama_kategori']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="badge bg-secondary position-absolute top-0 end-0 mt-1 me-4" style="z-index: 9; font-size:0.55rem; opacity: 0.7;" id="hint_kategori"><?= getLabel($shortcuts, 'fokus_kategori', 'F10') ?></span>
                            </div>
                            <div class="col-6 col-md-3 text-end">
                                <div class="btn-group btn-group-sm me-1" role="group">
                                    <button type="button" class="btn btn-outline-primary active" id="btnViewGrid" onclick="setViewMode('grid')"><i class="fas fa-th-large"></i></button>
                                    <button type="button" class="btn btn-outline-primary" id="btnViewList" onclick="setViewMode('list')"><i class="fas fa-list"></i></button>
                                </div>
                                <a href="riwayat.php" class="btn btn-outline-secondary btn-sm" title="Riwayat"><i class="fas fa-history"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="productContainer" class="row g-2 view-grid"></div>
                
                <div id="loading" class="text-center mt-5" style="display:none;">
                    <div class="spinner-border text-primary spinner-border-sm" role="status"></div>
                    <p class="mt-2 text-muted small">Memuat data...</p>
                </div>
            </div>

            <div class="cart-section" id="section-cart">
                <div class="cart-header">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0 fw-bold" style="color: var(--primary-color);"><i class="fas fa-shopping-cart me-2"></i>Keranjang</h6>
                        <div>
                            <button class="btn btn-sm btn-outline-danger py-0 px-2" style="border-radius: 6px;" onclick="resetKeranjang()" title="Kosongkan"><i class="fas fa-trash-alt"></i></button>
                            <span class="badge bg-primary ms-1" id="totalItemsBadge" style="font-size:0.7rem">0 Item</span>
                        </div>
                    </div>
                    
                    <div class="bg-light p-2 rounded position-relative d-flex align-items-center border" id="step-pelanggan">
                        <div style="flex-grow: 1;">
                            <select id="pelanggan" class="form-select form-select-sm select2-pelanggan">
                                <option value="UMUM" selected data-deposit="0" data-utang="0" data-telp="">Pelanggan Umum (Cash)</option>
                            </select>
                        </div>
                        
                        <button class="btn btn-primary btn-sm ms-1" style="border-radius:6px;" type="button" onclick="bukaModalPelanggan()" title="Tambah Pelanggan Baru"><i class="fas fa-plus"></i></button>
                        
                        <button class="btn btn-success btn-sm ms-1 position-relative" style="border-radius:6px;" type="button" data-bs-toggle="modal" data-bs-target="#modalWaFiles" title="Lihat File dari WA (<?= getLabel($shortcuts, 'buka_file_whatsapp', 'ALT+W') ?>)">
                            <i class="fab fa-whatsapp"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light wa-badge-count" style="font-size: 0.5rem; display: <?= $total_chat_baru > 0 ? 'inline-block' : 'none' ?>;">
                                <?= $total_chat_baru ?>
                            </span>
                            <span class="kbd-badge shortcut-float" id="hint_file_whatsapp"><?= getLabel($shortcuts, 'buka_file_whatsapp', 'ALT+W') ?></span>
                        </button>
                        
                        <button class="btn btn-info btn-sm ms-1 text-white" style="border-radius:6px;" type="button" onclick="document.getElementById('inputManualFile').click()" title="Upload File Manual (PDF/Gambar)">
                            <i class="fas fa-file-upload"></i>
                        </button>
                        <input type="file" id="inputManualFile" style="display:none;" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onchange="prosesManualFile(this)">

                        <button class="btn btn-warning btn-sm ms-1 text-dark position-relative" style="border-radius:6px;" type="button" onclick="openPrintAnalyzerModal()" title="Print Analyzer: upload, hitung warna, tambah ke keranjang (<?= getLabel($shortcuts, 'buka_print_analyzer', 'ALT+A') ?>)">
                            <i class="fas fa-magic"></i>
                            <span class="kbd-badge shortcut-float" id="hint_print_analyzer"><?= getLabel($shortcuts, 'buka_print_analyzer', 'ALT+A') ?></span>
                        </button>

                        <span class="badge bg-secondary position-absolute top-0 end-0 mt-1 me-5" style="z-index: 9; font-size:0.55rem; opacity: 0.7; margin-right: 95px !important;" id="hint_pelanggan"><?= getLabel($shortcuts, 'pilih_pelanggan', 'F8') ?></span>
                    </div>
                    
                    <div id="infoPelangganContainer" class="info-pelanggan-box">
                        <div class="d-flex justify-content-between">
                            <div id="rowDeposit" style="display:none;" class="me-2"><i class="fas fa-wallet text-success"></i> <span class="val-deposit" id="lblSaldoDeposit">0</span></div>
                            <div id="rowUtang" style="display:none;"><i class="fas fa-exclamation-circle text-danger"></i> <span class="val-utang" id="lblSisaUtang">0</span></div>
                        </div>
                    </div>
                </div>

                <div class="cart-items" id="cartContainer">
                    <div class="text-center text-muted mt-5 pt-5">
                        <i class="fas fa-shopping-basket fa-2x mb-2 opacity-25"></i>
                        <p class="small">Keranjang Kosong</p>
                    </div>
                </div>

                <div class="cart-footer">
                    <div class="mb-2">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted border-end-0" style="font-size:0.75rem">Disc</span>
                                    <input type="number" id="inputDiskonGlobal" class="form-control input-compact text-danger border-start-0" placeholder="0" min="0">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted border-end-0" style="font-size:0.75rem">Ongkir</span>
                                    <input type="number" id="inputOngkir" class="form-control input-compact text-primary border-start-0" placeholder="0" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <div id="rowPotonganDeposit" class="potongan-line text-success" style="display:none;">
                            <span><i class="fas fa-check-circle"></i> Pakai Deposit</span>
                            <span id="lblPotonganDeposit">-Rp 0</span>
                        </div>
                        <div id="rowUtangLama" class="potongan-line text-danger border-danger" style="display:none;">
                            <span><i class="fas fa-exclamation-circle"></i> + Utang Lama</span>
                            <span id="lblUtangLama">Rp 0</span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-end mb-1 mt-3">
                            <small class="text-muted fw-bold" id="labelTotal" style="font-size:0.8rem">Total Belanja</small>
                            <div class="total-text" id="displayTotal" data-value="0" data-deposit-used="0" data-utang-lama="0" data-grand-total="0" data-diskon-global="0" data-ongkir="0">Rp 0</div>
                        </div>
                        <div id="rowGrandTotal" style="display:none;" class="border-top mt-1 pt-1 text-end">
                            <small class="fw-bold text-dark" style="font-size:0.8rem">GRAND TOTAL (GABUNGAN): </small>
                            <span class="h5 fw-bold text-dark mb-0" id="lblGrandTotal">Rp 0</span>
                        </div>
                    </div>

                    <div id="infoPotonganDepositDetail" class="alert alert-info border-info p-1 mb-2" style="display:none; font-size: 0.75rem;"></div>

                    <div class="row g-2 align-items-center mt-3">
                        <div class="col-12">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white text-muted border-end-0" style="font-size:0.75rem"><i class="fas fa-calendar-alt me-1"></i>Tanggal</span>
                                <input type="date" id="tanggalTransaksi" class="form-control input-compact border-start-0 fw-bold" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" style="font-size:0.85rem; height:34px;">
                            </div>
                            <div class="small text-muted mt-1" style="font-size:0.68rem;">Default mengikuti tanggal sistem komputer. Ubah hanya jika transaksi perlu masuk tanggal lain.</div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-center mt-2">
                        <div class="col-5">
                            <div class="position-relative" id="step-metode">
                                <select id="metodePembayaran" class="form-select form-select-sm fw-bold bg-light border-primary" style="font-size:0.85rem; height: 38px; border-radius: 8px;">
                                    <option value="Cash">TUNAI</option>
                                    <option value="QRIS">QRIS</option>
                                    <option value="Debit">DEBIT</option>
                                    <option value="Transfer">TRF</option>
                                    <option value="Deposit" class="text-success fw-bold">DEPO</option>
                                    <option value="Utang" class="text-danger fw-bold">UTANG</option>
                                </select>
                                <span class="kbd-badge" style="background:#fff; border:none;" id="hint_metode"><?= getLabel($shortcuts, 'pilih_metode_bayar', 'F4') ?></span>
                            </div>
                        </div>
                        <div class="col-7">
                            <div class="position-relative" id="step-bayar">
                                <input type="number" id="inputBayar" class="form-control form-control-sm fw-bold" placeholder="Uang Bayar" style="text-align: right; height: 38px; font-size: 1rem; border-radius: 8px; border-color: var(--primary-color);">
                                <span class="kbd-badge" style="background:#fff; border:none;" id="hint_bayar"><?= getLabel($shortcuts, 'fokus_bayar', 'F2') ?></span>
                            </div>
                        </div>
                    </div>

                    <div id="boxPecahanBayar" class="mt-2 p-2 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3" style="display:none; font-size:0.68rem; overflow:visible;">
                        <div class="d-flex justify-content-between align-items-center mb-1 gap-2">
                            <div class="fw-bold text-primary"><i class="fas fa-money-bill-wave me-1"></i>Pecahan uang diterima</div>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.68rem" onclick="resetPecahanBayarKasir()">Reset</button>
                        </div>
                        <div id="pecahanBayarButtons" class="pecahan-bayar-grid mb-1"></div>
                        <div id="pecahanBayarSummary" class="small text-muted pecahan-bayar-summary-compact">Tap pecahan uang yang diterima.</div>
                        <div id="pecahanBayarDetected" class="small text-muted mt-1"></div>
                    </div>
                    
                    <div class="mt-2 d-flex justify-content-between align-items-center">
                        <div id="areaSimpanDeposit" class="d-flex align-items-center bg-warning bg-opacity-25 px-2 py-1 rounded" style="display:none; font-size: 0.7rem;">
                            <input class="form-check-input me-1 m-0" type="checkbox" id="chkSimpanDeposit" onchange="toggleInputDeposit(this)">
                            <label class="form-check-label text-dark me-1" for="chkSimpanDeposit">Simpan Kembalian</label>
                            <input type="number" id="inputNominalDeposit" class="form-control form-control-sm py-0 px-1 text-success fw-bold" style="width: 60px; height: 22px; font-size: 0.7rem;" disabled placeholder="0">
                        </div>
                        <small class="fw-bold text-success ms-auto" style="font-size:0.9rem">Kembali: <span id="displayKembali">Rp 0</span></small>
                    </div>
                    <div id="suggestKembalianKasir" class="mt-2 p-2 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 small" style="display:none; font-size:0.68rem; white-space:normal; overflow:visible; word-break:break-word; line-height:1.25;"></div>

                    <div class="mt-3" id="step-simpan">
                        <button class="btn w-100 btn-lg fw-bold py-2" style="background: var(--primary-color); color:white; border-radius: 10px; box-shadow: 0 4px 6px rgba(26, 86, 219, 0.2);" id="btn_simpan" onclick="prosesBayar()">
                            BAYAR <span style="font-size:0.7rem; opacity:0.8; margin-left: 5px;">(<?= getLabel($shortcuts, 'simpan_transaksi', 'F9') ?>)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mobile-bottom-nav">
    <a href="#" class="nav-item-mobile active" id="tabCatalog" onclick="showMobileTab('catalog'); return false;">
        <i class="fas fa-th-list"></i> Produk
    </a>
    <a href="#" class="nav-item-mobile" id="tabCart" onclick="showMobileTab('cart'); return false;">
        <i class="fas fa-shopping-cart"></i> 
        Keranjang <span class="badge bg-danger rounded-pill" id="mobileCartCount" style="font-size:0.6rem; vertical-align: top;">0</span>
    </a>
</div>

<div class="modal fade" id="modalRekomHarga" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-calculator text-primary me-2"></i>Rekomendasi Harga</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="mb-3 d-flex flex-wrap gap-2 align-items-center bg-white p-2 border rounded-3 shadow-sm">
                    <div class="d-flex align-items-center flex-grow-1">
                        <label class="fw-bold small text-muted mb-0 ps-1 text-nowrap"><i class="fas fa-file-alt text-primary me-1"></i> Hal:</label>
                        <input type="text" id="inputCustomPages" class="form-control form-control-sm border-0 bg-light ms-2" placeholder="Semua (1-5, 8)" style="box-shadow:none;">
                    </div>
                    <div class="d-flex align-items-center border-start ps-2">
                        <label class="fw-bold small text-muted mb-0 ps-1 text-nowrap" title="Multiple Pages per Sheet"><i class="fas fa-th-large text-primary me-1"></i> N-up:</label>
                        <select id="inputNup" class="form-select form-select-sm border-0 bg-light ms-2 fw-bold" style="width: 65px; box-shadow:none; cursor:pointer;">
                            <option value="1" selected>1</option>
                            <option value="2">2</option>
                            <option value="4">4</option>
                            <option value="6">6</option>
                            <option value="9">9</option>
                            <option value="16">16</option>
                        </select>
                    </div>
                    <button class="btn btn-sm text-white fw-bold px-3 rounded-3" style="background: var(--primary-color);" id="btnHitungUlang" onclick="hitungUlangRekom()"><i class="fas fa-sync-alt"></i></button>
                </div>
                <div class="text-center mb-4 mt-4" id="prosesLoadingRekom">
                    <div class="spinner-border text-primary spinner-border-sm mb-2" role="status" id="loadingSpinnerRekom"></div>
                    <h6 class="fw-bolder text-dark mt-0 text-break" id="judulFileRekom" style="line-height: 1.3;">Memproses File...</h6>
                    <small class="text-muted" id="infoProsesRekom">Sedang menghitung halaman dan persentase warna.</small>
                </div>
                <div id="hasilRekomendasi" style="display:none;">
                    <div class="row g-2 mb-3 text-center" id="boxRingkasanHalaman"></div>
                    <div class="mb-3 p-3 bg-white border rounded-3 shadow-sm d-flex align-items-center justify-content-between">
                        <label class="fw-bold text-dark mb-0 small"><i class="fas fa-copy text-muted me-1"></i> Jumlah Rangkap:</label>
                        <div class="input-group input-group-sm" style="width: 120px;">
                            <input type="number" id="inputRangkapCetak" class="form-control text-center fw-bold" value="1" min="1" style="border-color: var(--border-color);">
                            <span class="input-group-text bg-light text-muted border-start-0">set</span>
                        </div>
                    </div>
                    <ul class="nav nav-pills nav-fill mb-3 bg-white p-1 rounded-3 border" id="rekomTab" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active fw-bold py-2 rounded-3" id="print-tab" data-bs-toggle="tab" data-bs-target="#print-tab-pane" type="button" role="tab"><i class="fas fa-print me-1"></i> Print Auto</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold py-2 rounded-3 text-muted" id="bw-tab" data-bs-toggle="tab" data-bs-target="#bw-tab-pane" type="button" role="tab"><i class="fas fa-tint-slash me-1"></i> B/W</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link fw-bold py-2 rounded-3 text-muted" id="fc-tab" data-bs-toggle="tab" data-bs-target="#fc-tab-pane" type="button" role="tab"><i class="fas fa-copy me-1"></i> Fotocopy</button></li>
                    </ul>
                    <div class="tab-content" id="rekomTabContent">
                        <div class="tab-pane fade show active" id="print-tab-pane" role="tabpanel" tabindex="0">
                            <ul class="list-group list-group-flush border rounded-3 shadow-sm mb-2 overflow-hidden" id="listHargaKertas"></ul>
                        </div>
                        <div class="tab-pane fade" id="bw-tab-pane" role="tabpanel" tabindex="0">
                            <ul class="list-group list-group-flush border rounded-3 shadow-sm mb-2 overflow-hidden" id="listHargaBW"></ul>
                        </div>
                        <div class="tab-pane fade" id="fc-tab-pane" role="tabpanel" tabindex="0">
                            <ul class="list-group list-group-flush border rounded-3 shadow-sm mb-2 overflow-hidden" id="listHargaFC"></ul>
                        </div>
                    </div>
                    <div class="tab-content" id="rekomTabContent">
                        <div class="tab-pane fade show active" id="print-tab-pane" role="tabpanel" tabindex="0">
                            <ul class="list-group list-group-flush border rounded-3 shadow-sm mb-2 overflow-hidden" id="listHargaKertas"></ul>
                        </div>
                        <div class="tab-pane fade" id="fc-tab-pane" role="tabpanel" tabindex="0">
                            <ul class="list-group list-group-flush border rounded-3 shadow-sm mb-2 overflow-hidden" id="listHargaFC"></ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTambahPelanggan" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-user-plus text-primary me-2"></i>Tambah Pelanggan Baru</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <form id="formTambahPelanggan">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Nama Pelanggan</label>
                        <input type="text" name="nama" id="formNamaPelanggan" class="form-control form-control-lg border-0 shadow-sm rounded-3" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">No. Telepon / WA</label>
                        <input type="text" name="telp" id="formTelpPelanggan" class="form-control form-control-lg border-0 shadow-sm rounded-3" autocomplete="off">
                        <small id="alertLid" class="text-danger fw-bold mt-2 d-block bg-white p-2 rounded-3 border border-danger border-opacity-25" style="display:none; font-size: 0.75rem;">
                            <i class="fas fa-info-circle me-1"></i> ID Unik (LID) terdeteksi. Disimpan ke kolom LID.
                        </small>
                    </div>
                    <input type="hidden" name="wa_lid" id="formWaLidPelanggan">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Alamat</label>
                        <textarea name="alamat" class="form-control border-0 shadow-sm rounded-3" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer py-3 bg-white border-top px-4">
                <button type="button" class="btn btn-light fw-bold text-muted rounded-3 px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn text-white fw-bold px-4 rounded-3" style="background: var(--primary-color);" onclick="simpanPelangganBaru()">Simpan Data</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalSimpanKeLama" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fas fa-link text-warning me-2"></i>Tautkan ke Pelanggan Lama</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <p class="small text-muted mb-4">Pilih pelanggan yang sudah ada di database untuk menautkan nomor/ID WhatsApp baru ini.</p>
                <input type="hidden" id="hiddenNomorBaru">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark">Cari Pelanggan Lama:</label>
                    <div class="bg-white p-1 rounded-3 shadow-sm border">
                        <select id="pilih_pelanggan_lama" class="form-select border-0" style="width:100%"></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-3 bg-white border-top px-4">
                <button type="button" class="btn btn-light fw-bold text-muted rounded-3 px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn text-white fw-bold px-4 rounded-3" style="background: var(--primary-color);" onclick="prosesSimpanKeLama()">Simpan & Tautkan</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalWaFiles" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h6 class="modal-title fw-bolder text-dark mb-0"><i class="fab fa-whatsapp text-success fs-5 me-2"></i>File Desain dari WhatsApp</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-light">
                <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center shadow-sm position-relative" style="z-index:10;">
                    <span class="small fw-bold text-muted"><i class="far fa-calendar-alt me-1"></i> Tanggal Chat:</span>
                    <form method="GET" class="m-0">
                        <input type="date" name="wa_date" id="wa_date_picker" class="form-control form-control-sm fw-bold text-dark border-0 bg-light rounded-3 px-3 py-2" style="max-width: 160px;" value="<?= $wa_date_filter ?>">
                    </form>
                </div>
                <div class="accordion accordion-flush" id="accordionWaFilesFront"></div>
            </div>
            <div class="modal-footer py-3 bg-white px-4 border-top">
                <small class="text-muted me-auto" style="font-size:0.7rem;"><i class="fas fa-info-circle me-1"></i> Hanya menampilkan file yang belum diproses ke keranjang.</small>
                <button type="button" class="btn btn-light border fw-bold text-dark rounded-3 px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../print_analyzer/modal_widget.php'; ?>

<div class="modal fade" id="modalSukses" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4">
                <h5 class="modal-title fw-bolder text-success mb-0"><i class="fas fa-check-circle me-2"></i>Transaksi Berhasil Disimpan!</h5>
            </div>
            <div class="modal-body p-0 bg-light">
                <div class="row g-0">
                    <div class="col-md-5 border-end text-center d-flex align-items-center justify-content-center bg-white p-4">
                        <div class="text-center w-100">
                             <iframe id="strukFrame" src="" style="width: 100%; height: 380px; border: 1px solid var(--border-color); background:#f9fafb; border-radius: 12px; box-shadow: inset 0 2px 5px rgba(0,0,0,0.02);"></iframe>
                        </div>
                    </div>
                    <div class="col-md-7 d-flex flex-column justify-content-center p-4 p-md-5">
                        <div class="alert border-0 text-center mb-4 py-3 rounded-4" style="background-color: #ecfdf5;">
                            <small class="text-success fw-bold d-block mb-1 text-uppercase" style="letter-spacing: 1px;">Status Pembayaran</small>
                            
                            <div class="bg-white border rounded-3 p-2 mb-2 text-start shadow-sm" style="font-size:0.8rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted fw-bold"><i class="fas fa-user me-1"></i>Customer</span>
                                    <span class="fw-bold text-dark text-end" id="lblSuksesPelanggan">-</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted fw-bold"><i class="fas fa-receipt me-1"></i>Grand Total</span>
                                    <span class="fw-bolder text-primary text-end" id="lblSuksesGrandTotal">Rp 0</span>
                                </div>
                            </div>
<h2 class="fw-bolder text-success m-0" id="lblKembali">Kembali: Rp 0</h2>
                        </div>
                        
                        <button type="button" class="btn btn-dark btn-lg w-100 mb-3 fw-bold rounded-3 shadow-sm border-0 d-flex align-items-center justify-content-center py-3" id="btnCetakTMU" onclick="cetakLagi()">
                            <i class="fas fa-print fa-lg me-2"></i> Cetak Struk <span class="kbd-badge shortcut-inline" id="hint_cetak_struk"><?= getLabel($shortcuts, 'cetak_struk', 'ALT+C') ?></span>
                        </button>
                        
                        <button type="button" class="btn btn-light border btn-lg w-100 mb-4 fw-bold rounded-3 shadow-sm text-dark d-flex align-items-center justify-content-center py-3" onclick="keManajemenOrder()" title="Buka Manajemen Order (<?= getLabel($shortcuts, 'buka_manajemen_order', 'ALT+O') ?>)">
                            <i class="fas fa-tasks text-primary fa-lg me-2"></i> Masuk Antrian Order <span class="kbd-badge shortcut-inline" id="hint_manajemen_order"><?= getLabel($shortcuts, 'buka_manajemen_order', 'ALT+O') ?></span>
                        </button>
                        
                        <div class="bg-white p-3 rounded-4 border shadow-sm mb-4">
                            <label class="small fw-bold text-muted mb-2 d-block"><i class="fab fa-whatsapp text-success me-1"></i> Kirim Nota ke WhatsApp Pelanggan <span class="kbd-badge shortcut-inline" id="hint_kirim_wa"><?= getLabel($shortcuts, 'kirim_nota_wa', 'ALT+K') ?></span></label>
                            <div class="input-group">
                                <input type="text" id="inputNoWA" class="form-control bg-light border-end-0" placeholder="08xxx..." style="box-shadow:none;">
                                <button class="btn btn-success fw-bold px-4 border-start-0" onclick="kirimWA()" id="btnKirimWA"><i class="fas fa-paper-plane"></i></button>
                            </div>
                        </div>
                        
                        <div class="mt-auto">
                            <button type="button" class="btn w-100 btn-lg fw-bolder text-white rounded-3 shadow py-3" style="background: var(--primary-color);" id="btnTransaksiBaru">
                                Transaksi Selanjutnya <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/intro.js@7.2.0/minified/intro.min.js"></script>

<script>
    // --- JAM REALTIME ---
    function updateClock() {
        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const clockEl = document.getElementById('realtimeClock');
        if(clockEl) clockEl.textContent = `${hours}:${minutes}:${seconds} WIB`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    const visualHints = <?= json_encode($shortcuts) ?>;
    const hotkeys = <?= json_encode($map_listener) ?>;

    let keranjang = [];
    let products = [];
    let activePelanggan = { id: 'UMUM', text: 'Pelanggan Umum', deposit: 0, utang: 0, telp: '' };
    let lastTrxData = { no: '', utang_lama: 0, total_bayar: 0, depo_awal: 0, depo_pakai: 0 }; 
    const shortcutKeys = "123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    let productShortcuts = {}; 
    let currentViewMode = localStorage.getItem('pos_view_mode') || 'grid';

    let waDateFilter = '<?= $wa_date_filter ?>';
    let waFilesHash = '<?= $initial_hash ?>';
    let lastPelangganSearch = '';

    const formatRupiah = (num) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(num);
    const escHtml = (txt) => String(txt ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

    function showToast() {
        const toast = document.getElementById('customToast');
        toast.classList.add('show');
        setTimeout(() => { toast.classList.remove('show'); }, 2000);
    }

    function bukaModalPelanggan() {
        $('#formTambahPelanggan')[0].reset();
        $('#alertLid').hide();
        var modal = new bootstrap.Modal(document.getElementById('modalTambahPelanggan'));
        modal.show();
    }

    function toggleTourPreference(el) {
        if (el.checked) { localStorage.setItem('pos_tour_hidden', 'true'); } 
        else { localStorage.removeItem('pos_tour_hidden'); }
    }

    function startTour(force) {
        if (typeof introJs === 'undefined') return; 
        if (!force && localStorage.getItem('pos_tour_hidden') === 'true') return;
        
        let scCari = visualHints['fokus_cari'] ? visualHints['fokus_cari'].toUpperCase() : 'F1';
        let scKategori = visualHints['fokus_kategori'] ? visualHints['fokus_kategori'].toUpperCase() : 'F10';
        let scPelanggan = visualHints['pilih_pelanggan'] ? visualHints['pilih_pelanggan'].toUpperCase() : 'F8';
        let scMetode = visualHints['pilih_metode_bayar'] ? visualHints['pilih_metode_bayar'].toUpperCase() : 'F4';
        let scBayar = visualHints['fokus_bayar'] ? visualHints['fokus_bayar'].toUpperCase() : 'F2';
        let scSimpan = visualHints['simpan_transaksi'] ? visualHints['simpan_transaksi'].toUpperCase() : 'F9';

        let intro = introJs();
        intro.setOptions({
            nextLabel: 'Lanjut', prevLabel: 'Kembali', doneLabel: 'Selesai', showProgress: true,
            steps: [
                { element: document.querySelector('#step-cari'), intro: `Ketik nama barang di sini. Tekan <b class='text-primary'>${scCari}</b> untuk fokus cepat.` },
                { element: document.querySelector('#step-kategori'), intro: `Filter barang berdasarkan kategori. Tekan <b class='text-primary'>${scKategori}</b>.` },
                { element: document.querySelector('#step-pelanggan'), intro: `Pilih pelanggan dari WhatsApp atau tambah baru. Tekan <b class='text-primary'>${scPelanggan}</b>.` },
                { element: document.querySelector('#step-metode'), intro: `Pilih metode pembayaran (Cash/QRIS/Deposit). Tekan <b class='text-primary'>${scMetode}</b>.` },
                { element: document.querySelector('#step-bayar'), intro: `Masukkan nominal uang bayar pelanggan. Tekan <b class='text-primary'>${scBayar}</b>.` },
                { element: document.querySelector('#step-simpan'), intro: `Klik tombol ini atau tekan <b class='text-primary'>${scSimpan}</b> untuk menyelesaikan transaksi. Selesai!` }
            ]
        });

        intro.onafterchange(function() {
            if ($('.introjs-dont-show').length === 0) {
                $('<div class="introjs-dont-show text-start p-2 mt-2" style="background:#f8f9fa; border-top:1px solid #dee2e6; border-radius:0 0 4px 4px; font-size:0.75rem;">' +
                  '<div class="form-check m-0"><input class="form-check-input" type="checkbox" id="chkHideTour" onchange="toggleTourPreference(this)" style="cursor:pointer;"><label class="form-check-label text-muted" for="chkHideTour" style="cursor:pointer;">Jangan tampilkan otomatis lagi</label></div></div>').insertAfter('.introjs-tooltipbuttons');
            }
            $('#chkHideTour').prop('checked', localStorage.getItem('pos_tour_hidden') === 'true');
        });
        intro.start();
    }
    
    function daftarkanNomorWa(nomor, nama) {
        $('#formTambahPelanggan')[0].reset();
        let cleanNum = nomor.replace(/[^0-9]/g, '');
        if (cleanNum.startsWith('62') || cleanNum.startsWith('08')) {
            $('#formTelpPelanggan').val(cleanNum); $('#formWaLidPelanggan').val(''); $('#alertLid').hide();
        } else {
            $('#formWaLidPelanggan').val(cleanNum); $('#formTelpPelanggan').val(''); $('#alertLid').show();                  
            Swal.fire({ icon: 'info', title: 'ID Unik Terdeteksi', text: 'Disimpan otomatis ke kolom LID.', timer: 3000, showConfirmButton: false });
        }
        if(nama !== nomor && nama !== "") { $('#formNamaPelanggan').val(nama); }
        
        const waModalEl = document.getElementById('modalWaFiles');
        if (waModalEl) bootstrap.Modal.getOrCreateInstance(waModalEl).hide();
        var modal = new bootstrap.Modal(document.getElementById('modalTambahPelanggan')); modal.show();
    }
    
    function simpanPelangganBaru() {
        let nama = $('#formNamaPelanggan').val().trim();
        let telp = $('#formTelpPelanggan').val().trim();
        let lid = $('#formWaLidPelanggan').val().trim();

        if (nama === '') return Swal.fire('Perhatian', 'Nama pelanggan tidak boleh kosong!', 'warning').then(() => { setTimeout(() => $('#formNamaPelanggan').focus(), 300); });
        if (telp === '' && lid === '') return Swal.fire('Perhatian', 'No Telepon atau WA LID harus diisi!', 'warning').then(() => { setTimeout(() => $('#formTelpPelanggan').focus(), 300); });

        let formData = new FormData(document.getElementById('formTambahPelanggan'));
        formData.append('action', 'check_nama_pelanggan');
        
        fetch('index.php', { method: 'POST', body: formData }).then(response => response.json()).then(data => {
            if (data.status === 'exist') {
                Swal.fire({ title: 'Nama Pelanggan Sudah Ada!', html: `Pelanggan <b>${data.data.nama_pelanggan}</b> sudah terdaftar.<br><small class="text-muted">No HP Lama: ${data.data.no_telepon || '-'}</small>`, icon: 'warning', showDenyButton: true, showCancelButton: true, confirmButtonText: 'Update Data Lama', denyButtonText: 'Ganti Nama Baru', cancelButtonText: 'Batal', confirmButtonColor: '#198754', denyButtonColor: '#0d6efd'
                }).then((result) => {
                    if (result.isConfirmed) { prosesSimpanAtauUpdate('update_pelanggan_lama', data.data.kode_pelanggan); } 
                    else if (result.isDenied) { setTimeout(() => { document.getElementById('formNamaPelanggan').focus(); Swal.fire({ icon: 'info', title: 'Silakan Ganti Nama', text: 'Tambahkan pembeda, misal: "Budi (Sby)"', timer: 2000, showConfirmButton: false }); }, 500); }
                });
            } else if (data.status === 'not_exist') { prosesSimpanAtauUpdate('simpan_pelanggan_baru', null); } 
            else { Swal.fire('Error', data.message, 'error'); }
        }).catch(error => { Swal.fire({ icon: 'error', title: 'Error', text: 'Terjadi kesalahan sistem' }); });
    }

    function prosesSimpanAtauUpdate(actionType, kodeLama) {
        let form = document.getElementById('formTambahPelanggan');
        let formData = new FormData(form); 
        formData.append('action', actionType);
        if(actionType === 'update_pelanggan_lama' && kodeLama) formData.append('kode_pelanggan', kodeLama); 
        
        fetch('index.php', { method: 'POST', body: formData }).then(response => response.json()).then(data => {
            if (data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('modalTambahPelanggan')).hide();
                Swal.fire({ icon: 'success', title: 'Berhasil', text: 'Data tersimpan.', timer: 1500, showConfirmButton: false });
                let newOption = new Option(data.nama, data.id, true, true); 
                $('#pelanggan').append(newOption).trigger('change');
                activePelanggan = { id: data.id, text: data.nama, deposit: parseFloat(data.deposit), utang: parseFloat(data.utang), telp: data.telp };
                localStorage.setItem('pos_pelanggan_data_v2', JSON.stringify(activePelanggan)); 
                updateInfoPelangganUI();
            } else { Swal.fire({ icon: 'error', title: 'Gagal', text: data.message }); }
        });
    }

    // --- FUNGSI PILIH PELANGGAN DARI MODAL WA ---
    // FIX: data dari File Desain WhatsApp bisa stale karena tombol dibuat dari cache/list lama.
    // Saat dipilih, ambil ulang saldo deposit dan sisa utang langsung dari database.
    function terapkanPelangganAktif(id, nama, telp, deposit, utang, showNotif = true) {
        let pDepo = parseFloat(deposit) || 0;
        let pUtang = parseFloat(utang) || 0;

        if ($('#pelanggan').find("option[value='" + id + "']").length) {
            $('#pelanggan').val(id).trigger('change');
        } else {
            let newOption = new Option(nama, id, true, true);
            $('#pelanggan').append(newOption).trigger('change');
        }

        activePelanggan = { id: id, text: nama, deposit: pDepo, utang: pUtang, telp: telp };
        localStorage.setItem('pos_pelanggan_data_v2', JSON.stringify(activePelanggan));

        updateInfoPelangganUI();
        renderCart();

        const waModalEl = document.getElementById('modalWaFiles');
        if (waModalEl) {
            const waModal = bootstrap.Modal.getInstance(waModalEl);
            if (waModal) waModal.hide();
        }

        setTimeout(() => {
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css({'overflow': '', 'padding-right': ''});
        }, 300);

        if (showNotif) {
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: nama + ' dipilih', timer: 1500, showConfirmButton: false });
        }
    }

    window.setPelangganAktif = async function(id, nama, telp, deposit, utang) {
        if (!id || id === 'UMUM') {
            terapkanPelangganAktif(id || 'UMUM', nama || 'Pelanggan Umum', telp || '', deposit || 0, utang || 0);
            return;
        }

        try {
            const fd = new FormData();
            fd.append('action', 'get_pelanggan_latest');
            fd.append('kode_pelanggan', id);
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const json = await res.json();

            if (json.status === 'success' && json.data) {
                const p = json.data;
                terapkanPelangganAktif(p.id, p.nama, p.telp || telp || '', p.deposit || 0, p.utang || 0);
            } else {
                // Fallback kalau AJAX gagal: tetap pilih pelanggan, tapi pakai data tombol.
                terapkanPelangganAktif(id, nama, telp || '', deposit || 0, utang || 0);
            }
        } catch (e) {
            terapkanPelangganAktif(id, nama, telp || '', deposit || 0, utang || 0);
        }
    };

    let curFileMode = ''; let curFileName = ''; let curFileObj = null; let curNamaAsli = '';

    // --- TAMBAHKAN VARIABEL GLOBAL INI ---
    let globalRekomData = null;
    let globalRekomFilename = '';

    // --- FUNGSI BARU UNTUK MERENDER ULANG DAFTAR HARGA ---
    function renderListHargaRekom() {
        if (!globalRekomData) return;
        let res = globalRekomData;
        let filename = globalRekomFilename;
        let det = res.deteksi;

        // Ambil nilai rangkap, default ke 1 jika kosong/invalid
        let multiplier = parseInt($('#inputRangkapCetak').val()) || 1;
        if (multiplier < 1) multiplier = 1;

        // 1. Tab Print Auto
        let htmlHarga = '';
        if(res.rekomendasi.length === 0) { 
            htmlHarga = `<div class="alert alert-danger m-2 small">Barang Print tidak ditemukan.</div>`; 
        } else {
            res.rekomendasi.forEach(r => {
                let safeJson = r.cart_items_json.replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                // KALIKAN HARGA DASAR DENGAN JUMLAH RANGKAP
                let total_rp = r.total_rp_base * multiplier; 
                
                htmlHarga += `<li class="list-group-item d-flex justify-content-between align-items-center py-2 px-2"><div><div class="fw-bold text-dark" style="font-size:0.85rem">${r.nama_kertas}</div><span class="text-muted" style="font-size:0.7rem">Total: ${formatRupiah(total_rp)}</span></div><div class="btn-group flex-shrink-0"><button class="btn btn-sm btn-primary fw-bold shadow-sm" onclick="terapkanRekomendasiKeKeranjang('${safeJson}', '${filename}', false, ${det.total_halaman})">1 Sisi</button><button class="btn btn-sm btn-outline-primary fw-bold shadow-sm" onclick="terapkanRekomendasiKeKeranjang('${safeJson}', '${filename}', true, ${det.total_halaman})">2 Sisi</button></div></li>`;
            });
            htmlHarga += `<div class="p-2 mt-2 text-center text-muted border-top" style="font-size:0.65rem; background:#f8f9fa;">*Opsi <b>2 Sisi</b> menambahkan diskon kertas di Grand Total.</div>`;
        }
        $('#listHargaKertas').html(htmlHarga);

        // 2. Tab Print B/W
        let htmlBW = '';
        if(!res.rekomendasi_bw || res.rekomendasi_bw.length === 0) { 
            htmlBW = `<div class="alert alert-danger m-2 small">Barang Print Hitam tidak ditemukan.</div>`; 
        } else {
            res.rekomendasi_bw.forEach(r => {
                let safeJson = r.cart_items_json.replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                // KALIKAN HARGA DASAR DENGAN JUMLAH RANGKAP
                let total_rp = r.total_rp_base * multiplier;
                
                htmlBW += `<li class="list-group-item d-flex justify-content-between align-items-center py-2 px-2"><div><div class="fw-bold text-dark" style="font-size:0.85rem">${r.nama_kertas}</div><span class="text-muted" style="font-size:0.7rem">Total: ${formatRupiah(total_rp)}</span></div><div class="btn-group flex-shrink-0"><button class="btn btn-sm btn-dark fw-bold shadow-sm" onclick="terapkanRekomendasiKeKeranjang('${safeJson}', '${filename}', false, ${det.total_halaman})">1 Sisi</button><button class="btn btn-sm btn-outline-dark fw-bold shadow-sm" onclick="terapkanRekomendasiKeKeranjang('${safeJson}', '${filename}', true, ${det.total_halaman})">2 Sisi</button></div></li>`;
            });
            htmlBW += `<div class="p-2 mt-2 text-center text-muted border-top" style="font-size:0.65rem; background:#f8f9fa;">*Opsi <b>2 Sisi</b> menambahkan diskon kertas di Grand Total.</div>`;
        }
        $('#listHargaBW').html(htmlBW);

        // 3. Tab Fotocopy
        let htmlFC = '';
        if(!res.rekomendasi_fc || res.rekomendasi_fc.length === 0) { 
            htmlFC = `<div class="alert alert-danger m-2 small">Item Fotocopy tidak ditemukan.</div>`; 
        } else {
            res.rekomendasi_fc.forEach(r => {
                let safeJson1 = r.json_1sisi.replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                let btn2Sisi = r.ada_dupleks ? `<button class="btn btn-sm btn-outline-primary fw-bold shadow-sm" onclick="terapkanRekomendasiKeKeranjang('${r.json_2sisi.replace(/'/g, "&#39;").replace(/"/g, "&quot;")}', '${filename}', false, 0)">Dupleks</button>` : `<button class="btn btn-sm btn-outline-secondary fw-bold shadow-sm opacity-50" disabled>Dupleks</button>`;
                
                // KALIKAN HARGA DASAR DENGAN JUMLAH RANGKAP
                let total_rp = r.rp_1sisi * multiplier; 
                
                htmlFC += `<li class="list-group-item d-flex justify-content-between align-items-center py-2 px-2"><div><div class="fw-bold text-dark" style="font-size:0.85rem">${r.nama_kertas}</div><span class="text-muted" style="font-size:0.7rem">Total 1 Sisi: ${formatRupiah(total_rp)}</span></div><div class="btn-group flex-shrink-0"><button class="btn btn-sm btn-primary fw-bold shadow-sm" onclick="terapkanRekomendasiKeKeranjang('${safeJson1}', '${filename}', false, 0)">1 Sisi</button>${btn2Sisi}</div></li>`;
            });
        }
        $('#listHargaFC').html(htmlFC);
    }

    // --- FUNGSI BAWAAN YANG SUDAH DIUPDATE ---
    function prosesSuksesHitung(res, filename) {
        $('#infoProsesRekom').text('Selesai!'); 
        let det = res.deteksi; 
        let labelLbr = det.is_nup ? `FISIK (${det.nup_val}-up)` : 'TOTAL LBR';
        let subLabel = det.is_nup ? `<div class="text-primary mt-1" style="font-size:0.55rem; line-height:1;">Dari ${det.total_asli} Hal</div>` : '';

        let htmlRingkasan = `<div class="col-4"><div class="border rounded p-1 bg-white shadow-sm h-100 d-flex flex-column justify-content-center"><div class="text-muted fw-bold" style="font-size:0.60rem">${labelLbr}</div><div class="fw-bold fs-5" style="line-height:1;">${det.total_halaman}</div>${subLabel}</div></div><div class="col-4"><div class="border rounded p-1 bg-white shadow-sm h-100 d-flex flex-column justify-content-center"><div class="text-muted fw-bold" style="font-size:0.60rem">HITAM</div><div class="fw-bold fs-5 text-secondary" style="line-height:1;">${det.bw}</div></div></div><div class="col-4"><div class="border rounded p-1 bg-white shadow-sm h-100 d-flex flex-column justify-content-center"><div class="text-muted fw-bold" style="font-size:0.60rem">WARNA</div><div class="fw-bold fs-5 text-danger" style="line-height:1;">${det.total_halaman - det.bw}</div></div></div>`; 
        $('#boxRingkasanHalaman').html(htmlRingkasan);
        
        // 1. Simpan State Data ke Variabel Global
        globalRekomData = res;
        globalRekomFilename = filename;

        // 2. Render Harga Sesuai Rangkap 
        renderListHargaRekom();

        $('#hasilRekomendasi').fadeIn(); 
    }

    function doHitungRekom() {
        let pages = $('#inputCustomPages').val().trim();
        let n_up = $('#inputNup').val() || 1; // Ambil nilai N-up dari dropdown

        $('#judulFileRekom').text(curNamaAsli); 
        $('#infoProsesRekom').text('Sedang menghitung ' + (pages ? 'halaman ' + pages : 'semua halaman') + (n_up > 1 ? ` (Format ${n_up}-up)` : '') + '...'); 
        $('#prosesLoadingRekom').show(); $('#loadingSpinnerRekom').show(); $('#hasilRekomendasi').hide(); 
        
        var triggerEl = document.querySelector('#rekomTab button[data-bs-target="#print-tab-pane"]');
        bootstrap.Tab.getOrCreateInstance(triggerEl).show();

        var modalRekom = new bootstrap.Modal(document.getElementById('modalRekomHarga')); modalRekom.show(); 
        $('#inputRangkapCetak').val(1);
        
        if (curFileMode === 'wa') {
            $.ajax({ 
                url: 'index.php', type: 'POST', dataType: 'json', data: { action: 'hitung_rekomendasi', filename: curFileName, pages: pages, n_up: n_up }, 
                success: function(res) { $('#loadingSpinnerRekom').hide(); if(res.status === 'success') { prosesSuksesHitung(res, curFileName); } else { $('#infoProsesRekom').html(`<span class="text-danger fw-bold">Error: ${res.message}</span>`); } }, 
                error: function() { $('#loadingSpinnerRekom').hide(); $('#infoProsesRekom').html(`<span class="text-danger fw-bold">Gagal server.</span>`); } 
            });
        } else if (curFileMode === 'manual') {
            let formData = new FormData(); formData.append('action', 'hitung_rekomendasi_manual'); formData.append('manual_file', curFileObj); formData.append('pages', pages); formData.append('n_up', n_up);
            $.ajax({ 
                url: 'index.php', type: 'POST', data: formData, contentType: false, processData: false, 
                success: function(res) { $('#loadingSpinnerRekom').hide(); if(res.status === 'success') { prosesSuksesHitung(res, curFileName); } else { $('#infoProsesRekom').html(`<span class="text-danger fw-bold">Error: ${res.message}</span>`); } }, 
                error: function() { $('#loadingSpinnerRekom').hide(); $('#infoProsesRekom').html(`<span class="text-danger fw-bold">Gagal upload.</span>`); } 
            }); 
        }
    }

    function hitungRekomHarga(filename, namaAsli) {
        let markedFiles = JSON.parse(localStorage.getItem('wa_files_marked') || '[]');
        if (!markedFiles.includes(filename)) { markedFiles.push(filename); localStorage.setItem('wa_files_marked', JSON.stringify(markedFiles)); }
        
        try { let modalWaEl = document.getElementById('modalWaFiles'); let modalWa = bootstrap.Modal.getInstance(modalWaEl); if(modalWa) modalWa.hide(); } catch(e){}
        
        curFileMode = 'wa'; curFileName = filename; curNamaAsli = namaAsli; curFileObj = null; $('#inputCustomPages').val(''); 
        doHitungRekom(); 
        autoRefreshWaFiles(true);
    }

    function prosesManualFile(inputOrFile) { 
        let file;
        if (inputOrFile instanceof File) { file = inputOrFile; } 
        else if (inputOrFile && inputOrFile.files && inputOrFile.files.length > 0) { file = inputOrFile.files[0]; inputOrFile.value = ''; } 
        else return;
        curFileMode = 'manual'; curFileName = file.name; curNamaAsli = file.name; curFileObj = file; $('#inputCustomPages').val(''); doHitungRekom();
    }
    function hitungUlangRekom() { if(curFileMode) doHitungRekom(); }
    
    let dragCounter = 0; const dropZone = document.getElementById('dragDropOverlay');
    window.addEventListener('dragenter', e => { e.preventDefault(); if (e.dataTransfer.types.includes('Files')) { dragCounter++; dropZone.style.display = 'flex'; }});
    window.addEventListener('dragleave', e => { e.preventDefault(); if (e.dataTransfer.types.includes('Files')) { dragCounter--; if (dragCounter <= 0) { dragCounter = 0; dropZone.style.display = 'none'; }}});
    window.addEventListener('dragover', e => e.preventDefault());
    window.addEventListener('drop', e => { e.preventDefault(); dragCounter = 0; dropZone.style.display = 'none'; if (e.dataTransfer.files && e.dataTransfer.files.length > 0) { let file = e.dataTransfer.files[0]; let ext = file.name.split('.').pop().toLowerCase(); if(['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'].includes(ext)) { prosesManualFile(file); } else { Swal.fire('Error Format', 'Format file tidak didukung.', 'error'); } }});

    function terapkanRekomendasiKeKeranjang(jsonStr, filename_terpilih = '', is_2_sisi = false, total_halaman = 0) { 
        let itemsToAdd = JSON.parse(jsonStr.replace(/&quot;/g, '"').replace(/&#39;/g, "'")); 
        let multiplier = parseInt($('#inputRangkapCetak').val()) || 1; if(multiplier < 1) multiplier = 1;

        itemsToAdd.forEach(it => { 
            let finalQty = it.qty_base * multiplier; 
            let existingItem = keranjang.find(i => i.kode === it.kode); 
            if(existingItem) { 
                existingItem.qty += finalQty; existingItem.harga = hitungHargaDinamis(it.data_barang, existingItem.qty); 
                if (is_2_sisi && !existingItem.nama.includes('[2 Sisi]')) existingItem.nama += ' [2 Sisi]';
                if(!existingItem.file_wa) existingItem.file_wa = filename_terpilih; 
            } else { 
                keranjang.push({ kode: it.kode, nama: it.nama + (is_2_sisi ? ' [2 Sisi]' : ''), harga: hitungHargaDinamis(it.data_barang, finalQty), qty: finalQty, diskon_item: 0, data_barang: it.data_barang, file_wa: filename_terpilih }); 
            } 
        }); 

        if (is_2_sisi && total_halaman > 1) {
            let lembar_hemat = Math.floor(total_halaman / 2) * multiplier; let diskon_tambahan = lembar_hemat * 50;
            let diskon_sekarang = parseFloat($('#inputDiskonGlobal').val()) || 0;
            $('#inputDiskonGlobal').val(diskon_sekarang + diskon_tambahan);
        }
        
        simpanStateKeranjang(); renderCart(); showToast();
        try { let modalRekomEl = document.getElementById('modalRekomHarga'); let modalRekom = bootstrap.Modal.getInstance(modalRekomEl) || bootstrap.Modal.getOrCreateInstance(modalRekomEl); modalRekom.hide(); } catch(e){}
        setTimeout(() => { $('.modal-backdrop').remove(); $('body').removeClass('modal-open').css({'overflow': '', 'padding-right': ''}); }, 400);
    }

    $('#wa_date_picker').on('change', function() { waDateFilter = $(this).val(); autoRefreshWaFiles(true); });

    function waToggleGroup(groupKey, checked) {
        $('.wa-check-' + groupKey).prop('checked', !!checked);
    }

    function openPrintAnalyzerSelectedWa(btn, groupKey, kodePelanggan='', namaPelanggan='', telp='', deposit=0, utang=0) {
        const checks = $('.wa-check-' + groupKey + ':checked');
        if (!checks.length) {
            if (typeof Swal !== 'undefined') Swal.fire('Belum pilih file', 'Centang minimal 1 file WhatsApp dulu.', 'warning');
            else alert('Centang minimal 1 file WhatsApp dulu.');
            return;
        }
        const files = [];
        const names = [];
        checks.each(function(){
            const fn = $(this).data('filename');
            if (fn) {
                files.push(String(fn));
                names.push(String($(this).data('original') || fn));
            }
        });
        if (!files.length) return;
        if (typeof openPrintAnalyzerFromWhatsAppMultiple === 'function') {
            openPrintAnalyzerFromWhatsAppMultiple(files, names, kodePelanggan, namaPelanggan, telp, deposit, utang);
        } else if (typeof openPrintAnalyzerFromWhatsApp === 'function') {
            openPrintAnalyzerFromWhatsApp(files[0], names[0] || files[0], kodePelanggan, namaPelanggan, telp, deposit, utang);
        }
    }
    
    function updateMarkedFilesUI() {
        let markedFiles = JSON.parse(localStorage.getItem('wa_files_marked') || '[]');
        $('.btn-rekom-wa').each(function() {
            let filename = $(this).data('filename');
            if (markedFiles.includes(filename)) { $(this).removeClass('btn-warning').addClass('btn-success').html('<i class="fas fa-check"></i>'); $(this).closest('li').css('background-color', '#f0fff4'); }
        });
        if (typeof paPromotePinnedWaClient === 'function') {
            paPromotePinnedWaClient(false);
        }
    }

    function autoRefreshWaFiles(force = false) { 
        $.ajax({ 
            url: 'index.php', type: 'POST', dataType: 'json', data: { action: 'cek_wa_baru_html', wa_date: waDateFilter }, 
            success: function(res) { 
                if (res.status === 'success') { 
                    if (res.hash !== waFilesHash || force) { 
                        waFilesHash = res.hash; $('#accordionWaFilesFront').html(res.html_modal); updateMarkedFilesUI(); 
                        if(res.count > 0) { $('.wa-badge-count').text(res.count).show(); } else { $('.wa-badge-count').hide(); } 
                    } 
                } 
            } 
        }); 
    }
    
    // --- LOAD AWAL & EVENT LISTENERS UTAMA ---
    $(document).ready(function() {

        // AUTO FOCUS SELECT2 (KATEGORI & PELANGGAN)
        $(document).on('select2:open', function(e) {
            setTimeout(function() {
                const searchField = document.querySelector('.select2-container--open .select2-search__field');
                if(searchField) { searchField.focus(); }
            }, 100); 
        });

        // Trigger realtime tiap kali angka rangkap diubah
        $('#inputRangkapCetak').on('input change', function() {
            renderListHargaRekom();
        });

        // Init Kategori Select2
        $('#filterKategori').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#filterKategori').on('change', loadProducts); 

        // Init Pelanggan Select2
        $('#pelanggan').select2({ theme: 'bootstrap-5', ajax: { url: 'get_pelanggan_json.php', dataType: 'json', delay: 250, processResults: function (data) { return { results: data.results }; } }, placeholder: 'Cari Pelanggan...', minimumInputLength: 0, templateResult: formatPelangganResult, templateSelection: formatPelangganSelection }); 
        $('#pelanggan').on('select2:select', function (e) { let data = e.params.data; let pDepo = parseFloat(data.saldo_deposit) || 0; let pUtang = parseFloat(data.sisa_utang) || 0; activePelanggan = { id: data.id, text: data.text, deposit: pDepo, utang: pUtang, telp: data.telp || '' }; localStorage.setItem('pos_pelanggan_data_v2', JSON.stringify(activePelanggan)); updateInfoPelangganUI(); renderCart(); }); 
        $('#pelanggan').on('select2:closing', function() { let searchField = document.querySelector('.select2-container--open .select2-search__field'); if(searchField) { lastPelangganSearch = searchField.value; } }); 
        
        // Search trigger
        let searchTimer; $('#keyword').on('input', function() { clearTimeout(searchTimer); const val = $(this).val(); if(val.length > 0) $('#loading').show(); searchTimer = setTimeout(function() { loadProducts(); }, 400); }); 
        $('#keyword, #inputBayar, #inputDiskonGlobal, #inputOngkir').on('focus click', function() { $(this).select(); }); 

        // Load data on start
        setViewMode(currentViewMode);
        loadProducts(); 
        
        // Cek LocalStorage
        let savedPelanggan = localStorage.getItem('pos_pelanggan_data_v2'); if (savedPelanggan) { let pData = JSON.parse(savedPelanggan); activePelanggan = pData; let newOption = new Option(pData.text, pData.id, true, true); $('#pelanggan').append(newOption).trigger('change'); updateInfoPelangganUI(); if (pData.id && pData.id !== 'UMUM' && typeof setPelangganAktif === 'function') { setPelangganAktif(pData.id, pData.text, pData.telp || '', pData.deposit || 0, pData.utang || 0); } } 
        let savedCart = localStorage.getItem('pos_keranjang'); if (savedCart) { try { keranjang = JSON.parse(savedCart); renderCart(); } catch(e){} }
        
        // Refresh Files WhatsApp
        autoRefreshWaFiles(true); setInterval(autoRefreshWaFiles, 3000);

        $('#formTelpPelanggan, #inputNoWA').on('input', function() { let cleaned = $(this).val().replace(/[^0-9]/g, ''); $(this).val(cleaned); });
        $('#modalTambahPelanggan').on('shown.bs.modal', function () { $('#formNamaPelanggan').focus(); });
        $('#formTambahPelanggan input, #formTambahPelanggan textarea').on('keypress', function(e) { if (e.which === 13) { e.preventDefault(); simpanPelangganBaru(); } });
        
        document.getElementById('inputBayar').addEventListener('keyup', hitungKembali);
        document.getElementById('metodePembayaran').addEventListener('change', function() { let grandTotal = parseFloat(document.getElementById('displayTotal').dataset.grandTotal || 0); let input = document.getElementById('inputBayar'); if (this.value === 'Cash' || this.value === 'Utang') { input.value = (this.value === 'Utang') ? 0 : grandTotal; input.readOnly = false; if(this.value === 'Cash') input.focus(); } else { input.value = grandTotal; input.readOnly = true; } renderCart(); });
        document.getElementById('inputDiskonGlobal').addEventListener('input', renderCart);
        document.getElementById('inputOngkir').addEventListener('input', renderCart);
    });

    function toggleInputDeposit(el) { const input = document.getElementById('inputNominalDeposit'); input.disabled = !el.checked; if(el.checked) { input.focus(); input.select(); } }
    function formatPelangganResult(state) { if (!state.id) return state.text; let depo = parseFloat(state.saldo_deposit) || 0; let utang = parseFloat(state.sisa_utang) || 0; let badges = ''; if(depo >= 10) badges += `<span class="badge bg-success" style="font-size:0.65em">Depo: ${formatRupiah(depo)}</span> `; if(utang >= 10) badges += `<span class="badge bg-danger" style="font-size:0.65em">Utang: ${formatRupiah(utang)}</span>`; return $(`<div class="d-flex justify-content-between align-items-center"><div><span class="fw-bold small">${state.text}</span><br><small class="text-muted" style="font-size:0.7em">${state.telp || '-'}</small></div><div class="text-end">${badges}</div></div>`); }
    function formatPelangganSelection(state) { return state.text; }
    function updateInfoPelangganUI() { $('#infoPelangganContainer').hide(); $('#rowDeposit').hide(); $('#rowUtang').hide(); if (activePelanggan.id !== 'UMUM') { let adaInfo = false; let safeDepo = parseFloat(activePelanggan.deposit) || 0; let safeUtang = parseFloat(activePelanggan.utang) || 0; if (safeDepo >= 10) { $('#rowDeposit').css('display', 'block'); $('#lblSaldoDeposit').text(formatRupiah(safeDepo)); adaInfo = true; } if (safeUtang >= 10) { $('#rowUtang').css('display', 'block'); $('#lblSisaUtang').text(formatRupiah(safeUtang)); adaInfo = true; } if (adaInfo) $('#infoPelangganContainer').fadeIn(); } }
    
    // --- LOAD PRODUCTS ---
    function loadProducts() { 
        const keyword = document.getElementById('keyword').value; 
        const kategori = $('#filterKategori').val(); 
        const container = document.getElementById('productContainer'); 
        const loading = document.getElementById('loading'); 
        container.innerHTML = ''; loading.style.display = 'block'; productShortcuts = {}; 
        
        fetch(`get_barang.php?keyword=${keyword}&kategori=${kategori}`).then(async response => { 
            const text = await response.text(); 
            loading.style.display = 'none'; 
            if (!response.ok) throw new Error("Server Error"); 
            try { 
                let data = JSON.parse(text); products = Array.isArray(data) ? data : []; 
                renderProducts(); setTimeout(() => startTour(false), 1000); 
            } catch (e) { container.innerHTML = `<div class="col-12 text-center text-danger py-5">Gagal memuat data.</div>`; } 
        }).catch(err => { loading.style.display = 'none'; container.innerHTML = `<div class="col-12 text-center text-danger py-5">Koneksi Gagal.</div>`; }); 
    }
    
    function renderProducts() { 
        const container = document.getElementById('productContainer'); 
        if (!products || products.length === 0) { container.innerHTML = '<div class="col-12 text-center text-muted py-5"><i class="fas fa-box-open fa-3x mb-3 opacity-25"></i><br>Barang tidak ditemukan.</div>'; return; } 
        
        let htmlContent = ''; 
        let catText = $('#filterKategori option:selected').text();
        let catName = (catText === 'Semua Kategori' || !catText) ? 'Umum' : catText;

        products.forEach((p, index) => { 
            let nama = p.nama_barang || "Tanpa Nama"; let stok = parseFloat(p.stok) || 0; let harga = parseFloat(p.harga_jual) || 0; let kode = p.kode_barang || ""; 
            let badgeGrosir = (p.grosir && p.grosir.length > 0) ? '<span class="badge bg-warning text-dark position-absolute top-0 start-0 m-1 shadow-sm" style="font-size:0.55rem; z-index:5;"><i class="fas fa-tags"></i> Grosir</span>' : ''; 
            
            let shortcutLabel = ''; 
            if (index < shortcutKeys.length) { 
                let keyChar = shortcutKeys.charAt(index); 
                shortcutLabel = `<div class="product-key shadow" id="key-${keyChar}">${keyChar}</div>`; 
                productShortcuts[keyChar] = kode; 
            } 
            let safeName = nama.replace(/"/g, '&quot;'); 
            let safeKode = kode.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            let idAttr = (index === 0) ? 'id="tour-first-product"' : ''; 

            htmlContent += `
            <div class="col-6 col-md-4 col-lg-3" ${idAttr}>
                <div class="card product-card h-100" onclick="addToCart('${safeKode}')">
                    ${badgeGrosir}${shortcutLabel}
                    <div class="product-img-placeholder">
                        <i class="fas fa-box"></i>
                        <span class="badge-cat" style="display:none;">${catName}</span>
                    </div>
                    <div class="card-body card-body-custom">
                        <div class="product-info"><div class="product-name" title="${safeName}">${nama}</div><small class="product-stok">Stok: ${stok}</small></div>
                        <div class="product-bottom"><div class="product-price">${formatRupiah(harga)}</div><button class="btn-add-circle" style="display:none;"><i class="fas fa-plus"></i></button></div>
                    </div>
                </div>
            </div>`; 
        }); 
        container.innerHTML = htmlContent; 
    }

    function addToCart(kode) {
        if (!products || products.length === 0) return;
        const barang = products.find(p => p.kode_barang === kode);
        if(!barang) return;
        let isJasa = (barang.satuan && barang.satuan.toLowerCase().includes('jasa'));
        if(!isJasa && barang.stok <= 0) { Swal.fire({ icon: 'warning', title: 'Stok Habis!', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }); return; }
        
        let item = keranjang.find(i => i.kode === kode);
        if(item) {
            if(!isJasa && item.qty + 1 > barang.stok) { Swal.fire({ icon: 'warning', title: 'Stok Tidak Cukup!', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }); return; }
            item.qty++; item.harga = hitungHargaDinamis(barang, item.qty);
        } else {
            let qtyAwal = 1;
            keranjang.push({ kode: barang.kode_barang, nama: barang.nama_barang, harga: hitungHargaDinamis(barang, qtyAwal), qty: qtyAwal, diskon_item: 0, data_barang: barang });
        }
        
        simpanStateKeranjang(); 
        showToast();
        renderCart();
        $('#mobileCartCount').addClass('bg-warning').removeClass('bg-danger');
        setTimeout(() => $('#mobileCartCount').removeClass('bg-warning').addClass('bg-danger'), 200);
    }

    function hitungHargaDinamis(barang, qty) { let hargaFinal = parseFloat(barang.harga_jual); if (barang.grosir && Array.isArray(barang.grosir)) { for (let rule of barang.grosir) { if (qty >= parseInt(rule.min_qty)) { hargaFinal = parseFloat(rule.harga_grosir); break; } } } return hargaFinal; }
    function updateQty(index, change) { let item = keranjang[index]; let barang = item.data_barang; if(item.qty + change <= 0) { hapusItem(index); } else { let isJasa = (barang.satuan && barang.satuan.toLowerCase().includes('jasa')); if(!isJasa && change > 0 && item.qty + 1 > barang.stok) { Swal.fire({ icon: 'warning', title: 'Stok Mentok!', toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }); return; } item.qty += change; item.harga = hitungHargaDinamis(barang, item.qty); simpanStateKeranjang(); renderCart(); } }
    function updateQtyManual(index, val) { let item = keranjang[index]; let barang = item.data_barang; let newQty = parseInt(val); if (isNaN(newQty) || newQty < 1) { newQty = 1; } let isJasa = (barang.satuan && barang.satuan.toLowerCase().includes('jasa')); if (!isJasa && newQty > barang.stok) { Swal.fire({ icon: 'warning', title: 'Stok Tidak Cukup!', text: `Sisa stok hanya: ${barang.stok}`, toast: true, position: 'top-end', showConfirmButton: false, timer: 1500 }); newQty = barang.stok; } item.qty = newQty; item.harga = hitungHargaDinamis(barang, item.qty); simpanStateKeranjang(); renderCart(); }
    function updateDiskonItem(index, val) { let diskon = parseFloat(val) || 0; keranjang[index].diskon_item = diskon; simpanStateKeranjang(); renderCart(); setTimeout(() => { let input = document.getElementById(`diskon-input-${index}`); if(input) { input.focus(); } }, 50); }
    function hapusItem(index) { keranjang.splice(index, 1); simpanStateKeranjang(); renderCart(); }
    function resetKeranjang() { if(keranjang.length > 0 && confirm('Hapus semua isi keranjang?')) { keranjang = []; localStorage.removeItem('pos_keranjang'); localStorage.removeItem('wa_files_marked'); renderCart(); autoRefreshWaFiles(true); } }   
    function simpanStateKeranjang() { localStorage.setItem('pos_keranjang', JSON.stringify(keranjang)); }
    
    // --- RENDER CART ---
    function renderCart() {
        const container = document.getElementById('cartContainer');
        const totalElem = document.getElementById('displayTotal');
        const diskonGlobalInput = document.getElementById('inputDiskonGlobal');
        const ongkirInput = document.getElementById('inputOngkir');

        let diskonGlobal = parseFloat(diskonGlobalInput.value) || 0;
        let ongkir = parseFloat(ongkirInput.value) || 0;

        if(keranjang.length === 0) {
            container.innerHTML = `<div class="text-center text-muted mt-5 pt-5"><i class="fas fa-shopping-basket fa-3x mb-3 opacity-25"></i><p class="fw-bold">Belum ada item dipilih</p></div>`;
            totalElem.innerText = formatRupiah(0); totalElem.dataset.value = 0; totalElem.dataset.depositUsed = 0; totalElem.dataset.grandTotal = 0; totalElem.dataset.utangLama = 0; totalElem.dataset.diskonGlobal = 0; totalElem.dataset.ongkir = 0;
            document.getElementById('totalItemsBadge').innerText = "0 Item"; document.getElementById('mobileCartCount').innerText = "0";
            $('#rowPotonganDeposit').hide(); $('#rowUtangLama').hide(); $('#rowGrandTotal').hide();
            hitungKembali(); return;
        }

        let grandTotal = 0;
        let totalQty = 0;

        function renderCartControls(index, item) {
            return `<div class="d-flex align-items-center justify-content-end mb-1"><input type="number" id="diskon-input-${index}" class="input-diskon-item me-1" placeholder="Disc" value="${item.diskon_item > 0 ? item.diskon_item : ''}" onchange="updateDiskonItem(${index}, this.value)"><button class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:0.7rem" onclick="updateQty(${index}, -1)">-</button><input type="number" class="form-control form-control-sm input-qty" value="${item.qty}" onchange="updateQtyManual(${index}, this.value)" onfocus="this.select()"><button class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:0.7rem" onclick="updateQty(${index}, 1)">+</button><button class="btn btn-link text-danger btn-sm py-0 ms-1 px-0" onclick="hapusItem(${index})"><i class="fas fa-times"></i></button></div>`;
        }

        function renderNormalItem(item, index) {
            let subtotal = (item.harga * item.qty) - (item.diskon_item || 0);
            if(subtotal < 0) subtotal = 0;
            let hargaNormal = parseFloat(item.data_barang.harga_jual);
            let badgeDiskon = (item.harga < hargaNormal) ? `<span class="badge bg-success ms-1" style="font-size:0.55rem">Grosir</span>` : '';
            return `<li class="list-group-item px-2 py-2"><div class="d-flex justify-content-between align-items-start"><div class="cart-line-main"><div class="fw-bold text-break cart-product-title">${escHtml(item.nama)} ${badgeDiskon}</div><small class="text-muted" style="font-size:0.65em">${formatRupiah(item.harga)}</small></div><div class="text-end flex-shrink-0 cart-line-side"><div class="fw-bold mb-1" style="font-size:0.8rem">${formatRupiah(subtotal)}</div>${renderCartControls(index, item)}</div></div></li>`;
        }

        function renderAnalyzerGroup(fileName, rows) {
            let fileTotal = rows.reduce((sum, row) => {
                const subtotal = (row.item.harga * row.item.qty) - (row.item.diskon_item || 0);
                return sum + Math.max(0, subtotal);
            }, 0);
            let filePages = rows.reduce((sum, row) => sum + (parseFloat(row.item.analyzer_meta?.pages || 0) || 0), 0);
            let fileSheets = rows.reduce((sum, row) => sum + (parseFloat(row.item.analyzer_meta?.sheets || 0) || 0), 0);
            let html = `<li class="list-group-item p-0 border-start border-warning border-4">
                <div class="px-2 py-2 bg-warning-subtle border-bottom">
                    <div class="d-flex justify-content-between gap-2 align-items-start">
                        <div class="text-break">
                            <div class="fw-bold" style="font-size:0.82rem;"><i class="fas fa-file-alt me-1"></i>${escHtml(fileName)}</div>
                            <small class="text-muted">${rows.length} barang/jasa • ${filePages} halaman • ${fileSheets} sheet</small>
                        </div>
                        <div class="fw-bold text-end" style="font-size:0.82rem; white-space:nowrap;">${formatRupiah(fileTotal)}</div>
                    </div>
                </div>
                <div class="list-group list-group-flush">`;

            rows.forEach(({item, index}) => {
                let subtotal = (item.harga * item.qty) - (item.diskon_item || 0);
                if(subtotal < 0) subtotal = 0;
                let hargaNormal = parseFloat(item.data_barang.harga_jual);
                let badgeDiskon = (item.harga < hargaNormal) ? `<span class="badge bg-success ms-1" style="font-size:0.55rem">Grosir</span>` : '';
                let cat = item.analyzer_meta?.category_label || '';
                let pages = item.analyzer_meta?.pages || 0;
                let qtyCetak = item.analyzer_meta?.qty_cetak || 1;
                let pagesPerSheet = item.analyzer_meta?.pages_per_sheet || 1;
                let sheets = item.analyzer_meta?.sheets || pages;
                let pricingBasis = item.analyzer_meta?.pricing_basis || 'sheet';
                let pageSheetText = pricingBasis === 'sheet'
                    ? `${pages} hlm dalam ${sheets} sheet × ${qtyCetak}`
                    : (pagesPerSheet > 1 ? `${pages} hlm ÷ ${pagesPerSheet}/sheet = ${sheets} sheet × ${qtyCetak}` : `${pages} hlm × ${qtyCetak}`);
                html += `<div class="list-group-item px-2 py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="cart-line-main">
                            <div class="fw-bold text-break cart-product-title">${escHtml(item.nama)} ${badgeDiskon}</div>
                            <div class="mt-1 d-flex flex-wrap gap-1">
                                <span class="badge bg-dark-subtle text-dark border" style="font-size:0.62rem;">${escHtml(cat)}</span>
                                <span class="badge bg-light text-dark border" style="font-size:0.62rem;">${pageSheetText}</span>
                            </div>
                            <small class="text-muted" style="font-size:0.65em">${formatRupiah(item.harga)}</small>
                        </div>
                        <div class="text-end flex-shrink-0 cart-line-side">
                            <div class="fw-bold mb-1" style="font-size:0.8rem">${formatRupiah(subtotal)}</div>
                            ${renderCartControls(index, item)}
                        </div>
                    </div>
                </div>`;
            });
            html += `</div></li>`;
            return html;
        }

        let html = '<ul class="list-group list-group-flush small">';
        let analyzerGroups = {};
        let analyzerOrder = [];
        let normalRows = [];

        keranjang.forEach((item, index) => {
            let subtotal = (item.harga * item.qty) - (item.diskon_item || 0);
            if(subtotal < 0) subtotal = 0;
            grandTotal += subtotal;
            totalQty += item.qty;

            if(item.analyzer_meta && item.analyzer_meta.file_name) {
                const key = item.analyzer_meta.file_name;
                if(!analyzerGroups[key]) { analyzerGroups[key] = []; analyzerOrder.push(key); }
                analyzerGroups[key].push({item, index});
            } else {
                normalRows.push({item, index});
            }
        });

        normalRows.forEach(({item, index}) => { html += renderNormalItem(item, index); });
        analyzerOrder.forEach(fileName => { html += renderAnalyzerGroup(fileName, analyzerGroups[fileName]); });
        html += '</ul>';
        container.innerHTML = html;

        let totalKasar = (grandTotal - diskonGlobal) + ongkir;
        if(totalKasar < 0) totalKasar = 0;
        let utangLama = parseFloat(activePelanggan.utang) || 0;
        if(utangLama < 10) utangLama = 0;
        let depositTersedia = parseFloat(activePelanggan.deposit) || 0;
        if(depositTersedia < 10) depositTersedia = 0;
        let totalTagihanCek = totalKasar + utangLama;

        if (activePelanggan.id !== 'UMUM' && totalTagihanCek > 0) {
            let currentMetode = $('#metodePembayaran').val();
            if (depositTersedia >= totalTagihanCek) { if (currentMetode !== 'Deposit') { $('#metodePembayaran').val('Deposit'); } }
            else { if (currentMetode === 'Deposit') { $('#metodePembayaran').val('Cash'); } }
        }

        let metode = document.getElementById('metodePembayaran').value;
        let depositDipakai = 0;
        let divInfoDepo = $('#infoPotonganDepositDetail');

        if (metode === 'Deposit' && activePelanggan.id !== 'UMUM') {
            let tagihan = totalTagihanCek; let sisaAkhir = depositTersedia - tagihan; depositDipakai = tagihan;
            divInfoDepo.html(`<div class="d-flex justify-content-between"><span>Saldo Awal:</span> <span class="fw-bold">${formatRupiah(depositTersedia)}</span></div><div class="d-flex justify-content-between text-danger"><span>Akan Dipotong:</span> <span class="fw-bold">-${formatRupiah(tagihan)}</span></div><hr class="my-1"><div class="d-flex justify-content-between"><span>Sisa Saldo:</span> <span class="fw-bold ${sisaAkhir < 0 ? 'text-danger' : 'text-success'}">${formatRupiah(sisaAkhir)}</span></div>`).show();
        } else { divInfoDepo.hide(); }

        if (metode !== 'Deposit') {
            if (depositTersedia > 0) { depositDipakai = Math.min(totalKasar, depositTersedia); $('#rowPotonganDeposit').css('display', 'flex'); $('#lblPotonganDeposit').text('-' + formatRupiah(depositDipakai)); }
            else { $('#rowPotonganDeposit').hide(); }
        } else { $('#rowPotonganDeposit').hide(); }

        let totalBelanjaNet = totalKasar;
        if(metode !== 'Deposit') { totalBelanjaNet -= depositDipakai; } else { totalBelanjaNet = 0; }

        let displayGrandTotal = (totalKasar - depositDipakai) + utangLama;
        if(metode === 'Deposit') displayGrandTotal = 0;

        if (utangLama > 0) {
            $('#rowUtangLama').css('display', 'flex'); $('#lblUtangLama').text('+' + formatRupiah(utangLama)); $('#rowGrandTotal').show(); $('#lblGrandTotal').text(formatRupiah(displayGrandTotal));
            totalElem.innerText = formatRupiah(totalKasar - depositDipakai); totalElem.classList.add('text-muted', 'fs-6'); totalElem.classList.remove('text-primary', 'fs-2'); $('#labelTotal').text("Total Belanja Saat Ini");
        } else {
            $('#rowUtangLama').hide(); $('#rowGrandTotal').hide(); $('#labelTotal').text("Total Bayar");
            totalElem.innerText = formatRupiah(totalKasar - depositDipakai); totalElem.classList.remove('text-muted', 'fs-6'); totalElem.classList.add('text-primary', 'fs-2');
        }

        totalElem.dataset.value = totalKasar; totalElem.dataset.depositUsed = depositDipakai; totalElem.dataset.utangLama = utangLama; totalElem.dataset.grandTotal = displayGrandTotal; totalElem.dataset.diskonGlobal = diskonGlobal; totalElem.dataset.ongkir = ongkir;
        document.getElementById('totalItemsBadge').innerText = totalQty + " Item"; document.getElementById('mobileCartCount').innerText = totalQty;

        let inputBayar = document.getElementById('inputBayar');
        if (metode === 'Deposit' || metode === 'QRIS' || metode === 'Debit' || metode === 'Transfer') { inputBayar.value = (metode === 'Deposit') ? 0 : totalElem.dataset.grandTotal; inputBayar.readOnly = true; }
        else if (metode !== 'Utang') { inputBayar.readOnly = false; }

        hitungKembali();
    }
    
    function pecahanKembalianKasir(nominal) {
        return pecahanObjectToTextKasir(pecahanObjectKasir(nominal)).split(' • ').filter(Boolean);
    }


    const PECAHAN_CASH_KASIR = [100000,50000,20000,10000,5000,2000,1000,500,200,100];
    let pecahanBayarDipilihKasir = {};
    let pecahanKasTersediaKasir = {};
    let pecahanKasLastLoadKasir = 0;

    function pecahanObjectKasir(nominal) {
        nominal = Math.floor(Number(nominal) || 0);
        let sisa = nominal;
        let obj = {};
        PECAHAN_CASH_KASIR.forEach(function(p){
            const qty = Math.floor(sisa / p);
            if (qty > 0) {
                obj[p] = qty;
                sisa -= qty * p;
            }
        });
        return obj;
    }

    function pecahanObjectToTextKasir(obj) {
        obj = obj || {};
        let rows = [];
        PECAHAN_CASH_KASIR.forEach(function(p){
            const qty = parseInt(obj[p] || 0, 10);
            if (qty > 0) rows.push(qty + '× ' + formatRupiah(p));
        });
        return rows.join(' • ');
    }

    function totalPecahanObjectKasir(obj) {
        obj = obj || {};
        let total = 0;
        PECAHAN_CASH_KASIR.forEach(function(p){ total += p * (parseInt(obj[p] || 0, 10) || 0); });
        return total;
    }


    function clonePecahanKasir(obj) {
        const out = {};
        obj = obj || {};
        PECAHAN_CASH_KASIR.forEach(function(p){
            const qty = parseInt(obj[p] || obj[String(p)] || 0, 10);
            if (qty > 0) out[p] = qty;
        });
        return out;
    }

    function tambahStokPecahanKasir(base, add) {
        const out = clonePecahanKasir(base);
        add = add || {};
        PECAHAN_CASH_KASIR.forEach(function(p){
            const qty = parseInt(add[p] || add[String(p)] || 0, 10);
            if (qty > 0) out[p] = (parseInt(out[p] || 0, 10) || 0) + qty;
        });
        return out;
    }

    function suggestPecahanDariStokKasir(nominal, stok) {
        nominal = Math.floor(Number(nominal) || 0);
        stok = clonePecahanKasir(stok);
        let sisa = nominal;
        let out = {};
        PECAHAN_CASH_KASIR.forEach(function(p){
            const tersedia = parseInt(stok[p] || 0, 10);
            if (tersedia <= 0) return;
            const butuh = Math.floor(sisa / p);
            const ambil = Math.min(butuh, tersedia);
            if (ambil > 0) {
                out[p] = ambil;
                sisa -= ambil * p;
            }
        });
        return {pecahan: out, sisa: sisa};
    }

    function loadPecahanKasTersediaKasir(force) {
        const now = Date.now();
        if (!force && now - pecahanKasLastLoadKasir < 12000) return Promise.resolve(pecahanKasTersediaKasir);
        pecahanKasLastLoadKasir = now;
        return fetch('../kas/cash_available.php', {credentials:'same-origin'})
            .then(r => r.json())
            .then(res => {
                if (res && res.status === 'success' && res.open) pecahanKasTersediaKasir = clonePecahanKasir(res.pecahan || {});
                return pecahanKasTersediaKasir;
            })
            .catch(() => pecahanKasTersediaKasir);
    }

    function renderPecahanBayarKasir() {
        const box = document.getElementById('boxPecahanBayar');
        const buttons = document.getElementById('pecahanBayarButtons');
        const summary = document.getElementById('pecahanBayarSummary');
        const detected = document.getElementById('pecahanBayarDetected');
        const metode = document.getElementById('metodePembayaran') ? document.getElementById('metodePembayaran').value : 'Cash';
        if (!box || !buttons || !summary) return;
        if (metode !== 'Cash') {
            box.style.display = 'none';
            pecahanBayarDipilihKasir = {};
            return;
        }
        box.style.display = 'block';
        buttons.innerHTML = PECAHAN_CASH_KASIR.map(function(p){
            const qty = parseInt(pecahanBayarDipilihKasir[p] || 0, 10);
            return '<button type="button" class="btn btn-sm '+(qty>0?'btn-primary':'btn-outline-primary')+' py-1 px-2" style="font-size:0.62rem" onclick="tambahPecahanBayarKasir('+p+')">'+formatRupiah(p).replace('Rp ','')+(qty>0?' <b>×'+qty+'</b>':'')+'</button>';
        }).join('');
        const total = totalPecahanObjectKasir(pecahanBayarDipilihKasir);
        if (total > 0) {
            summary.innerHTML = '<b>Total:</b> '+formatRupiah(total)+' <span class="text-muted">('+pecahanObjectToTextKasir(pecahanBayarDipilihKasir)+')</span>';
        } else {
            summary.innerHTML = 'Tap pecahan uang yang diterima.';
        }
        if (detected) {
            const manualBayar = parseFloat(document.getElementById('inputBayar')?.value || 0);
            const detectedObj = pecahanObjectKasir(manualBayar);
            const detectedText = pecahanObjectToTextKasir(detectedObj);
            detected.innerHTML = manualBayar > 0 ? '<i class="fas fa-wand-magic-sparkles me-1"></i>Terdeteksi: '+(detectedText || '-') : '';
        }
    }

    function tambahPecahanBayarKasir(nominal) {
        nominal = parseInt(nominal || 0, 10);
        if (!nominal) return;
        pecahanBayarDipilihKasir[nominal] = (parseInt(pecahanBayarDipilihKasir[nominal] || 0, 10) || 0) + 1;
        const total = totalPecahanObjectKasir(pecahanBayarDipilihKasir);
        const input = document.getElementById('inputBayar');
        if (input) input.value = total;
        renderPecahanBayarKasir();
        hitungKembali();
    }

    function resetPecahanBayarKasir() {
        pecahanBayarDipilihKasir = {};
        const input = document.getElementById('inputBayar');
        if (input) input.value = '';
        renderPecahanBayarKasir();
        hitungKembali();
    }

    function getPecahanBayarUntukSyncKasir() {
        const selectedTotal = totalPecahanObjectKasir(pecahanBayarDipilihKasir);
        if (selectedTotal > 0) return pecahanBayarDipilihKasir;
        const bayar = parseFloat(document.getElementById('inputBayar')?.value || 0);
        return pecahanObjectKasir(bayar);
    }

    function renderSuggestKembalianKasir(kembali, metode) {
        const box = document.getElementById('suggestKembalianKasir');
        if (!box) return;
        if (metode !== 'Cash' || kembali <= 0) {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }
        const stokDenganUangDiterima = tambahStokPecahanKasir(pecahanKasTersediaKasir, getPecahanBayarUntukSyncKasir());
        const stokSuggest = suggestPecahanDariStokKasir(kembali, stokDenganUangDiterima);
        const stokText = pecahanObjectToTextKasir(stokSuggest.pecahan);
        const fallbackText = pecahanObjectToTextKasir(pecahanObjectKasir(kembali));
        box.style.display = 'block';
        let html = '<div class="fw-bold text-success mb-1"><i class="fas fa-coins me-1"></i>Saran ambil kembalian sesuai pecahan kas:</div>';
        if (stokText && stokSuggest.sisa <= 0) {
            html += '<div style="white-space:normal; overflow:visible; word-break:break-word; line-height:1.35;">' + stokText.split(' • ').join(' <span class="text-muted">•</span> ') + '</div>';
        } else if (stokText) {
            html += '<div style="white-space:normal; overflow:visible; word-break:break-word; line-height:1.35;">' + stokText.split(' • ').join(' <span class="text-muted">•</span> ') + '</div>';
            html += '<div class="text-warning mt-1" style="white-space:normal; word-break:break-word; line-height:1.35;"><i class="fas fa-triangle-exclamation me-1"></i>Pecahan tercatat belum cukup. Sisa: '+formatRupiah(stokSuggest.sisa)+'. Alternatif hitung nominal: '+fallbackText+'</div>';
        } else {
            html += '<div class="text-warning" style="white-space:normal; word-break:break-word; line-height:1.35;"><i class="fas fa-triangle-exclamation me-1"></i>Pecahan kas belum tercatat/cukup. Alternatif hitung nominal: '+fallbackText+'</div>';
        }
        html += '<div class="text-muted mt-1" style="font-size:0.68rem">Stok: cash awal + uang diterima - kembalian.</div>';
        box.innerHTML = html;
    }

    function hitungKembali() { 
        let grandTotal = parseFloat(document.getElementById('displayTotal').dataset.grandTotal || 0); 
        let bayar = parseFloat(document.getElementById('inputBayar').value || 0); 
        let metode = document.getElementById('metodePembayaran') ? document.getElementById('metodePembayaran').value : 'Cash';
        if (metode === 'Cash') {
            const selectedTotal = totalPecahanObjectKasir(pecahanBayarDipilihKasir);
            if (selectedTotal > 0 && selectedTotal !== bayar) { pecahanBayarDipilihKasir = {}; }
        }
        let kembali = bayar - grandTotal; 
        const areaDeposit = document.getElementById('areaSimpanDeposit'); const inputDeposit = document.getElementById('inputNominalDeposit'); const chkDeposit = document.getElementById('chkSimpanDeposit'); 
        
        if (kembali < 0) { 
            document.getElementById('displayKembali').innerHTML = `<span class="text-danger">Kurang: ${formatRupiah(Math.abs(kembali))}</span>`; 
            if(areaDeposit) areaDeposit.style.display = 'none'; 
            if(chkDeposit) chkDeposit.checked = false; 
            if(inputDeposit) { inputDeposit.disabled = true; inputDeposit.value = 0; } 
        } else { 
            document.getElementById('displayKembali').innerHTML = `<span class="text-success">Kembali: ${formatRupiah(kembali)}</span>`; 
            if (kembali > 0 && activePelanggan.id !== 'UMUM') { 
                if(areaDeposit) areaDeposit.style.display = 'flex'; 
                if(inputDeposit && !chkDeposit.checked) { inputDeposit.value = kembali; } 
            } else { 
                if(areaDeposit) areaDeposit.style.display = 'none'; 
                if(chkDeposit) { chkDeposit.checked = false; } 
            } 
        }
        renderSuggestKembalianKasir(kembali, metode);
        if (metode === 'Cash' && kembali > 0) {
            loadPecahanKasTersediaKasir(false).then(function(){ renderSuggestKembalianKasir(kembali, metode); });
        }
        renderPecahanBayarKasir();
    }
    
    function prosesBayar() { 
        let totalKasar = parseFloat(document.getElementById('displayTotal').dataset.value || 0); 
        let utangLama = parseFloat(document.getElementById('displayTotal').dataset.utangLama || 0); 
        let diskonGlobal = parseFloat(document.getElementById('displayTotal').dataset.diskonGlobal || 0); 
        let ongkir = parseFloat(document.getElementById('displayTotal').dataset.ongkir || 0); 
        let depositDipakai = parseFloat(document.getElementById('displayTotal').dataset.depositUsed || 0); 
        let bayar = parseFloat(document.getElementById('inputBayar').value || 0); 
        let metode = document.getElementById('metodePembayaran').value; 
        let tanggalTransaksi = (document.getElementById('tanggalTransaksi')?.value || '').trim();
        let pelanggan = $('#pelanggan').val(); 
        let simpanKeDeposit = 0; let chkDeposit = document.getElementById('chkSimpanDeposit'); let inputDeposit = document.getElementById('inputNominalDeposit'); 
        
        if (chkDeposit && chkDeposit.checked && activePelanggan.id !== 'UMUM') { let valDeposit = parseFloat(inputDeposit.value || 0); let totalYangHarusDibayar = (totalKasar - depositDipakai) + utangLama; let maxKembali = bayar - totalYangHarusDibayar; if (valDeposit > maxKembali) { return Swal.fire({ icon: 'warning', title: 'Invalid Deposit', text: 'Nominal deposit tidak boleh melebihi jumlah kembalian!' }); } if (valDeposit > 0) { simpanKeDeposit = valDeposit; } } 
        if(keranjang.length === 0) return alert('Keranjang kosong'); 
        if(metode === 'Utang' && pelanggan === 'UMUM') return alert('Pelanggan UMUM tidak boleh hutang'); 
        
        if (metode === 'Deposit') { 
            if (pelanggan === 'UMUM') return alert('Pelanggan UMUM tidak punya Deposit!'); 
            let totalTagihan = totalKasar + utangLama; 
            if (activePelanggan.deposit < totalTagihan) { return Swal.fire({ icon: 'error', title: 'Saldo Kurang!', text: `Saldo Deposit hanya ${formatRupiah(activePelanggan.deposit)}, sedangkan tagihan ${formatRupiah(totalTagihan)}` }); } 
        } else if(metode !== 'Utang' && bayar < 1) { return alert('Masukkan jumlah uang!'); } 
        
        let uangUntukBelanja = 0; let uangUntukUtangLama = 0; let tagihanCash = (totalKasar - depositDipakai); 
        if (metode === 'Utang') { 
            uangUntukBelanja = 0; uangUntukUtangLama = 0; 
        } else { 
            if(metode === 'Deposit') { 
                uangUntukBelanja = totalKasar; uangUntukUtangLama = utangLama; 
            } else { 
                if (bayar >= tagihanCash) { uangUntukBelanja = tagihanCash; let sisaUang = bayar - tagihanCash; if (utangLama > 0) { uangUntukUtangLama = Math.min(sisaUang, utangLama); } } else { uangUntukBelanja = bayar; uangUntukUtangLama = 0; } 
            } 
        } 
        
        let btnSimpan = document.querySelector('button[onclick="prosesBayar()"]'); let textAsli = btnSimpan.innerHTML; btnSimpan.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Proses...'; btnSimpan.disabled = true; 
        
        let kembalianCash = Math.max(0, bayar - ((totalKasar - depositDipakai) + utangLama));

        // Simpan snapshot pecahan sebelum form/keranjang di-reset.
        // Tanpa snapshot ini, pecahan yang dipilih operator bisa hilang lalu cash_push fallback
        // membentuk pecahan otomatis, misalnya Rp 2.000 jadi 1× Rp 2.000.
        const pecahanDiterimaCashSnapshot = (metode === 'Cash') ? clonePecahanKasir(getPecahanBayarUntukSyncKasir()) : {};
        const pecahanKembalianCashSnapshot = (metode === 'Cash') ? clonePecahanKasir(pecahanObjectKasir(kembalianCash)) : {};

        let data = { tanggal_transaksi: tanggalTransaksi, kode_pelanggan: pelanggan, uang_bayar: uangUntukBelanja, uang_pelunasan_utang: uangUntukUtangLama, uang_diterima_cash: (metode === 'Cash' ? bayar : 0), kembalian_cash: (metode === 'Cash' ? kembalianCash : 0), nominal_deposit: depositDipakai, nominal_deposit_baru: simpanKeDeposit, diskon_global: diskonGlobal, ongkir: ongkir, metode: metode, items: keranjang.map(i => ({ kode_barang: i.kode, jumlah: i.qty, harga: i.harga, diskon: (i.diskon_item || 0), file_wa: (i.file_wa || null), analyzer_meta: (i.analyzer_meta || null) })) }; 
        
        fetch('proses.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'data=' + encodeURIComponent(JSON.stringify(data)) })
        .then(async res => {
            let text = await res.text();
            try { 
                let jsonStart = text.indexOf('{');
                let jsonEnd = text.lastIndexOf('}');
                if (jsonStart !== -1 && jsonEnd !== -1) { return JSON.parse(text.substring(jsonStart, jsonEnd + 1)); }
                return JSON.parse(text); 
            } 
            catch(e) { console.error("Response Invalid:", text); throw new Error("Format error dari PHP."); }
        })
        .then(result => { 
            btnSimpan.innerHTML = textAsli; btnSimpan.disabled = false; 
            if(result.status === 'success') { 
                lastTrxData = { no: result.no_penjualan, utang_lama: uangUntukUtangLama, total_bayar: bayar, depo_awal: activePelanggan.deposit || 0, depo_pakai: depositDipakai || 0 }; 
                
                let noHpOtomatis = '';
                if(activePelanggan.telp && activePelanggan.telp !== '-') {
                    let cNum = String(activePelanggan.telp).replace(/[^0-9]/g, '');
                    if (cNum.startsWith('62') || cNum.startsWith('08')) { noHpOtomatis = cNum; }
                }
                
                let pelangganSuksesNama = (activePelanggan && activePelanggan.text) ? activePelanggan.text : ($('#pelanggan option:selected').text() || 'Pelanggan Umum');
                pelangganSuksesNama = String(pelangganSuksesNama).replace(/\s*\(.*?\)\s*$/g, '').trim() || 'Pelanggan Umum';

                // FIX: Grand Total pada modal sukses harus mengikuti angka preview nota/total sebelah,
                // bukan dihitung ulang manual. Ini mencegah selisih saat ada deposit, utang lama,
                // diskon global, ongkir, atau mode pembayaran khusus.
                const displayTotalEl = document.getElementById('displayTotal');
                let grandTotalSukses = parseFloat(displayTotalEl?.dataset?.grandTotal || 0);
                if (!Number.isFinite(grandTotalSukses)) {
                    grandTotalSukses = (totalKasar - depositDipakai) + utangLama;
                }
                if (grandTotalSukses < 0) grandTotalSukses = 0;

                localStorage.removeItem('pos_keranjang'); localStorage.removeItem('pos_pelanggan_data_v2'); 
                keranjang = []; document.getElementById('inputDiskonGlobal').value = ''; document.getElementById('inputOngkir').value = ''; document.getElementById('inputBayar').value = ''; pecahanBayarDipilihKasir = {}; renderPecahanBayarKasir();
                
                let totalTerpakai = uangUntukBelanja + uangUntukUtangLama; let kembali = bayar - totalTerpakai; if(kembali < 0) kembali = 0; 
                document.getElementById('lblKembali').innerText = "Kembali: " + formatRupiah(kembali); 
                if (document.getElementById('lblSuksesPelanggan')) document.getElementById('lblSuksesPelanggan').innerText = pelangganSuksesNama;
                if (document.getElementById('lblSuksesGrandTotal')) document.getElementById('lblSuksesGrandTotal').innerText = formatRupiah(grandTotalSukses);
                let params = new URLSearchParams({ id: result.no_penjualan, utang: uangUntukUtangLama || 0, depo_awal: lastTrxData.depo_awal, depo_pakai: lastTrxData.depo_pakai }); 
                document.getElementById('strukFrame').src = `cetak.php?${params.toString()}`; 
                document.getElementById('inputNoWA').value = noHpOtomatis; 
                
                var myModal = new bootstrap.Modal(document.getElementById('modalSukses')); myModal.show(); 
                pushCashTransaksiKeManajemen(result.no_penjualan, tanggalTransaksi, metode, bayar, kembalianCash, pecahanDiterimaCashSnapshot, pecahanKembalianCashSnapshot);
                pushNamaPelangganKeAndroid(pelangganSuksesNama, result.no_penjualan); 
                
                $('#pelanggan').empty().append(new Option("Pelanggan Umum (Cash)", "UMUM", true, true)).trigger('change'); 
                activePelanggan = { id: 'UMUM', text: 'Pelanggan Umum', deposit: 0, utang: 0, telp: '' };
                updateInfoPelangganUI(); renderCart(); 
            } else { alert('Gagal: ' + result.message); } 
        }).catch(err => { btnSimpan.innerHTML = textAsli; btnSimpan.disabled = false; alert("Terjadi kesalahan sistem: " + err.message); }); 
    }
    

    function pushCashTransaksiKeManajemen(noPenjualan, tanggal, metode, uangDiterima, kembalian, pecahanDiterima, pecahanKembalian) {
        if (metode !== 'Cash') return;
        uangDiterima = parseFloat(uangDiterima || 0);
        kembalian = parseFloat(kembalian || 0);
        if (!uangDiterima || uangDiterima <= 0) return;
        try {
            const fd = new FormData();
            fd.append('no_penjualan', noPenjualan || '');
            fd.append('tanggal', tanggal || '');
            fd.append('uang_diterima', uangDiterima);
            fd.append('kembalian', Math.max(0, kembalian));
            fd.append('pecahan_diterima', JSON.stringify(pecahanDiterima || {}));
            fd.append('pecahan_kembalian', JSON.stringify(pecahanKembalian || {}));
            fetch('../kas/cash_push.php', { method:'POST', body:fd, credentials:'same-origin' })
                .then(r => r.text())
                .then(text => {
                    let res = null;
                    try { res = JSON.parse(text); } catch(e) { console.warn('Cash sync response bukan JSON:', text); return; }
                    if (res && res.status === 'success' && res.saved) {
                        loadPecahanKasTersediaKasir(true).then(function(){ hitungKembali(); });
                    }
                    if (res && res.status === 'success' && res.saved && typeof Swal !== 'undefined') {
                        Swal.fire({ icon:'success', title:'Cash masuk tercatat', toast:true, position:'top-end', showConfirmButton:false, timer:1200 });
                    }
                })
                .catch(e => console.warn('Cash sync gagal:', e));
        } catch(e) { console.warn('Cash sync gagal:', e); }
    }

    function isPelangganUmumName(nama) {
        const n = String(nama || '').trim().toLowerCase();
        return !n || n === 'umum' || n.includes('pelanggan umum') || n.includes('cash');
    }

    function pushNamaPelangganKeAndroid(nama, noPenjualan) {
        nama = String(nama || '').trim();
        if (!nama || isPelangganUmumName(nama)) return;
        // Buat URL absolut dari root aplikasi agar tidak nyasar saat halaman ada di /transaksi/penjualan/.
        const appRoot = window.location.pathname.split('/transaksi/penjualan/')[0] || '';
        const url = appRoot + '/tools/android_clipboard_push.php';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nama_pelanggan: nama, no_penjualan: noPenjualan || '' })
        }).then(function(r){ return r.text(); })
          .then(function(text){
              let res = null;
              try { res = JSON.parse(text); } catch(e) { console.warn('Android clipboard response bukan JSON:', text); return; }
              if (res && res.status === 'success' && typeof Swal !== 'undefined') {
                  Swal.fire({ icon:'success', title:'Dikirim ke Android', text:nama, toast:true, position:'top-end', showConfirmButton:false, timer:1300 });
              } else {
                  console.warn('Android clipboard push gagal:', res);
              }
          }).catch(function(e){ console.warn('Android clipboard push gagal:', e); });
    }

    function kirimWA() { 
        let noHP = document.getElementById('inputNoWA').value; 
        if(!noHP) { Swal.fire({ icon: 'warning', title: 'Isi nomor WA!', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 }); return; } 
        let btn = document.getElementById('btnKirimWA'); let original = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled = true; 
        let payload = { no_penjualan: lastTrxData.no, nomor_hp: noHP, utang_lama_input: lastTrxData.utang_lama, total_bayar_input: lastTrxData.total_bayar, depo_awal: lastTrxData.depo_awal, depo_pakai: lastTrxData.depo_pakai }; 
        fetch('kirim_wa.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(res => res.json()).then(data => { btn.innerHTML = original; btn.disabled = false; if(data.status === 'success') { Swal.fire({ icon: 'success', title: 'Terkirim!', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 }); } else { Swal.fire('Gagal', data.message, 'error'); } }).catch(err => { btn.innerHTML = original; btn.disabled = false; });
    }

    function cetakLagi() { let btn = document.getElementById('btnCetakTMU'); if(btn) { btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mencetak...'; btn.disabled = true; } let params = new URLSearchParams({ id: lastTrxData.no, utang: lastTrxData.utang_lama || 0, depo_awal: lastTrxData.depo_awal || 0, depo_pakai: lastTrxData.depo_pakai || 0 }); fetch('cetak_direct.php?' + params.toString()).then(response => { if(btn) { btn.innerHTML = '<i class="fas fa-print fa-lg me-2"></i> CETAK STRUK (TM-U220)'; btn.disabled = false; } Swal.fire({ icon: 'success', title: 'Terkirim ke Printer', text: 'Silakan cek printer kasir Anda.', timer: 1500, showConfirmButton: false }); }).catch(err => { if(btn) { btn.innerHTML = '<i class="fas fa-print fa-lg me-2"></i> CETAK STRUK (TM-U220)'; btn.disabled = false; } Swal.fire('Error', 'Gagal menghubungi server', 'error'); }); }
    function keManajemenOrder() { if(lastTrxData && lastTrxData.no) { window.open('../order/index.php?id=' + lastTrxData.no, '_blank'); } else { window.open('../order/index.php', '_blank'); } }
    $('#btnTransaksiBaru').on('click', () => window.location.reload());

    function showMobileTab(tab) { if(window.innerWidth > 768) return; const sectionCatalog = document.getElementById('section-catalog'); const sectionCart = document.getElementById('section-cart'); const tabCatalog = document.getElementById('tabCatalog'); const tabCart = document.getElementById('tabCart'); if (tab === 'catalog') { sectionCatalog.classList.remove('hidden-mobile'); sectionCart.classList.remove('show-mobile'); tabCatalog.classList.add('active'); tabCart.classList.remove('active'); } else { sectionCatalog.classList.add('hidden-mobile'); sectionCart.classList.add('show-mobile'); tabCatalog.classList.remove('active'); tabCart.classList.add('active'); } }
    function setViewMode(mode) { currentViewMode = mode; localStorage.setItem('pos_view_mode', mode); const container = document.getElementById('productContainer'); const btnGrid = document.getElementById('btnViewGrid'); const btnList = document.getElementById('btnViewList'); container.classList.remove('view-grid', 'view-list'); btnGrid.classList.remove('active'); btnList.classList.remove('active'); if(mode === 'list') { container.classList.add('view-list'); btnList.classList.add('active'); } else { container.classList.add('view-grid'); btnGrid.classList.add('active'); } }

    function normalizeShortcutName(label) {
        let value = String(label || '').toUpperCase().trim();
        if (!value || value === 'NONE') return '';
        value = value.replace(/\s+/g, '').replace(/-/g, '+');
        value = value.replace(/\bDEL\b/g, 'DELETE');
        value = value.replace(/\bINS\b/g, 'INSERT');
        value = value.replace(/\bPGUP\b/g, 'PAGEUP');
        value = value.replace(/\bPGDN\b/g, 'PAGEDOWN');
        return value;
    }

    function getShortcutNameFromEvent(event) {
        let key = (event.key || '').toUpperCase();
        const code = (event.code || '').toUpperCase();
        const mapKey = {
            ' ': 'SPACE',
            'SPACEBAR': 'SPACE',
            'ESC': 'ESCAPE',
            'DEL': 'DELETE',
            'INS': 'INSERT',
            'PGUP': 'PAGEUP',
            'PGDN': 'PAGEDOWN',
            'ARROWUP': 'UP',
            'ARROWDOWN': 'DOWN',
            'ARROWLEFT': 'LEFT',
            'ARROWRIGHT': 'RIGHT'
        };
        key = mapKey[key] || key;

        // Fallback untuk beberapa keyboard/browser yang mengirim key aneh.
        if (!key || key === 'UNIDENTIFIED') {
            if (code === 'DELETE') key = 'DELETE';
            else if (code === 'INSERT') key = 'INSERT';
            else if (code === 'F10') key = 'F10';
            else key = code;
        }

        if (['CONTROL', 'CTRL', 'ALT', 'SHIFT', 'META'].includes(key)) return '';

        let parts = [];
        if (event.ctrlKey) parts.push('CTRL');
        if (event.altKey) parts.push('ALT');
        if (event.shiftKey) parts.push('SHIFT');
        parts.push(key);
        return normalizeShortcutName(parts.join('+'));
    }

    function focusAndSelect(el) {
        if (!el) return;
        el.focus();
        if (typeof el.select === 'function') setTimeout(() => el.select(), 30);
    }

    function jalankanAksiShortcut(aksi) {
        if(aksi == 'fokus_cari') { focusAndSelect(document.getElementById('keyword')); }
        else if(aksi == 'fokus_bayar') { focusAndSelect(document.getElementById('inputBayar')); }
        else if(aksi == 'simpan_transaksi') { const btn = document.getElementById('btn_simpan'); if (btn && !btn.disabled) btn.click(); }
        else if(aksi == 'batal_transaksi') { resetKeranjang(); }
        else if(aksi == 'pilih_pelanggan') { $('#pelanggan').select2('open'); }
        else if(aksi == 'fokus_kategori') { $('#filterKategori').select2('open'); }
        else if(aksi == 'cek_stok') { $('#filterKategori').select2('open'); }
        else if(aksi == 'pilih_metode_bayar') { const el = document.getElementById('metodePembayaran'); if(el) el.focus(); }
        else if(aksi == 'buka_file_whatsapp') { const el = document.getElementById('modalWaFiles'); if(el) bootstrap.Modal.getOrCreateInstance(el).show(); }
        else if(aksi == 'buka_print_analyzer') { if (typeof openPrintAnalyzerModal === 'function') openPrintAnalyzerModal(); }
        else if(aksi == 'cetak_struk') {
            if(lastTrxData && lastTrxData.no) { cetakLagi(); }
            else { Swal.fire({ icon:'info', title:'Belum ada transaksi', text:'Shortcut cetak struk aktif setelah transaksi tersimpan.', timer:1800, showConfirmButton:false }); }
        }
        else if(aksi == 'kirim_nota_wa') {
            if(lastTrxData && lastTrxData.no) { kirimWA(); }
            else { Swal.fire({ icon:'info', title:'Belum ada transaksi', text:'Shortcut kirim WA aktif setelah transaksi tersimpan.', timer:1800, showConfirmButton:false }); }
        }
        else if(aksi == 'buka_manajemen_order') { keManajemenOrder(); }
    }

    const normalizedHotkeys = {};
    Object.keys(hotkeys || {}).forEach(k => {
        const nk = normalizeShortcutName(k);
        if (nk && !normalizedHotkeys[nk]) normalizedHotkeys[nk] = hotkeys[k];
    });

    document.addEventListener('keydown', function(event) {
        const shortcutName = getShortcutNameFromEvent(event);
        const keyOnly = shortcutName.split('+').pop();
        const activeEl = document.activeElement;
        const isTyping = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT' || activeEl.classList.contains('select2-search__field'));

        // Shortcut barang hanya untuk tombol huruf/angka polos dan hanya saat tidak sedang mengetik.
        if (!isTyping && !event.ctrlKey && !event.altKey && !event.shiftKey && /^[0-9A-Z]$/.test(keyOnly)) {
            if (productShortcuts[keyOnly]) {
                event.preventDefault();
                event.stopPropagation();
                addToCart(productShortcuts[keyOnly]);
                const badge = document.getElementById('key-' + keyOnly);
                if (badge) {
                    let card = badge.closest('.product-card');
                    if(card) { card.classList.add('active-key'); setTimeout(() => card.classList.remove('active-key'), 150); }
                }
                return;
            }
        }

        const aksi = normalizedHotkeys[shortcutName] || normalizedHotkeys[keyOnly];
        if (aksi) {
            event.preventDefault();
            event.stopPropagation();
            jalankanAksiShortcut(aksi);
        }
    }, true);
</script>