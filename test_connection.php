<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'status' => 'ok',
    'message' => 'Backend reachable!',
    'timestamp' => time(),
    'php_version' => phpversion()
]);
