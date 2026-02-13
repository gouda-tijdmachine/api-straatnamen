<?php

declare(strict_types=1);

include 'config.php';

require_once 'classes/Router.php';
require_once 'classes/ApiHandler.php';
require_once 'classes/ResponseHelper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
    http_response_code(204);
    exit;
}

$router = new Router();
$apiHandler = new ApiHandler();

$router->get('/straatnamen/{identifier}', [$apiHandler, 'getStreetById']);
$router->get('/straatnamen', [$apiHandler, 'searchStreets']);
$router->post('/clear_cache', [$apiHandler, 'clearCache']);

try {
    $router->dispatch();
} catch (Exception $e) {
    ResponseHelper::error($e->getMessage(), 500);
}
