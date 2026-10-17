<?php

// Install at /home/ACCOUNT/public_html/bizismart/gateway.php.
// Laravel remains /home/ACCOUNT/bizismart/backend (outside public_html).
// Adjust this path for a different reviewed layout; never accept a request path here.
$frontController = dirname(__DIR__, 2).'/bizismart/backend/public/index.php';
if (!is_file($frontController)) {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private');
    $requestId = bin2hex(random_bytes(16));
    header('X-Request-ID: '.$requestId);
    echo json_encode(['success' => false, 'error' => ['code' => 'DEPLOYMENT_INCOMPLETE', 'message' => 'The service is not configured.'], 'request_id' => $requestId]);
    exit;
}
require $frontController;
