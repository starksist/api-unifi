<?php
// ============================================
// API Proxy - Consulta UniFi y loguea en CSV
// ============================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';

/**
 * Hace una consulta paginada a la API de UniFi y devuelve todos los items
 */
function fetchUnifiPaginated(string $endpoint): array
{
    $allData = [];
    $offset  = 0;
    $limit   = 200;

    do {
        $url = UNIFI_BASE_URL . '/proxy/network/integrations/v1/sites/' . UNIFI_SITE_ID
             . '/' . $endpoint . '?limit=' . $limit . '&offset=' . $offset;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . UNIFI_API_KEY,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return ['error' => "Error al conectar con UniFi $endpoint (HTTP $httpCode): $error"];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Respuesta JSON inválida: ' . json_last_error_msg()];
        }

        $items      = $data['data'] ?? [];
        $totalCount = $data['totalCount'] ?? 0;
        $allData    = array_merge($allData, $items);
        $offset    += $limit;

    } while ($offset < $totalCount);

    return ['data' => $allData, 'totalCount' => $totalCount];
}

/**
 * Consulta la API clásica de UniFi (tiene SSID, is_guest, VLAN)
 */
function fetchUnifiClassicClients(): array
{
    $url = UNIFI_BASE_URL . '/proxy/network/api/s/default/stat/sta';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => [
            'X-API-KEY: ' . UNIFI_API_KEY,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return ['error' => "Error al conectar con UniFi clients (HTTP $httpCode): $error"];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Respuesta JSON inválida: ' . json_last_error_msg()];
    }

    return $data;
}

/**
 * Parsea lista de SSIDs desde config
 */
function parseSsidList(string $config): array
{
    return array_map('trim', array_map('strtolower', explode(',', $config)));
}

/**
 * Procesa los dispositivos y clientes para obtener métricas
 */
function processMetrics(array $devices, array $clients): array
{
    $deviceList = $devices['data'] ?? [];
    $clientList = $clients['data'] ?? [];

    // Contar APs usando el campo "features" que contiene "accessPoint"
    $apCount  = 0;
    $apOnline = 0;
    foreach ($deviceList as $device) {
        $features = $device['features'] ?? [];
        if (in_array('accessPoint', $features, true)) {
            $apCount++;
            if (strtoupper($device['state'] ?? '') === 'ONLINE') {
                $apOnline++;
            }
        }
    }

    // Clasificar SSIDs
    $guestSsids = GUEST_SSIDS !== '' ? parseSsidList(GUEST_SSIDS) : [];
    $iotGroups  = array_map('trim', array_map('strtolower', explode(',', IOT_GROUP_NAMES)));

    // Contar solo clientes WiFi (ignorar cableados)
    $wifiClients  = 0;
    $guestClients = 0;
    $iotClients   = 0;

    foreach ($clientList as $client) {
        if ($client['is_wired'] ?? false) {
            continue;
        }

        $ssid    = strtolower($client['essid'] ?? '');
        $isGuest = !empty($client['is_guest'])
                || (!empty($guestSsids) && in_array($ssid, $guestSsids, true));

        // IoT se detecta por usergroup_id o network_members_group_ids
        $ugId    = strtolower($client['usergroup_id'] ?? '');
        $ngIds   = array_map('strtolower', $client['network_members_group_ids'] ?? []);
        $isIot   = in_array($ugId, $iotGroups, true)
                || !empty(array_intersect($ngIds, $iotGroups));

        if ($isGuest) {
            $guestClients++;
        } elseif ($isIot) {
            $iotClients++;
        }

        $wifiClients++;
    }

    return [
        'timestamp'     => date('Y-m-d H:i:s'),
        'ap_total'      => $apCount,
        'ap_online'     => $apOnline,
        'wifi_clients'  => $wifiClients,
        'guest_clients' => $guestClients,
        'iot_clients'   => $iotClients,
        'total_clients' => $wifiClients,
    ];
}

/**
 * Guarda las métricas en un archivo CSV diario
 */
function logToCsv(array $metrics): void
{
    if (!is_dir(CSV_DIR)) {
        mkdir(CSV_DIR, 0755, true);
    }

    $filename = CSV_DIR . '/unifi_' . date('Y-m-d') . '.csv';
    $isNew    = !file_exists($filename);

    $fp = fopen($filename, 'a');
    if ($fp === false) {
        return;
    }

    if ($isNew) {
        fputcsv($fp, ['timestamp', 'ap_total', 'ap_online', 'wifi_clients', 'guest_clients', 'iot_clients', 'total_clients']);
    }

    fputcsv($fp, [
        $metrics['timestamp'],
        $metrics['ap_total'],
        $metrics['ap_online'],
        $metrics['wifi_clients'],
        $metrics['guest_clients'],
        $metrics['iot_clients'],
        $metrics['total_clients'],
    ]);

    fclose($fp);
}

// ============================================
// Ejecución principal
// ============================================

$devices = fetchUnifiPaginated('devices');
if (isset($devices['error'])) {
    echo json_encode(['success' => false, 'error' => $devices['error']]);
    exit;
}

$clients = fetchUnifiClassicClients();
if (isset($clients['error'])) {
    echo json_encode(['success' => false, 'error' => $clients['error']]);
    exit;
}

$metrics = processMetrics($devices, $clients);

// Guardar en CSV
logToCsv($metrics);

// Devolver al frontend
echo json_encode([
    'success' => true,
    'data'    => $metrics,
]);
