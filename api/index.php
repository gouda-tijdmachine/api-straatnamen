<?php

declare(strict_types=1);

include 'config.php';

require_once 'classes/Router.php';
require_once 'classes/ApiHandler.php';
require_once 'classes/ResponseHelper.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
// If-None-Match en If-Modified-Since zijn geen CORS-safelisted requestheaders en moeten dus
// expliciet toegestaan worden; zonder Expose-Headers kan een browser-client ETag en
// Last-Modified cross-origin niet uitlezen.
header('Access-Control-Allow-Headers: Content-Type, Authorization, If-None-Match, If-Modified-Since');
header('Access-Control-Expose-Headers: ETag, Last-Modified');

if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
    http_response_code(204);
    exit;
}

$router = new Router();
$apiHandler = new ApiHandler();

$router->get('/straatnamen/{identifier}', [$apiHandler, 'getStreetById']);
$router->get('/straatnamen', [$apiHandler, 'searchStreets']);
$router->get('/afbeeldingen/{identifier}', [$apiHandler, 'getImagesByStreetId']);
$router->get('/ping', [$apiHandler, 'ping']);
$router->post('/clear_cache', [$apiHandler, 'clearCache']);

try {
    $router->dispatch();
} catch (Exception $e) {
    ResponseHelper::error($e->getMessage(), 500);
}
