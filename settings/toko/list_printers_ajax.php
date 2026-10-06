<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Session habis. Login ulang.']);
    exit;
}

try {
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        throw new Exception('Deteksi printer otomatis hanya tersedia di Windows.');
    }

    $ps = <<<'PS'
$printers = Get-CimInstance Win32_Printer | Select-Object Name,ShareName,PortName,Default,WorkOffline,PrinterStatus
$printers | ConvertTo-Json -Depth 3
PS;
    $tmp = tempnam(sys_get_temp_dir(), 'printers_') . '.ps1';
    file_put_contents($tmp, $ps);
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($tmp) . ' 2>&1';
    exec($cmd, $out, $code);
    @unlink($tmp);
    $raw = trim(implode("\n", $out));
    if ($code !== 0 || $raw === '') {
        throw new Exception('Gagal membaca daftar printer Windows. ' . $raw);
    }
    $data = json_decode($raw, true);
    if ($data === null) {
        throw new Exception('Output printer Windows tidak valid: ' . substr($raw, 0, 300));
    }
    if (isset($data['Name'])) $data = [$data];
    $result = [];
    foreach ($data as $p) {
        $name = trim((string)($p['Name'] ?? ''));
        if ($name === '') continue;
        $share = trim((string)($p['ShareName'] ?? ''));
        $value = $share !== '' ? $share : $name;
        $result[] = [
            'name' => $name,
            'share_name' => $share,
            'port_name' => (string)($p['PortName'] ?? ''),
            'default' => !empty($p['Default']),
            'offline' => !empty($p['WorkOffline']),
            'value' => $value,
            'recommended' => $share !== '',
        ];
    }
    echo json_encode(['status'=>'success','printers'=>$result], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
