<?php
// ============================================
// Descarga de CSV diario
// ============================================
require_once __DIR__ . '/config.php';

$date = $_GET['date'] ?? date('Y-m-d');

// Validar formato de fecha para evitar path traversal
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo 'Fecha inválida';
    exit;
}

$filename = CSV_DIR . '/unifi_' . $date . '.csv';

if (!file_exists($filename)) {
    http_response_code(404);
    echo 'No hay datos para la fecha ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="unifi_' . $date . '.csv"');
readfile($filename);
