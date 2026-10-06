<?php
session_start();

// Jika sudah login, lempar kembali ke dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

require_once '../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = md5(trim($_POST['password'])); // Enkripsi MD5 sesuai spek database

    // Query Cek User ke Tabel Operator
    $sql = "SELECT * FROM operator WHERE username = ? AND password = ? LIMIT 1";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username, $password]);
        $user = $stmt->fetch();

        if ($user) {
            // Login Sukses - Set Session
            $_SESSION['user_id'] = $user['kode_user'];
            $_SESSION['nama']    = $user['nama_user'];
            $_SESSION['level']   = $user['level']; // admin / kasir
            $_SESSION['shift']   = $user['shift']; // shift 1 / 2 / 3
            
            // Redirect ke Dashboard
            header("Location: ../index.php");
            exit;
        } else {
            // Login Gagal
            $error = "Username atau Password salah!";
        }
    } catch (PDOException $e) {
        $error = "Terjadi kesalahan sistem: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Aplikasi Kasir</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #e9ecef;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .login-header {
            background: #0d6efd;
            color: white;
            padding: 20px;
            text-align: center;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
        }
        .btn-login {
            background-color: #0d6efd;
            border: none;
            padding: 10px;
            font-weight: bold;
        }
        .btn-login:hover {
            background-color: #0b5ed7;
        }
    </style>
</head>
<body>

    <div class="card login-card">
        <div class="login-header">
            <h3><i class="fas fa-cash-register me-2"></i>APP KASIR</h3>
            <p class="mb-0 small">Silakan login untuk memulai</p>
        </div>
        <div class="card-body p-4">
            
            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-1"></i> <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" required autofocus>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" required>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">LOGIN</button>
                </div>
            </form>
        </div>
        <div class="card-footer text-center py-3 text-muted bg-white border-top-0 rounded-bottom">
            <small>&copy; <?= date('Y') ?> Sistem Kasir PHP Native</small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
