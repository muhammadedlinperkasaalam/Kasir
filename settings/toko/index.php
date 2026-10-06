<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../../auth/login.php"); exit; }
require_once '../../config/database.php';

// --- 1. PROSES SIMPAN ---
$script_swal = "";
if (isset($_POST['simpan'])) {
    try {
        $nama    = $_POST['nama_toko'];
        $alamat  = $_POST['alamat_toko'];
        $telp    = $_POST['no_telepon'];
        $footer  = $_POST['footer_struk'];
        $ig      = $_POST['link_ig'];
        $fb      = $_POST['link_fb'];
        $web     = $_POST['link_website'];
        $port    = $_POST['printer_port'];
        $width   = (int)$_POST['content_width'];
        $feed    = (int)$_POST['feed_lines'];
        
        $margin_left  = (int)$_POST['margin_left'];
        $margin_right = (int)$_POST['margin_right'];
        
        // Simpan margin sebagai string spasi
        $margin_str = (string)$margin_left; // simpan sebagai angka spasi supaya konsisten dengan file cetak

        $sql = "UPDATE settings_toko SET 
                nama_toko = ?, alamat_toko = ?, no_telepon = ?, footer_struk = ?, 
                link_ig = ?, link_fb = ?, link_website = ?,
                printer_port = ?, content_width = ?, margin_left = ?, margin_right = ?, feed_lines = ?
                WHERE id = 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nama, $alamat, $telp, $footer, $ig, $fb, $web, $port, $width, $margin_left, $margin_right, $feed]);
        $script_swal = "Swal.fire({ title: 'Berhasil!', text: 'Pengaturan & Kalibrasi Disimpan.', icon: 'success', timer: 1500, showConfirmButton: false });";
    } catch (PDOException $e) {
        $msg = addslashes($e->getMessage());
        $script_swal = "Swal.fire('Error', '$msg', 'error');";
    }
}

// --- 2. AMBIL DATA ---
$stmt = $pdo->query("SELECT * FROM settings_toko WHERE id = 1");
$toko = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$toko) {
    // DEFAULT: Margin Kiri 3 Spasi (Sesuai Request)
    $toko = ['nama_toko' => '', 'alamat_toko' => '', 'no_telepon' => '', 'footer_struk' => '', 'link_ig' => '', 'link_fb' => '', 'link_website' => '', 'printer_port' => 'LPT1', 'content_width' => 33, 'margin_left' => '   ', 'margin_right' => 0, 'feed_lines' => 3];
}
// Hitung margin kiri. Data lama bisa berupa spasi, data baru berupa angka.
function toko_margin_left_count($value, $default = 3) {
    if ($value === null || $value === '') return $default;
    $value = (string)$value;
    return preg_match('/^\d+$/', trim($value)) ? (int)trim($value) : strlen($value);
}
$margin_count_left  = toko_margin_left_count($toko['margin_left'] ?? '3');
$margin_count_right = (int)($toko['margin_right'] ?? 0);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pengaturan Toko & Printer | Addinta Printing</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap');
        
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; color: #1e293b; }
        
        /* Sidebar */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Cards */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); background: white; overflow: hidden; }
        .card-header-custom { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; font-weight: 700; letter-spacing: 0.5px; }
        
        /* Custom Inputs */
        .form-label { font-size: 0.8rem; font-weight: 600; color: #475569; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { border-radius: 8px; border: 1px solid #cbd5e1; padding: 0.5rem 0.75rem; font-size: 0.9rem; transition: all 0.2s; }
        .form-control:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1); }
        .form-text { font-size: 0.75rem; color: #94a3b8; }

        /* Receipt Preview Styling */
        .preview-container { background-color: #1e293b; border-radius: 16px; padding: 30px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; min-height: 500px; box-shadow: inset 0 4px 20px rgba(0,0,0,0.2); }
        .kertas-struk {
            background: #fdfbf7; /* Warna kertas thermal asli */
            padding: 20px 0; 
            border-radius: 2px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            font-family: 'JetBrains Mono', 'Courier New', monospace; 
            font-size: 13px; 
            color: #0f172a; 
            white-space: pre; 
            overflow-x: hidden; 
            min-height: 400px;
            border-bottom: 3px dashed #cbd5e1;
            line-height: 1.3;
            cursor: default;
            transition: width 0.3s ease;
        }
        
        .preview-center { text-align: center; width: 100%; display: block; }
        .preview-left { text-align: left; width: 100%; display: block; }
        
        /* Buttons */
        .btn-custom { border-radius: 10px; font-weight: 600; padding: 10px 20px; letter-spacing: 0.5px; transition: transform 0.2s; }
        .btn-custom:active { transform: scale(0.98); }
    </style>
</head>
<body>

    <?php include '../../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fas fa-store text-primary me-2"></i> Identitas Toko & Printer</h4>
                <p class="text-muted small mb-0 mt-1">Atur profil toko dan kalibrasi tata letak struk kasir.</p>
            </div>
        </div>

        <form method="POST" id="formSettings">
            <div class="row g-4">
                
                <div class="col-lg-5">
                    <div class="card-custom mb-4">
                        <div class="card-header-custom bg-white d-flex align-items-center text-primary">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px;">
                                <i class="fas fa-building"></i>
                            </div>
                            Informasi Struk
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="form-label">Nama Toko</label>
                                <input type="text" id="inpNama" name="nama_toko" class="form-control" required value="<?= htmlspecialchars($toko['nama_toko']) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Alamat Toko</label>
                                <textarea id="inpAlamat" name="alamat_toko" class="form-control" rows="2" required><?= htmlspecialchars($toko['alamat_toko']) ?></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Pesan Bawah (Footer)</label>
                                <input type="text" id="inpFooter" name="footer_struk" class="form-control" value="<?= htmlspecialchars($toko['footer_struk']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="card-header-custom bg-white d-flex align-items-center text-success">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px;">
                                <i class="fas fa-print"></i>
                            </div>
                            Kalibrasi Thermal Printer
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-4">
                                <label class="form-label text-success">Printer Port (Shared Name / IP)</label>
                                <div class="input-group">
                                    <input type="text" id="inpPort" name="printer_port" list="printerList" class="form-control fw-bold" style="border-color: #86efac; background-color: #f0fdf4;" value="<?= htmlspecialchars($toko['printer_port'] ?? 'LPT1') ?>">
                                    <button class="btn btn-outline-success" type="button" onclick="loadPrinters()" title="Deteksi printer Windows">
                                        <i class="fas fa-search me-1"></i> Deteksi
                                    </button>
                                </div>
                                <datalist id="printerList"></datalist>
                                <div class="form-text">Contoh: <b>LPT1</b>, <b>COM1</b>, <b>192.168.1.100</b>, <b>192.168.1.100:9100</b>, <b>THERMAL</b>, atau <b>\\KOMPUTER\SHARE_PRINTER</b>.</div>
                                <div id="printerDetectInfo" class="small mt-2 text-muted"></div>
                            </div>
                            
                            <div class="row g-3 mb-4">
                                <div class="col-4">
                                    <label class="form-label">Lebar (Char)</label>
                                    <input type="number" id="inpWidth" name="content_width" class="form-control fw-bold text-center" value="<?= htmlspecialchars($toko['content_width'] ?? 33) ?>">
                                    <div class="form-text text-center">Biasa 33 / 40</div>
                                </div>
                                <div class="col-4">
                                    <label class="form-label text-danger">Margin Kiri</label>
                                    <input type="number" id="inpMarginL" name="margin_left" class="form-control fw-bold text-center border-danger" value="<?= $margin_count_left ?>">
                                    <div class="form-text text-center text-danger">Geser Body</div>
                                </div>
                                <div class="col-4">
                                    <label class="form-label">Margin Kanan</label>
                                    <input type="number" id="inpMarginR" name="margin_right" class="form-control fw-bold text-center" value="<?= $margin_count_right ?>">
                                    <div class="form-text text-center">Batas Aman</div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Feed Lines (Jarak Kertas Sebelum Dipotong)</label>
                                <input type="number" id="inpFeed" name="feed_lines" class="form-control w-50" value="<?= htmlspecialchars($toko['feed_lines'] ?? 3) ?>">
                            </div>

                            <hr class="text-muted opacity-25">

                            <div class="d-grid gap-2 mt-4">
                                <button type="button" onclick="testPrint()" class="btn btn-warning btn-custom shadow-sm text-dark">
                                    <i class="fas fa-receipt me-2"></i> Test Print Hardware
                                </button>
                                <button type="submit" name="simpan" class="btn btn-primary btn-custom shadow-sm">
                                    <i class="fas fa-save me-2"></i> Simpan Konfigurasi
                                </button>
                            </div>

                            <input type="hidden" name="no_telepon" value="<?= htmlspecialchars($toko['no_telepon']) ?>">
                            <input type="hidden" name="link_ig" value="<?= htmlspecialchars($toko['link_ig']) ?>">
                            <input type="hidden" name="link_fb" value="<?= htmlspecialchars($toko['link_fb']) ?>">
                            <input type="hidden" name="link_website" value="<?= htmlspecialchars($toko['link_website']) ?>">
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="preview-container">
                        <div class="w-100 d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-white mb-0 fw-bold"><i class="fas fa-eye me-2 text-info"></i> Live Preview</h6>
                            <span class="badge bg-dark border border-secondary text-light">Visualisasi Kertas</span>
                        </div>
                        
                        <div id="paper" class="kertas-struk mx-auto"></div>
                        
                        <div class="alert alert-dark bg-dark bg-opacity-50 border-0 text-light mt-4 mb-0 small text-center rounded-3 w-100">
                            <i class="fas fa-info-circle text-info me-1"></i>
                            Header otomatis di tengah <strong>(Center)</strong>. Daftar item mengikuti setelan <strong>Margin Kiri</strong>.
                        </div>
                    </div>
                </div>

            </div>
        </form>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if(!empty($script_swal)): ?>
    <script> document.addEventListener('DOMContentLoaded', function() { <?= $script_swal ?> }); </script>
    <?php endif; ?>

    <script>
        // Logika Javascript Live Preview Tetap Sama
        const elNama    = document.getElementById('inpNama');
        const elAlamat  = document.getElementById('inpAlamat');
        const elFooter  = document.getElementById('inpFooter');
        const elWidth   = document.getElementById('inpWidth');
        const elMarginL = document.getElementById('inpMarginL');
        const elMarginR = document.getElementById('inpMarginR');
        const paper     = document.getElementById('paper');

        // --- HELPERS ---
        function padRight(str, len) { return str + " ".repeat(Math.max(0, len - str.length)); }
        function padLeft(str, len) { return " ".repeat(Math.max(0, len - str.length)) + str; }
        
        function getLines(text, maxWidth) {
            text = String(text || '').trim();
            maxWidth = parseInt(maxWidth) || 33;
            if (text === '') return [''];
            let words = text.split(/\s+/);
            let lines = [];
            let currentLine = words[0];
            for (let i = 1; i < words.length; i++) {
                if (currentLine.length + 1 + words[i].length <= maxWidth) {
                    currentLine += " " + words[i];
                } else {
                    lines.push(currentLine);
                    currentLine = words[i];
                }
            }
            lines.push(currentLine);
            return lines;
        }

        // --- RENDER UTAMA ---
        function render() {
            let w  = parseInt(elWidth.value) || 33;
            let mL = parseInt(elMarginL.value) || 0;
            let mR = parseInt(elMarginR.value) || 0;
            
            let contentW = w - mL - mR;
            let marginStr = " ".repeat(mL); // Spasi Indentasi Kiri Body
            
            let lineEq = "=".repeat(w); 
            let lineDash = "-".repeat(contentW); 

            let nama = elNama.value.toUpperCase();
            let alamat = elAlamat.value;
            let footer = elFooter.value;

            let html = "";

            // --- 1. HEADER (CENTER ABSOLUTE) ---
            let namaLines = getLines(nama, w);
            namaLines.forEach(line => {
                html += `<div class="preview-center" style="color:#0f172a; font-weight:800; font-size:16px; text-transform:uppercase;">${line}</div>`;
            });
            
            let manualLines = alamat.split('\n');
            manualLines.forEach(block => {
                let wrappedLines = getLines(block, w);
                wrappedLines.forEach(line => {
                    if(line.trim() !== '') html += `<div class="preview-center">${line}</div>`;
                });
            });

            html += `<div class="preview-center">${lineEq}</div>`;

            // --- 2. BODY (LEFT + MARGIN) ---
            html += `<div class="preview-left">`;

            html += `<span style="color:#334155; font-weight:bold;">${marginStr} No     : 20231001</span>\n`;
            html += ` ${marginStr}Tgl    : 07/02/26 13:37\n`;
            html += ` ${marginStr}Kasir  : Admin\n`;
            html += ` ${marginStr}Metode : CASH\n`;
            html += ` ${marginStr}${lineDash}\n`;

            // --- 3. TABLE HEADER ---
            html += ` <b>${marginStr}ITEM             QTY       TOTAL</b>\n`;
            html += ` ${marginStr}${lineDash}\n`;

            // --- 4. ITEMS ---
            let w_qty = 5; let w_tot = 11;
            let w_nama = contentW - w_qty - w_tot - 1;
            if(w_nama < 5) w_nama = 5; 

            let i1_n = padRight("  a4 70", w_nama);
            let i1_q = padLeft("1x", w_qty);
            let i1_t = padLeft("150", w_tot);
            html += `${marginStr}${i1_n}${i1_q}${i1_t}\n`;

            let i2_n = padRight("  a3 80", w_nama);
            let i2_q = padLeft("3x", w_qty);
            let i2_t = padLeft("900", w_tot);
            html += `${marginStr}${i2_n}${i2_q}${i2_t}\n`;

            html += ` ${marginStr}${lineDash}\n`;

            // --- 5. FOOTER ANGKA ---
            function row(label, val) {
                let space = contentW - label.length - val.length;
                if(space < 0) space = 1;
                return `${marginStr}${label}${" ".repeat(space)}${val}\n`;
            }

            html += row("  Total Item :", "4");
            html += row("  Subtotal   :", "1.050");
            html += `\n`; 
            html += `<b>${row("  GRAND TOTAL:", "1.050")}</b>\n`;
            html += row("  Bayar      :", "1.050");
            html += row("  KEMBALI    :", "0");
            
            html += `</div>`; // Tutup Div Body

            // --- 6. FOOTER BAWAH (CENTER) ---
            html += `<div class="preview-center">${lineEq}</div>`;
            
            let footLines = getLines(footer, w);
            footLines.forEach(line => {
                html += `<div class="preview-center">${line}</div>`;
            });
            
            html += `<div class="preview-center">-- Terima Kasih --</div>`;

            paper.innerHTML = html;
            // Lebar kertas dinamis menyesuaikan pengaturan Char
            paper.style.width = (w * 8.5 + 40) + "px"; 
        }

        [elNama, elAlamat, elFooter, elWidth, elMarginL, elMarginR].forEach(el => el.addEventListener('input', render));
        render(); // Panggil saat pertama kali load

        // --- FUNGSI DETEKSI PRINTER WINDOWS ---
        function loadPrinters() {
            const info = document.getElementById('printerDetectInfo');
            const list = document.getElementById('printerList');
            info.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Membaca daftar printer Windows...';
            fetch('list_printers_ajax.php')
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') {
                    info.innerHTML = '<span class="text-danger">Gagal deteksi: ' + (data.message || 'Unknown error') + '</span>';
                    return;
                }
                list.innerHTML = '';
                let html = '';
                (data.printers || []).forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.value || p.name;
                    opt.label = (p.default ? '[DEFAULT] ' : '') + p.name + (p.share_name ? ' | Share: ' + p.share_name : ' | Belum dishare') + (p.port_name ? ' | Port: ' + p.port_name : '');
                    list.appendChild(opt);
                    html += '<div class="border rounded p-2 mb-1 bg-light">'
                        + '<b>' + escapeHtml(p.name || '') + '</b>' + (p.default ? ' <span class="badge bg-primary">Default</span>' : '')
                        + (p.offline ? ' <span class="badge bg-danger">Offline</span>' : '')
                        + '<br><span>Share: ' + escapeHtml(p.share_name || '-') + '</span>'
                        + '<br><span>Port: ' + escapeHtml(p.port_name || '-') + '</span>'
                        + '<br><button type="button" class="btn btn-sm btn-success mt-1" onclick="setPrinterPort(\'' + escapeJs(p.value || p.name || '') + '\')">Pakai ini</button>'
                        + (!p.share_name ? ' <span class="text-warning small">Printer USB lokal sebaiknya dishare dulu agar raw print stabil.</span>' : '')
                        + '</div>';
                });
                info.innerHTML = html || '<span class="text-warning">Tidak ada printer terdeteksi.</span>';
            })
            .catch(err => {
                info.innerHTML = '<span class="text-danger">Gagal koneksi ke list_printers_ajax.php</span>';
            });
        }
        function setPrinterPort(value) {
            document.getElementById('inpPort').value = value;
        }
        function escapeHtml(str) {
            return String(str).replace(/[&<>'"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[s]));
        }
        function escapeJs(str) {
            return String(str)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/"/g, '\\"');
        }

        // --- FUNGSI AJAX TEST PRINT ---
        function testPrint() {
            let btn = document.querySelector('button[onclick="testPrint()"]');
            let originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Mengirim Data...';
            btn.disabled = true;

            let formData = new FormData();
            formData.append('port', document.getElementById('inpPort').value);
            formData.append('width', document.getElementById('inpWidth').value);
            formData.append('margin', document.getElementById('inpMarginL').value);
            formData.append('margin_right', document.getElementById('inpMarginR').value);
            formData.append('feed', document.getElementById('inpFeed').value);
            formData.append('nama', document.getElementById('inpNama').value);
            formData.append('alamat', document.getElementById('inpAlamat').value);

            fetch('test_print_ajax.php', { method: 'POST', body: formData })
            .then(async res => {
                const text = await res.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error(text ? text.substring(0, 500) : 'Response kosong dari server'); }
            })
            .then(data => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                if(data.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Terkirim ke Printer!', text: 'Target: ' + (data.target || document.getElementById('inpPort').value) + ' | Metode: ' + (data.method || '-'), timer: 3000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Gagal Print', html: '<div class="text-start small">' + escapeHtml(data.message || 'Tidak diketahui') + '</div>' });
                }
            })
            .catch(err => {
                btn.innerHTML = originalText;
                btn.disabled = false;
                Swal.fire({ icon: 'error', title: 'Error Test Print', html: '<div class="text-start small"><b>Detail:</b><br>' + escapeHtml(err.message || err) + '</div>' });
            });
        }
    </script>

</body>
</html>
