<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';

// ============================================================
// AUTO-MIGRATE: Tambah kolom template_deposit jika belum ada
// ============================================================
try {
    $pdo->query("SELECT template_deposit FROM setting_wa LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE setting_wa ADD COLUMN template_deposit TEXT NULL");
}

// ============================================================
// AUTO-MIGRATE: Toggle kirim pesan kedua QRIS untuk nota belum lunas
// ============================================================
try {
    $pdo->query("SELECT send_qris_unpaid FROM setting_wa LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE setting_wa ADD COLUMN send_qris_unpaid TINYINT(1) NOT NULL DEFAULT 1");
}

$pesan = '';

// ============================================================
// PROSES SIMPAN DATA
// ============================================================
if (isset($_POST['simpan_template'])) {
    $wa_lunas = $_POST['template_header']; // Template Nota Lunas
    $wa_utang = $_POST['template_footer']; // Template Nota Ada Sisa Utang
    $wa_depo  = $_POST['template_deposit']; // Template Info Deposit
    $qris_cap = $_POST['qris_caption'];    // Caption untuk QRIS
    $send_qris_unpaid = isset($_POST['send_qris_unpaid']) ? 1 : 0; // Kirim pesan kedua QRIS untuk nota belum lunas

    // --- LOGIKA UPLOAD GAMBAR QRIS ---
    $old_data = $pdo->query("SELECT qris_image FROM setting_wa WHERE id = 1")->fetch();
    $qris_img = $old_data['qris_image'] ?? '';

    if (!empty($_FILES['qris_file']['name'])) {
        $target_dir = "../assets/img/"; 
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        
        $file_ext = strtolower(pathinfo($_FILES['qris_file']['name'], PATHINFO_EXTENSION));
        $new_name = "qris_payment." . $file_ext; 
        $target_file = $target_dir . $new_name;

        $allowed = ['jpg', 'jpeg', 'png'];
        if (in_array($file_ext, $allowed)) {
            if (move_uploaded_file($_FILES['qris_file']['tmp_name'], $target_file)) {
                $qris_img = $new_name; 
            } else {
                echo "<script>alert('Gagal upload gambar.');</script>";
            }
        } else {
            echo "<script>alert('Format gambar harus JPG/PNG.');</script>";
        }
    }
    // ----------------------------------

    $cek = $pdo->query("SELECT count(*) FROM setting_wa WHERE id = 1")->fetchColumn();

    if ($cek > 0) {
        $stmt = $pdo->prepare("UPDATE setting_wa SET template_header = ?, template_footer = ?, template_deposit = ?, qris_image = ?, qris_caption = ?, send_qris_unpaid = ? WHERE id = 1");
    } else {
        $stmt = $pdo->prepare("INSERT INTO setting_wa (id, template_header, template_footer, template_deposit, qris_image, qris_caption, send_qris_unpaid) VALUES (1, ?, ?, ?, ?, ?, ?)");
    }

    if ($stmt->execute([$wa_lunas, $wa_utang, $wa_depo, $qris_img, $qris_cap, $send_qris_unpaid])) {
        echo "<script>alert('Setting Berhasil Disimpan!'); window.location='whatsapp.php';</script>";
    } else {
        echo "<script>alert('Gagal menyimpan.');</script>";
    }
}

// --- AMBIL DATA ---
$data = $pdo->query("SELECT * FROM setting_wa WHERE id = 1")->fetch();

$default_lunas = "*STRUK PEMBAYARAN*\nNo: {no_nota}\nPlg: {nama_pelanggan}\n\n{list_barang}\nOngkir: {ongkir}\n----------------\n{total}\n----------------\nBayar: {bayar}\nKembali: {kembali}\nAntrian saat ini: {antrian}\n\n*LUNAS* ✅";
$default_utang = "*TAGIHAN UTANG*\nHalo {nama_pelanggan},\nBerikut rincian belanja:\n\n{list_barang}\nOngkir: {ongkir}\n----------------\n{total}\n----------------\nBayar: {bayar}\n*SISA UTANG: {sisa}* 🔴";
$default_depo  = "*INFO DEPOSIT*\nSaldo Awal: {depo_awal}\nAkan Dipotong: -{depo_pakai}\nSisa Saldo: {sisa_depo}";
$default_qris_cap = "Silakan scan QRIS di atas untuk pembayaran.\nKonfirmasi jika sudah transfer. Terima kasih 🙏";

$tpl_lunas = !empty($data['template_header']) ? $data['template_header'] : $default_lunas;
$tpl_utang = !empty($data['template_footer']) ? $data['template_footer'] : $default_utang;
$tpl_depo  = !empty($data['template_deposit']) ? $data['template_deposit'] : $default_depo;
$tpl_qris_cap = !empty($data['qris_caption']) ? $data['qris_caption'] : $default_qris_cap;
$img_qris = !empty($data['qris_image']) ? "../assets/img/" . $data['qris_image'] : '';
$send_qris_unpaid = isset($data['send_qris_unpaid']) ? (int)$data['send_qris_unpaid'] : 1;

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setting WhatsApp | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Base Styling */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; color: #1e293b; }
        
        /* Sidebar */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link-sidebar { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; text-decoration: none; padding: 10px 15px; display: block; }
        .nav-link-sidebar.active { background-color: #f0f4ff; color: #0d6efd; }
        .nav-link-sidebar:hover { background-color: #f8f9fa; color: #0d6efd; }

        /* Main Content */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Cards */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); background: white; overflow: hidden; }
        .card-header-custom { padding: 0; border-bottom: 1px solid #f1f5f9; background: white; }
        
        /* Nav Pills Custom (Tabs) */
        .nav-pills-custom .nav-link { color: #64748b; font-weight: 600; border-radius: 0; padding: 15px 20px; transition: all 0.2s; border-bottom: 3px solid transparent; }
        .nav-pills-custom .nav-link:hover { color: #10b981; background-color: #f8fafc; }
        .nav-pills-custom .nav-link.active { color: #10b981; background-color: transparent; border-bottom: 3px solid #10b981; }

        /* Form Elements */
        .form-label { font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.4rem; }
        .form-control { border-radius: 10px; border: 1px solid #cbd5e1; padding: 0.6rem 0.8rem; font-size: 0.9rem; transition: all 0.2s; }
        .form-control:focus { border-color: #10b981; box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.15); }

        /* Variable Badges (Chips) */
        .var-badge { cursor: pointer; font-family: 'Inter', monospace; font-size: 0.8rem; padding: 6px 12px; border-radius: 20px; transition: all 0.2s; user-select: none; font-weight: 500; border: 1px solid #e2e8f0; background: white; color: #475569; display: inline-block; }
        .var-badge:hover { background-color: #10b981; color: white; border-color: #10b981; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(16,185,129,0.2); }
        .var-badge.highlight { background-color: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
        .var-badge.highlight:hover { background-color: #16a34a; color: white; }

        /* WhatsApp Simulation Container */
        .phone-mockup { background: #fff; border-radius: 30px; border: 10px solid #1e293b; box-shadow: 0 20px 40px rgba(0,0,0,0.1); height: 700px; position: relative; overflow: hidden; display: flex; flex-direction: column; }
        .phone-header { background: #075e54; color: white; padding: 15px; display: flex; align-items: center; gap: 12px; z-index: 2; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .wa-container { background-color: #efeae2; flex-grow: 1; padding: 20px; position: relative; background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-size: cover; overflow-y: auto; }
        
        /* WhatsApp Bubbles */
        .wa-bubble { background-color: #dcf8c6; padding: 8px 12px; border-radius: 12px 0 12px 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.15); color: #111b21; font-size: 0.9rem; line-height: 1.4; white-space: pre-wrap; position: relative; max-width: 90%; float: right; margin-bottom: 8px; clear: both; }
        .wa-bubble::after { content: ""; position: absolute; top: 0; right: -8px; width: 0; height: 0; border: 10px solid transparent; border-top-color: #dcf8c6; border-left: 0; margin-top: 0; margin-right: 0; }
        .wa-bubble-img { padding: 2px; }
        .wa-bubble-img img { width: 100%; border-radius: 8px; margin-bottom: 5px; }
        .wa-time { font-size: 0.65rem; color: #667781; text-align: right; margin-top: 4px; float: right; }
        
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>

    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 no-print gap-3">
            <div>
                <h4 class="fw-bold mb-0 text-dark"><i class="fab fa-whatsapp text-success me-2"></i> Pengaturan WhatsApp</h4>
                <p class="text-muted small mb-0 mt-1">Sesuaikan format pesan otomatis yang dikirim ke pelanggan.</p>
            </div>
        </div>

        <div class="row g-4">
            
            <div class="col-lg-7 mb-4">
                <div class="card-custom h-100 d-flex flex-column">
                    
                    <div class="card-header-custom">
                        <ul class="nav nav-pills-custom d-flex m-0 list-unstyled" id="myTab" role="tablist">
                            <li class="nav-item flex-fill text-center">
                                <button class="nav-link w-100 active" id="lunas-tab" data-bs-toggle="tab" data-bs-target="#lunas-panel" onclick="switchPreview('lunas')">
                                    <i class="fas fa-check-circle me-2"></i> Transaksi Lunas
                                </button>
                            </li>
                            <li class="nav-item flex-fill text-center">
                                <button class="nav-link w-100" id="utang-tab" data-bs-toggle="tab" data-bs-target="#utang-panel" onclick="switchPreview('utang')">
                                    <i class="fas fa-clock me-2"></i> Belum Lunas (Utang)
                                </button>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="card-body p-4 flex-grow-1">
                        <form method="POST" enctype="multipart/form-data" class="h-100 d-flex flex-column">
                            
                            <div class="bg-light p-3 rounded-4 mb-4 border border-light">
                                <div class="fw-bold text-dark small mb-2"><i class="fas fa-magic text-warning me-1"></i> Klik variabel untuk menyisipkan:</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="var-badge" onclick="insertText('{nama_pelanggan}')">{nama_pelanggan}</span>
                                    <span class="var-badge" onclick="insertText('{no_nota}')">{no_nota}</span>
                                    <span class="var-badge" onclick="insertText('{tanggal}')">{tanggal}</span>
                                    <span class="var-badge" onclick="insertText('{metode}')">{metode}</span>
                                    <span class="var-badge" onclick="insertText('{list_barang}')" title="Item nota ringkas; file analyzer otomatis dikelompokkan per file">{list_barang}</span>
                                    <span class="var-badge highlight" onclick="insertText('{ongkir}')" title="Biaya Pengiriman">{ongkir}</span>
                                    <span class="var-badge fw-bold border-primary text-primary" style="background:#eff6ff;" onclick="insertText('{total}')" title="Rincian Total">{total}</span>
                                    <span class="var-badge" onclick="insertText('{bayar}')">{bayar}</span>
                                    <span class="var-badge" onclick="insertText('{antrian}')">{antrian}</span>
                                    
                                    <span class="var-badge var-lunas border-success text-success" style="background:#f0fdf4;" onclick="insertText('{kembali}')">{kembali}</span>
                                    <span class="var-badge var-utang d-none border-danger text-danger fw-bold" style="background:#fef2f2;" onclick="insertText('{sisa}')">{sisa}</span>
                                </div>
                            </div>

                            <div class="alert alert-info border-0 rounded-4 py-2 px-3 mb-3" style="font-size:0.82rem;">
                                <i class="fas fa-list-ul me-1"></i>
                                Variabel <code>{list_barang}</code> otomatis memakai format ringkas. Jika item berasal dari Print Analyzer, item akan dikelompokkan per nama file.
                            </div>

                            <div class="tab-content flex-grow-1" id="myTabContent">
                                
                                <div class="tab-pane fade show active h-100" id="lunas-panel">
                                    <div class="mb-4">
                                        <label class="form-label text-success"><i class="fas fa-comment-dots me-1"></i> Pesan Utama (Lunas)</label>
                                        <textarea name="template_header" id="inputLunas" class="form-control font-monospace" rows="10" oninput="updatePreview('lunas')"><?= htmlspecialchars($tpl_lunas) ?></textarea>
                                    </div>
                                    <div class="p-3 bg-light border rounded-4">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label text-info m-0"><i class="fas fa-wallet me-1"></i> Tambahan Info Deposit</label>
                                            <span class="badge bg-info bg-opacity-10 text-info border border-info" style="font-size:0.65rem;">Opsional</span>
                                        </div>
                                        <p class="text-muted mb-2" style="font-size:0.75rem;">Disisipkan otomatis di bawah struk jika pelanggan membayar menggunakan saldo deposit. (Var: <code>{depo_awal}</code>, <code>{depo_pakai}</code>, <code>{sisa_depo}</code>)</p>
                                        <textarea name="template_deposit" id="inputDepo" class="form-control font-monospace" rows="3" oninput="updatePreview('lunas')"><?= htmlspecialchars($tpl_depo) ?></textarea>
                                    </div>
                                </div>
                                
                                <div class="tab-pane fade h-100" id="utang-panel">
                                    <div class="mb-4">
                                        <label class="form-label text-danger"><i class="fas fa-comment-dots me-1"></i> Pesan Tagihan (Belum Lunas)</label>
                                        <textarea name="template_footer" id="inputUtang" class="form-control font-monospace" rows="8" oninput="updatePreview('utang')"><?= htmlspecialchars($tpl_utang) ?></textarea>
                                    </div>
                                    
                                    <div class="p-3 bg-light border rounded-4">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                            <div>
                                                <label class="form-label text-primary mb-1"><i class="fas fa-qrcode me-1"></i> Pesan Kedua (Info QRIS / Rekening)</label>
                                                <div class="text-muted" style="font-size:0.75rem;">Khusus nota yang belum lunas. Matikan opsi ini jika tidak ingin mengirim info/gambar QRIS.</div>
                                            </div>
                                            <div class="form-check form-switch m-0">
                                                <input class="form-check-input" type="checkbox" role="switch" id="sendQrisUnpaid" name="send_qris_unpaid" value="1" <?= $send_qris_unpaid ? 'checked' : '' ?> onchange="updatePreview('utang')">
                                                <label class="form-check-label fw-semibold small" for="sendQrisUnpaid">Kirim pesan QRIS</label>
                                            </div>
                                        </div>
                                        <div class="row g-3" id="qrisSettingFields">
                                            <div class="col-md-5">
                                                <label class="text-muted" style="font-size:0.75rem; font-weight:600;">Upload QRIS (JPG/PNG)</label>
                                                <?php if($img_qris): ?>
                                                    <div class="mb-2 mt-1">
                                                        <img src="<?= $img_qris ?>" id="imgPreview" class="img-thumbnail rounded-3 shadow-sm" style="max-height: 90px; object-fit: contain;">
                                                    </div>
                                                <?php endif; ?>
                                                <input type="file" name="qris_file" id="qrisInput" class="form-control form-control-sm" accept="image/*" onchange="previewImage(this)">
                                            </div>
                                            <div class="col-md-7">
                                                <label class="text-muted" style="font-size:0.75rem; font-weight:600;">Caption Gambar</label>
                                                <div class="d-flex flex-wrap gap-1 mt-1 mb-2">
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{nama_pelanggan}', 'inputQris')">{nama_pelanggan}</span>
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{no_nota}', 'inputQris')">{no_nota}</span>
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{tanggal}', 'inputQris')">{tanggal}</span>
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{metode}', 'inputQris')">{metode}</span>
                                                    <span class="var-badge fw-bold border-primary text-primary" style="font-size:0.7rem; padding:4px 10px; background:#eff6ff;" onclick="insertText('{total}', 'inputQris')">{total}</span>
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{bayar}', 'inputQris')">{bayar}</span>
                                                    <span class="var-badge border-danger text-danger fw-bold" style="font-size:0.7rem; padding:4px 10px; background:#fef2f2;" onclick="insertText('{sisa}', 'inputQris')">{sisa}</span>
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{ongkir}', 'inputQris')">{ongkir}</span>
                                                    <span class="var-badge" style="font-size:0.7rem; padding:4px 10px;" onclick="insertText('{antrian}', 'inputQris')">{antrian}</span>
                                                </div>
                                                <textarea name="qris_caption" id="inputQris" class="form-control font-monospace" rows="4" placeholder="Ketik caption untuk gambar QRIS..." oninput="updatePreview('utang')"><?= htmlspecialchars($tpl_qris_cap) ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="mt-4 pt-3 border-top">
                                <button type="submit" name="simpan_template" class="btn btn-success w-100 fw-bold py-2 rounded-pill shadow-sm" style="letter-spacing: 0.5px;">
                                    <i class="fas fa-save me-2"></i> SIMPAN SEMUA SETTING
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="sticky-top" style="top: 20px;">
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark m-0"><i class="fas fa-mobile-alt text-primary me-2"></i> Simulasi Layar</h6>
                        
                        <div class="d-flex gap-2">
                            <div class="form-check form-switch bg-white px-3 py-1 rounded-pill border shadow-sm" id="switchUtangBox" style="display:none;">
                                <input class="form-check-input" type="checkbox" id="toggleUtangLama" onchange="updatePreview(currentMode)" style="cursor:pointer;">
                                <label class="form-check-label small fw-medium" for="toggleUtangLama" style="cursor:pointer; margin-left:5px;">Ada Utang Lama</label>
                            </div>
                            <div class="form-check form-switch bg-white px-3 py-1 rounded-pill border shadow-sm" id="switchDepoBox">
                                <input class="form-check-input" type="checkbox" id="toggleDeposit" onchange="updatePreview(currentMode)" checked style="cursor:pointer;">
                                <label class="form-check-label small fw-medium text-info" for="toggleDeposit" style="cursor:pointer; margin-left:5px;">Pakai Deposit</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="phone-mockup">
                        <div class="phone-header">
                            <i class="fas fa-arrow-left"></i>
                            <div class="bg-white rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                <i class="fas fa-user text-muted"></i>
                            </div>
                            <div>
                                <div class="fw-bold" style="font-size: 0.95rem; line-height: 1;">Pelanggan</div>
                                <div style="font-size: 0.7rem; opacity: 0.8;">Online</div>
                            </div>
                        </div>
                        <div class="wa-container">
                            <div id="previewArea" class="w-100 pb-4"></div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let currentMode = 'lunas';
    let currentQrisImg = "<?= $img_qris ?: 'https://via.placeholder.com/300x300.png?text=QRIS+Toko+Anda' ?>";

    // Data Dummy Dasar
    const dummy = {
        '{nama_pelanggan}': 'Bpk. Budi',
        '{no_nota}': 'TRX-001',
        '{tanggal}': '21 Jan 2026',
        '{metode}': 'Cash',
        '{list_barang}': `-----------------------------\nFILE: desain-undangan.pdf\nprint hitam a4 70       2x     1.000\nprint warna 25% a4 70   2x     1.400\n-----------------------------\nFILE: tugas-undangan.pdf\nprint hitam a4 70       3x     1.500\nprint warna 25% a4 70   5x     3.500`,
        '{ongkir}': 'Rp 5.000',
        '{bayar}': 'Rp 20.000',
        '{kembali}': 'Rp 5.000',  
        '{sisa}': 'Rp 5.000',
        '{depo_awal}': 'Rp 10.000',
        '{depo_pakai}': 'Rp 5.000',
        '{sisa_depo}': 'Rp 5.000',
        '{antrian}': '5'
    };

    const total_biasa = "*Total : Rp 10.000*";
    const total_rincian = `Total Utang Sblm : Rp 10.000\nTotal Pembelian : Rp 10.000\n*Grand Total : Rp 20.000*`;

    function switchPreview(mode) {
        currentMode = mode;
        if(mode === 'lunas') {
            document.querySelectorAll('.var-lunas').forEach(e => e.classList.remove('d-none'));
            document.querySelectorAll('.var-utang').forEach(e => e.classList.add('d-none'));
            document.getElementById('switchUtangBox').style.display = 'none';
            document.getElementById('switchDepoBox').style.display = 'block';
        } else {
            document.querySelectorAll('.var-lunas').forEach(e => e.classList.add('d-none'));
            document.querySelectorAll('.var-utang').forEach(e => e.classList.remove('d-none'));
            document.getElementById('switchUtangBox').style.display = 'block';
            document.getElementById('switchDepoBox').style.display = 'none';
        }
        updatePreview(mode);
    }

    function formatWaText(text) {
        let punyaUtangLama = document.getElementById('toggleUtangLama') ? document.getElementById('toggleUtangLama').checked : false;
        let isiTotal = punyaUtangLama ? total_rincian : total_biasa;
        
        text = text.replace('{total}', isiTotal);

        for (const [key, val] of Object.entries(dummy)) {
            text = text.replaceAll(key, val);
        }
        
        text = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
        text = text.replace(/\*(.*?)\*/g, '<b>$1</b>');
        text = text.replace(/_(.*?)_/g, '<i>$1</i>');
        text = text.replace(/~(.*?)~/g, '<del>$1</del>');
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    function updatePreview(mode) {
        const previewArea = document.getElementById('previewArea');
        previewArea.innerHTML = ''; // Reset

        // 1. BUAT BUBBLE PERTAMA (TEKS UTAMA)
        let textRaw = (mode === 'lunas' || currentMode === 'lunas') ? document.getElementById('inputLunas').value : document.getElementById('inputUtang').value;
        
        // JIKA LUNAS DAN SWITCH DEPOSIT MENYALA -> GABUNGKAN TEKS
        if (mode === 'lunas' && document.getElementById('toggleDeposit').checked) {
            let textDepoRaw = document.getElementById('inputDepo').value;
            if (textDepoRaw.trim() !== '') {
                textRaw += "\n\n" + textDepoRaw;
            }
        }

        let textFormatted = formatWaText(textRaw);

        let bubble1 = `
            <div class="wa-bubble text-start">
                <div>${textFormatted}</div>
                <div class="wa-time"><?= date('H:i') ?> <i class="fas fa-check-double text-info ms-1"></i></div>
            </div>`;
        
        previewArea.innerHTML += bubble1;

        // 2. JIKA UTANG DAN OPSI AKTIF, TAMBAHKAN BUBBLE KEDUA (GAMBAR + CAPTION)
        if (mode === 'utang' || currentMode === 'utang') {
            const sendQrisEl = document.getElementById('sendQrisUnpaid');
            const qrisFields = document.getElementById('qrisSettingFields');
            const sendQris = !sendQrisEl || sendQrisEl.checked;
            if (qrisFields) qrisFields.style.opacity = sendQris ? '1' : '0.45';
            if (!sendQris) {
                previewArea.innerHTML += `
                    <div class="wa-bubble text-start mt-2" style="background:#fff3cd;">
                        <div><b>Pesan QRIS dimatikan</b><br><span style="font-size:0.8rem;">Nota belum lunas hanya mengirim pesan utama.</span></div>
                        <div class="wa-time"><?= date('H:i') ?></div>
                    </div>`;
                return;
            }
            let capRaw = document.getElementById('inputQris').value;
            let capFormatted = formatWaText(capRaw); 

            let bubble2 = `
                <div class="wa-bubble text-start mt-2">
                    <div class="wa-bubble-img">
                        <img src="${currentQrisImg}" style="width:100%; border-radius:5px;">
                    </div>
                    <div class="mt-1">${capFormatted}</div>
                    <div class="wa-time"><?= date('H:i') ?> <i class="fas fa-check-double text-info ms-1"></i></div>
                </div>`;
            
            previewArea.innerHTML += bubble2;
        }
    }

    function previewImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                currentQrisImg = e.target.result; 
                let imgPrev = document.getElementById('imgPreview');
                if(imgPrev) imgPrev.src = e.target.result;
                updatePreview('utang'); 
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function insertText(tag, targetId) {
        let inputId = targetId;
        if (!inputId) {
            inputId = (currentMode === 'utang') ? 'inputUtang' : 'inputLunas';
        }

        let el = document.getElementById(inputId);
        let start = el.selectionStart;
        let end = el.selectionEnd;
        let text = el.value;
        el.value = text.substring(0, start) + tag + text.substring(end);
        el.selectionStart = el.selectionEnd = start + tag.length;
        el.focus();
        updatePreview(currentMode);
    }

    document.addEventListener("DOMContentLoaded", () => updatePreview('lunas'));
</script>

</body>
</html>
