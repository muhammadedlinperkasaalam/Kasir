<?php
session_start();

// Pastikan user login
if (!isset($_SESSION['user_id'])) { 
    echo json_encode(['status' => 'error', 'message' => 'Sesi habis, silakan login ulang.']);
    exit; 
}

require_once '../../config/database.php';

// Set header JSON
header('Content-Type: application/json');

// Pastikan request adalah POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

try {
    // 1. TANGKAP INPUT
    $action = $_POST['action'] ?? 'simpan';
    $nama   = trim($_POST['nama'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    
    // Ambil input mentah
    $input_telp = trim($_POST['telp'] ?? '');
    $input_lid  = trim($_POST['wa_lid'] ?? '');

    // -----------------------------------------------------------
    // LOGIKA CERDAS: PEMISAH NO HP VS WA LID (REVISI 15 DIGIT)
    // -----------------------------------------------------------
    $final_no_telp = '';
    $final_wa_lid  = '';

    // Bersihkan karakter non-angka
    $clean_input_telp = preg_replace('/[^0-9]/', '', $input_telp);
    $clean_input_lid  = preg_replace('/[^0-9]/', '', $input_lid);

    // ANALISA KOLOM TELEPON
    // REVISI: Jika panjang >= 15 digit, anggap sebagai WA LID
    if (strlen($clean_input_telp) >= 15) {
        $final_wa_lid = $clean_input_telp; // Pindahkan ke LID
        $final_no_telp = ''; // Kosongkan no telp (karena isinya LID)
    } else {
        // Jika panjang < 15, format sebagai No HP Indonesia (62...)
        if (!empty($clean_input_telp)) {
            if (substr($clean_input_telp, 0, 1) == '0') {
                $final_no_telp = '62' . substr($clean_input_telp, 1);
            } elseif (substr($clean_input_telp, 0, 1) == '8') {
                $final_no_telp = '62' . $clean_input_telp;
            } else {
                $final_no_telp = $clean_input_telp;
            }
        }
    }

    // ANALISA KOLOM LID (Prioritas Tinggi dari Input Hidden)
    if (!empty($clean_input_lid)) {
        $final_wa_lid = $clean_input_lid;
        // Jika input hidden LID terisi, pastikan jika telp sama dengan LID, telp dikosongkan
        if ($clean_input_telp == $clean_input_lid) {
            $final_no_telp = ''; 
        }
    }

    // -----------------------------------------------------------
    // A. CEK NAMA (Mencegah Duplikat)
    // -----------------------------------------------------------
    if ($action == 'check_nama') {
        if (empty($nama)) {
            echo json_encode(['status' => 'error', 'message' => 'Nama tidak boleh kosong.']);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT kode_pelanggan, nama_pelanggan, no_telepon FROM pelanggan WHERE nama_pelanggan LIKE ? LIMIT 1");
        $stmt->execute([$nama]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode(['status' => 'exist', 'data' => $existing]);
        } else {
            echo json_encode(['status' => 'not_exist']);
        }
        exit;
    }

    // -----------------------------------------------------------
    // B. UPDATE PELANGGAN LAMA
    // -----------------------------------------------------------
    if ($action == 'update') {
        $kode_pelanggan = $_POST['kode_pelanggan'] ?? '';
        if (empty($kode_pelanggan)) { echo json_encode(['status' => 'error', 'message' => 'Kode pelanggan hilang.']); exit; }

        $query = "UPDATE pelanggan SET alamat = :alamat";
        $params = [':alamat' => $alamat, ':kode' => $kode_pelanggan];

        if (!empty($final_no_telp)) {
            $query .= ", no_telepon = :telp";
            $params[':telp'] = $final_no_telp;
        }

        if (!empty($final_wa_lid)) {
            $query .= ", wa_lid = :lid";
            $params[':lid'] = $final_wa_lid;
        }

        $query .= " WHERE kode_pelanggan = :kode";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        // Ambil data terbaru
        $stmt_get = $pdo->prepare("SELECT * FROM pelanggan WHERE kode_pelanggan = ?");
        $stmt_get->execute([$kode_pelanggan]);
        $pelanggan = $stmt_get->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'id' => $pelanggan['kode_pelanggan'],
            'nama' => $pelanggan['nama_pelanggan'],
            'telp' => $pelanggan['no_telepon'],
            'deposit' => $pelanggan['saldo_deposit'],
            'utang' => $pelanggan['sisa_utang']
        ]);
        exit;
    }

    // -----------------------------------------------------------
    // C. SIMPAN PELANGGAN BARU (INSERT)
    // -----------------------------------------------------------
    if ($action == 'simpan') {
        if (empty($nama)) {
            echo json_encode(['status' => 'error', 'message' => 'Nama Pelanggan wajib diisi!']);
            exit;
        }

        // Generate Kode P000...
        $stmt = $pdo->query("SELECT MAX(kode_pelanggan) as max_code FROM pelanggan WHERE kode_pelanggan LIKE 'P%'");
        $max = $stmt->fetchColumn();

        if ($max) {
            $urutan = (int) substr($max, 1);
            $urutan++;
        } else {
            $urutan = 1;
        }
        $kode_baru = "P" . sprintf("%09s", $urutan);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "INSERT INTO pelanggan (kode_pelanggan, nama_pelanggan, alamat, no_telepon, wa_lid) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$kode_baru, $nama, $alamat, $final_no_telp, $final_wa_lid]);

        echo json_encode([
            'status' => 'success',
            'id'     => $kode_baru,
            'nama'   => $nama,
            'telp'   => $final_no_telp, 
            'deposit'=> 0,
            'utang'  => 0
        ]);
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
?>