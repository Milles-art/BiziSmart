<?php

// Install as /home/ACCOUNT/public_html/bizismart-admin/index.php.
$frontController = dirname(__DIR__, 2).'/bizismart/backend/public/index.php';
if (!is_file($frontController)) {
    http_response_code(503);
    header('Cache-Control: no-store, private');
    echo 'The service is not configured.';
    exit;
}
require $frontController;
