<?php
// Simple verification server template for Envato purchase codes.
// IMPORTANT: Host this privately and configure a valid ENVATO_API_TOKEN.

// Load configuration from environment or local config file
$envToken = getenv('ENVATO_API_TOKEN') ?: null;
if(!$envToken){
    // try a config file
    if(file_exists(__DIR__ . '/config.php')){
        $cfg = include __DIR__ . '/config.php';
        $envToken = $cfg['ENVATO_API_TOKEN'] ?? null;
    }
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if(!is_array($input) || empty($input['purchase_code'])){
    http_response_code(400);
    echo json_encode(['valid' => false, 'message' => 'purchase_code required']);
    exit;
}
$code = $input['purchase_code'];

if(!$envToken){
    http_response_code(500);
    echo json_encode(['valid' => false, 'message' => 'Server missing ENVATO_API_TOKEN']);
    exit;
}

// Call Envato API
$ch = curl_init('https://api.envato.com/v3/market/author/sale?code=' . urlencode($code));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $envToken,
    'User-Agent: Verification Server',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if($res === false){
    http_response_code(502);
    echo json_encode(['valid' => false, 'message' => 'Error contacting Envato API: ' . $err]);
    exit;
}

$data = json_decode($res, true);
if($http === 200 && is_array($data)){
    // Basic verification: respond with a small payload
    $out = [
        'valid' => true,
        'item' => $data['item'] ?? null,
        'buyer' => $data['buyer'] ?? null,
        'sold_at' => $data['sold_at'] ?? null,
    ];
    echo json_encode($out);
    exit;
}

$msg = is_array($data) && isset($data['message']) ? $data['message'] : 'Envato API error';
http_response_code($http ?: 502);
echo json_encode(['valid' => false, 'message' => $msg, 'raw' => $data]);

