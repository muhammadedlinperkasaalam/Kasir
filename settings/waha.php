<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
require_once '../config/database.php';
// --- PROSES SIMPAN SETTING ---
if (isset($_POST['simpan_server'])) {
    $url = rtrim($_POST['base_url'], '/'); // Hilangkan slash akhir
    $sess = $_POST['session_name'];
    // Kolom api_key tidak dipakai di local gateway, kita kosongkan atau isi dummy
    $token = 'local_token';
    $cek = $pdo->query("SELECT count(*) FROM waha_setting WHERE id = 1")->fetchColumn();
    if ($cek > 0) {
        $stmt = $pdo->prepare("UPDATE waha_setting SET base_url = ?, session_name = ?, api_key = ? WHERE id = 1");
    } else {
        $stmt = $pdo->prepare("INSERT INTO waha_setting (id, base_url, session_name, api_key) VALUES (1, ?, ?, ?)");
    }
    if ($stmt->execute([$url, $sess, $token])) {
        echo "<script>alert('Setting Gateway Berhasil Disimpan!'); window.location='waha.php';</script>";
    } else {
        echo "<script>alert('Gagal menyimpan.');</script>";
    }
}

// --- AMBIL DATA ---
$data = $pdo->query("SELECT * FROM waha_setting WHERE id = 1")->fetch();
$base_url = $data['base_url'] ?? 'http://localhost:8000/api';
$session_id = $data['session_name'] ?? 'device_admin_1';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Koneksi WhatsApp Gateway | Addinta Printing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* Base Styling - Modernisasi */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; color: #1e293b; }
        
        /* Sidebar Styling */
        .sidebar { background: white; border-right: 1px solid #e9ecef; }
        .nav-link { border-radius: 10px; margin-bottom: 5px; transition: 0.3s; font-weight: 500; color: #6c757d; }
        .nav-link.active { background-color: #f0f4ff !important; color: #0d6efd !important; }
        .nav-link:hover { background-color: #f8f9fa; }

        /* Main Content Styling */
        .main-content { flex-grow: 1; padding: 25px; background: #f8f9fa; overflow-y: auto; width: 100%; }
        
        /* Cards Custom */
        .card-custom { border: none; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); background: white; overflow: hidden; }
        .card-header-custom { padding: 18px 25px; border-bottom: 1px solid #f1f5f9; background: white; }
    </style>
</head>
<body>

<?php include '../sidebar.php'; ?>

<div class="main-content">
    
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0 text-dark"><i class="fab fa-whatsapp text-success me-2"></i> Local WA Gateway</h4>
            <p class="text-muted small mb-0 mt-1">Konfigurasi jembatan koneksi sistem ke WhatsApp.</p>
        </div>
    </div>

    <div class="row g-4">
        
        <div class="col-lg-5">
            <div class="card card-custom h-100">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-primary"><i class="fas fa-server me-2"></i>Konfigurasi Server</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary small">Base URL Gateway</label>
                            <input type="text" name="base_url" id="baseUrlInput" class="form-control" value="<?= htmlspecialchars($base_url) ?>" placeholder="http://localhost:8000/api" required>
                            <div class="form-text small">Alamat server PHP Native Gateway berjalan.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary small">Session ID (Nama Sesi)</label>
                            <input type="text" name="session_name" id="sessionNameInput" class="form-control" value="<?= htmlspecialchars($session_id) ?>" placeholder="device_admin_1" required>
                            <div class="form-text small">Nama unik untuk sesi WhatsApp ini.</div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" name="simpan_server" class="btn btn-primary fw-bold py-2 rounded-3">
                                <i class="fas fa-save me-2"></i> SIMPAN KONEKSI
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card card-custom h-100">
                <div class="card-header card-header-custom d-flex justify-content-between align-items-center bg-light">
                    <h6 class="m-0 fw-bold text-success"><i class="fas fa-qrcode me-2"></i>Status & Scan</h6>
                    <span id="statusBadge" class="badge bg-secondary px-3 py-2 rounded-pill shadow-sm">Checking...</span>
                </div>
                <div class="card-body text-center d-flex flex-column justify-content-center align-items-center" style="min-height: 350px;">
                    
                    <div id="qrArea" class="d-none w-100 py-3 bg-light rounded-4 border">
                        <div id="qrcode" class="bg-white p-3 mb-3 d-inline-block border rounded-3 shadow-sm"></div>
                        <p class="text-muted small fw-medium mb-0">Buka WhatsApp > Perangkat Tertaut > Tautkan</p>
                    </div>

                    <div id="connectedArea" class="d-none">
                        <i class="fas fa-check-circle text-success fa-5x mb-3"></i>
                        <h4 class="fw-bold text-success">TERHUBUNG</h4>
                        <p class="text-muted mb-4">WhatsApp siap mengirim pesan.</p>
                        <button onclick="doLogout()" class="btn btn-outline-danger btn-sm px-4 py-2 rounded-pill fw-medium shadow-sm">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout / Scan Ulang
                        </button>
                    </div>

                    <div id="startArea" class="d-none">
                        <i class="fas fa-power-off text-secondary fa-4x mb-3 opacity-50"></i>
                        <p class="text-muted fw-medium">Sesi belum dimulai.</p>
                        <button onclick="startSession()" class="btn btn-success px-4 py-2 rounded-pill fw-bold shadow-sm mt-2">
                            <i class="fas fa-play me-2"></i> Mulai Sesi & Scan QR
                        </button>
                    </div>

                    <div id="errorArea" class="d-none">
                        <i class="fas fa-exclamation-triangle text-danger fa-4x mb-3"></i>
                        <h6 class="fw-bold text-danger">Gagal Terhubung ke Gateway</h6>
                        <p class="text-muted small mb-4">Pastikan script <code class="bg-light px-2 py-1 rounded text-danger border">start_app.bat</code> sudah dijalankan.</p>
                        <button onclick="checkStatus()" class="btn btn-dark px-4 py-2 rounded-pill fw-medium shadow-sm">
                            <i class="fas fa-sync-alt me-1"></i> Coba Deteksi Ulang
                        </button>
                    </div>

                </div>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // JS SAMA PERSIS 100%
    const API_URL = "http://localhost:8000/api";
    const SESSION_ID = "<?= $session_id ?>";

    // 1. Cek Status Berkala
    async function checkStatus() {
        try {
            const res = await fetch(`${API_URL}/device/status?session_id=${SESSION_ID}`);
            if(!res.ok) throw new Error("Server Dead");
            
            const data = await res.json();
            
            const badge = document.getElementById('statusBadge');
            const qrArea = document.getElementById('qrArea');
            const connectedArea = document.getElementById('connectedArea');
            const startArea = document.getElementById('startArea');
            const errorArea = document.getElementById('errorArea');
            const qrcodeContainer = document.getElementById('qrcode');

            errorArea.classList.add('d-none');

            if (data.status === 'CONNECTED') {
                badge.className = "badge bg-success px-3 py-2 rounded-pill shadow-sm";
                badge.innerText = "ONLINE";
                
                qrArea.classList.add('d-none');
                startArea.classList.add('d-none');
                connectedArea.classList.remove('d-none');
            
            } else {
                badge.className = "badge bg-warning text-dark px-3 py-2 rounded-pill shadow-sm";
                badge.innerText = "WAITING SCAN";
                
                connectedArea.classList.add('d-none');
                
                if (data.qr) {
                    startArea.classList.add('d-none');
                    qrArea.classList.remove('d-none');
                    qrcodeContainer.innerHTML = "";
                    new QRCode(qrcodeContainer, { text: data.qr, width: 200, height: 200 }); // Disesuaikan sedikit width-nya agar pas di box
                } else {
                    qrArea.classList.add('d-none');
                    startArea.classList.remove('d-none');
                }
            }
        } catch (e) {
            console.error(e);
            document.getElementById('statusBadge').innerText = "ERROR";
            document.getElementById('statusBadge').className = "badge bg-danger px-3 py-2 rounded-pill shadow-sm";
            document.getElementById('startArea').classList.add('d-none');
            document.getElementById('connectedArea').classList.add('d-none');
            document.getElementById('qrArea').classList.add('d-none');
            document.getElementById('errorArea').classList.remove('d-none');
        }
    }

    // 2. Start Session (Trigger Node.js)
    async function startSession() {
        const btn = document.querySelector('#startArea button');
        btn.disabled = true; btn.innerText = "Memuat...";
        
        try {
            await fetch(`${API_URL}/device/start`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ session_id: SESSION_ID })
            });
            setTimeout(checkStatus, 1000); 
        } catch(e) { 
            Swal.fire('Error!', 'Gagal memulai sesi WhatsApp.', 'error'); 
        }
        
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-play me-2"></i> Mulai Sesi & Scan QR';
    }

    // 3. Logout dengan SweetAlert2
    function doLogout() {
        Swal.fire({
            title: 'Yakin ingin logout?',
            text: "Sesi WhatsApp ini akan diputus dan Anda harus scan QR ulang.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Logout!',
            cancelButtonText: 'Batal'
        }).then(async (result) => {
            if (result.isConfirmed) {
                
                // Tampilkan loading saat proses API berjalan
                Swal.fire({
                    title: 'Memproses...',
                    text: 'Sedang memutuskan koneksi ke Gateway',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                try {
                    await fetch(`${API_URL}/device/logout`, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ session_id: SESSION_ID })
                    });
                    
                    // Tampilkan sukses lalu reload halaman
                    Swal.fire({
                        title: 'Berhasil!',
                        text: 'Sesi WhatsApp berhasil dilogout.',
                        icon: 'success',
                        confirmButtonColor: '#198754'
                    }).then(() => {
                        location.reload();
                    });

                } catch (error) {
                    Swal.fire('Gagal!', 'Tidak dapat terhubung ke server saat logout.', 'error');
                }
            }
        });
    }

    // Jalankan otomatis
    checkStatus();
    setInterval(checkStatus, 3000); 
</script>

</body>
</html>
