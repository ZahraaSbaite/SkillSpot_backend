<?php
header('Content-Type: application/json');
echo json_encode([
    'received_action' => $_GET['action'] ?? null,
    'all_get' => $_GET,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'n/a',
    'query_string' => $_SERVER['QUERY_STRING'] ?? 'n/a'
]);
