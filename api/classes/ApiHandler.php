<?php

declare(strict_types=1);

require_once 'ResponseHelper.php';
require_once 'DataService.php';
require_once 'CacheService.php';

#$router->get('/straatnamen/{identifier}', [$apiHandler, 'getStreetById']);
#$router->get('/straatnamen/geojson', [$apiHandler, 'getStreetsGeoJson']);
#+$router->get('/straatnamen', [$apiHandler, 'searchStreets']);
#$router->post('/clear_cache', [$apiHandler, 'clearCache']);

class ApiHandler
{
    private DataService $dataService;
    private CacheService $cache;

    public function __construct()
    {
        $this->dataService = new DataService();
        $this->cache = new CacheService();
    }

    public function searchStreets(array $params = []): void
    {
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantgeojson = ResponseHelper::getQueryParam('geojson');
        if (str_contains($acceptHeader, 'application/geo+json') || isset($wantgeojson)) {
            $this->searchStreetsGeoJson($params);
        } elseif (str_contains($acceptHeader, 'application/json') || str_contains($acceptHeader, 'text/html')) {
            $this->searchStreetsJson($params);
        } else {
            ResponseHelper::error('Ongeldige accept header.', 406, 'NOT_ACCEPTABLE');
        }

        return;
    }

    private function searchStreetsGeoJson(array $params = []): void
    {
        try {
            $q = ResponseHelper::getQueryParam('q', '');
            $limit = ResponseHelper::getIntQueryParam('limit', 2000);
            $offset = ResponseHelper::getIntQueryParam('offset', 0);
            $type = ResponseHelper::getQueryParam('type', 'alle');
            $lat = ResponseHelper::getFloatQueryParam('lat', 0);
            $lon = ResponseHelper::getFloatQueryParam('lon', 0);

            $polygonen = $this->dataService->geoJsonStreets($q, $limit, $offset, $type, $lat, $lon);
            ResponseHelper::geoJson($polygonen);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getStreetsGeoJson');
        }
    }

    private function searchStreetsJson(array $params = []): void
    {
        try {
            $q = ResponseHelper::getQueryParam('q', '');
            $limit = ResponseHelper::getIntQueryParam('limit', 2000);
            $offset = ResponseHelper::getIntQueryParam('offset', 0);
            $type = ResponseHelper::getQueryParam('type', 'alle');
            $lat = ResponseHelper::getFloatQueryParam('lat', 0);
            $lon = ResponseHelper::getFloatQueryParam('lon', 0);

            $result = $this->dataService->searchStreets($q, $limit, $offset, $type, $lat, $lon);

            if (empty($result['straten'])) {
                ResponseHelper::error('Geen straten gevonden.', 404, 'NOT_FOUND');

                return;
            }

            ResponseHelper::json($result);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'searchStreets');
        }
    }

    public function getStreetById(array $params): void
    {
        try {
            $paramIdentifier = $params['identifier'] ?? null;
            $identifier = (!empty($paramIdentifier) && $paramIdentifier !== '{identifier}')
                ? $this->validateAndDecodeIdentifier($paramIdentifier)
                : $this->validateAndDecodeIdentifier(ResponseHelper::getQueryParam('identifier'));
            if ($identifier === null) {
                return;
            }

            $street = $this->dataService->getStreet($identifier);

            if (!$street) {
                ResponseHelper::error('Straat niet gevonden.', 404, 'NOT_FOUND');

                return;
            }

            ResponseHelper::json($street);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getStreetById');
        }
    }

    public function getImagesByStreetId(array $params): void
    {
        try {
            $paramIdentifier = $params['identifier'] ?? null;
            $identifier = (!empty($paramIdentifier) && $paramIdentifier !== '{identifier}')
                ? $this->validateAndDecodeIdentifier($paramIdentifier)
                : $this->validateAndDecodeIdentifier(ResponseHelper::getQueryParam('identifier'));
            if ($identifier === null) {
                return;
            }
            $limit = ResponseHelper::getIntQueryParam('limit', 25);
            $offset = ResponseHelper::getIntQueryParam('offset', 0);

            list($aantalimages, $images) = $this->dataService->getImages($identifier, $limit, $offset);

            if ($aantalimages == 0) {
                ResponseHelper::error('Straat niet gevonden.', 404, 'NOT_FOUND');

                return;
            }

            ResponseHelper::json([ "aantal" => $aantalimages, "afbeeldingen" => $images ]);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'getImagesByStreetId');
        }
    }

    public function ping(array $params = []): void
    {
        ResponseHelper::json([
            'time' => gmdate('c'),
            'php' => PHP_VERSION,
            'server_addr' => $_SERVER['SERVER_ADDR'] ?? null,
            'egress_ipv4' => self::fetchEgress('https://api.ipify.org', CURL_IPRESOLVE_V4),
            'egress_ipv6' => self::fetchEgress('https://api6.ipify.org', CURL_IPRESOLVE_V6),
            'sparql_probe' => self::probeSparql(),
        ]);
    }

    private static function fetchEgress(string $url, int $ipResolve): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_IPRESOLVE => $ipResolve,
        ]);
        $body = curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($err !== null) {
            return 'error: ' . $err;
        }
        $body = trim((string)$body);
        return $body === '' ? null : $body;
    }

    private static function probeSparql(): array
    {
        $url = defined('SPARQL_ENDPOINT') ? SPARQL_ENDPOINT : 'https://sparql.goudatijdmachine.nl';
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        curl_exec($ch);
        $err = curl_errno($ch) ? curl_error($ch) : null;
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $connectMs = (int)round(((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME)) * 1000);
        $totalMs = (int)round((microtime(true) - $start) * 1000);
        curl_close($ch);

        return [
            'url' => $url,
            'resolved_ip' => $primaryIp ?: null,
            'http' => $status ?: null,
            'error' => $err,
            'connect_ms' => $connectMs,
            'total_ms' => $totalMs,
        ];
    }

    public function clearCache(array $params = []): void
    {
        try {
            $deleted = $this->cache->clear_cache();

            if ($deleted > 0) {
                $response = [
                    'message' => "Successfully cleared $deleted keys from cache"
                ];
            } else {
                $response = [
                    'message' => "No keys were cleared from cache"
                ];
            }
            ResponseHelper::json($response);
        } catch (Exception $e) {
            $this->logAndReturnError($e, 'clearCache');
        }
    }

    private function validateAndDecodeIdentifier(?string $identifier): ?string
    {
        if (empty($identifier)) {
            ResponseHelper::error('Missende of ongeldige identifier.', 400, 'MISSING_IDENTIFIER');

            return null;
        }

        $identifier = urldecode($identifier);

        if (!filter_var($identifier, FILTER_VALIDATE_URL) || !$this->startsWithArk($identifier)) {
            ResponseHelper::error('Missende of ongeldige identifier.', 400, 'INVALID_IDENTIFIER');

            return null;
        }

        return $identifier;
    }

    private function startsWithArk(string $url): bool
    {
        $prefix = "https://n2t.net/ark:/60537/";

        return str_starts_with($url, $prefix);
    }

    private function logAndReturnError(Exception $e, string $method): void
    {
        ResponseHelper::error('Er heeft zich een onverwachte fout voorgedaan in ApiHandler::{$method}.', 500, 'INTERNAL_ERROR');
    }
}
