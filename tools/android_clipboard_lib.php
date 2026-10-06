<?php
function android_clipboard_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS android_clipboard_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama_pelanggan VARCHAR(150) NOT NULL,
        no_penjualan VARCHAR(30) NULL,
        source_user_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        copied_at DATETIME NULL,
        INDEX idx_status_id (status, id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function android_clipboard_clean_name($name): string {
    $name = trim((string)$name);
    $name = preg_replace('/\s+/', ' ', $name);
    return substr($name, 0, 150);
}
