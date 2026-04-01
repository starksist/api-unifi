<?php
// ============================================
// Configuración de la API UniFi
// ============================================

// API Key de UniFi
define('UNIFI_API_KEY', 'TU-API-KEY-AQUI');

// URL base del controlador UniFi
define('UNIFI_BASE_URL', 'https://172.16.100.200');

// Site ID de UniFi
define('UNIFI_SITE_ID', 'TU-SITE-ID-AQUI');

// Directorio donde se guardan los CSV (relativo al proyecto)
define('CSV_DIR', __DIR__ . '/data');

// Intervalo de refresco en segundos
define('REFRESH_INTERVAL', 30);

// SSIDs clasificados como Guest (separados por coma, case-insensitive)
// Además se usa el campo is_guest de la API de UniFi
define('GUEST_SSIDS', '');

// Nombres de grupo IoT en UniFi (el controlador los marca automáticamente)
define('IOT_GROUP_NAMES', 'IoT,iot');
