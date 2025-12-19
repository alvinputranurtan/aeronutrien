<?php

require_once __DIR__.'/db.php';

$device_id = (int) ($_GET['device_id'] ?? 0);
if ($device_id <= 0) {
    exit('device_id invalid');
}

// pastikan device ada
$st = db()->prepare('SELECT id FROM devices WHERE id=? AND deleted_at IS NULL');
$st->execute([$device_id]);
if (!$st->fetch()) {
    http_response_code(404);
    exit('Device tidak ditemukan');
}

// cek config aktif
$st = db()->prepare('SELECT id FROM configurations
                     WHERE device_id=? AND is_active=1 AND deleted_at IS NULL
                     ORDER BY version DESC LIMIT 1');
$st->execute([$device_id]);
$cfg = $st->fetch();

if ($cfg) {
    header('Location: npk_dashboard.php?device_id='.$device_id);
    exit;
}

// default sesuai contoh kamu
$default = [
    'theme' => 'light',
    'device_type' => 'nutrition',
    'device_configuration' => [
        'current' => ['n' => 50, 'p' => 45, 'k' => 35],
        'threshold' => ['n' => 45, 'p' => 55, 'k' => 75],
    ],
];
$json = json_encode($default, JSON_UNESCAPED_SLASHES);

try {
    $ins = db()->prepare('INSERT INTO configurations (device_id, version, is_active, data_configuration)
                          VALUES (?, 1, 1, ?)');
    $ins->execute([$device_id, $json]);
    header('Location: npk_dashboard.php?device_id='.$device_id);
    exit;
} catch (PDOException $e) {
    exit('Gagal membuat konfigurasi default.');
}
