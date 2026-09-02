<?php

declare(strict_types=1);

class ResponseHelper
{
    public static function json($data, $statusCode = 200, ?int $lastModified = null, ?string $vary = null)
    {
        self::send(
            json_encode($data, JSON_UNESCAPED_UNICODE), #  | JSON_PRETTY_PRINT
            'application/json',
            $statusCode,
            $lastModified,
            $vary
        );
    }

    public static function geoJson($data, $statusCode = 200, ?int $lastModified = null, ?string $vary = null)
    {
        self::send(
            json_encode($data, JSON_UNESCAPED_UNICODE), #  | JSON_PRETTY_PRINT
            'application/geo+json',
            $statusCode,
            $lastModified,
            $vary
        );
    }

    public static function error($message, $statusCode = 400, $code = null)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $error = [
            'code' => $code ?: (string)$statusCode,
            'message' => $message
        ];
        $body = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        header('Content-Length: ' . strlen($body));

        if (self::wantsBody()) {
            echo $body;
        }
        exit();
    }

    /**
     * Een HEAD-response mag geen body hebben, wel dezelfde headers als de bijbehorende GET.
     */
    public static function wantsBody(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD';
    }

    /**
     * Send a cacheable response with an ETag (and optionally a Last-Modified) validator.
     *
     * Honours If-None-Match / If-Modified-Since with a 304, and suppresses the body on HEAD,
     * so a PWA can check "is er iets gewijzigd?" without downloading the representation.
     */
    private static function send(string $body, string $contentType, int $statusCode, ?int $lastModified = null, ?string $vary = null): void
    {
        $etag = '"' . md5($body) . '"';

        header('Content-Type: ' . $contentType);
        header('ETag: ' . $etag);
        if ($lastModified !== null) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        }
        header('Cache-Control: public, max-age=0, must-revalidate');
        if ($vary !== null) {
            header('Vary: ' . $vary);
        }

        if ($statusCode === 200 && self::isNotModified($etag, $lastModified)) {
            http_response_code(304);
            exit();
        }

        http_response_code($statusCode);
        header('Content-Length: ' . strlen($body));

        if (self::wantsBody()) {
            echo $body;
        }
        exit();
    }

    /**
     * RFC 9110 §13.2.2: If-None-Match takes precedence; If-Modified-Since is only
     * evaluated when no If-None-Match was sent.
     */
    private static function isNotModified(string $etag, ?int $lastModified): bool
    {
        $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($ifNoneMatch !== '') {
            if ($ifNoneMatch === '*') {
                return true;
            }
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                // Weak comparison: W/"abc" matches "abc".
                if (str_starts_with($candidate, 'W/')) {
                    $candidate = substr($candidate, 2);
                }
                if ($candidate === $etag) {
                    return true;
                }
            }

            return false;
        }

        if ($lastModified === null) {
            return false;
        }

        $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince === '') {
            return false;
        }

        $since = strtotime($ifModifiedSince);

        return $since !== false && $lastModified <= $since;
    }

    /**
     * Turn an xsd:dateTime from the triple store into a Unix timestamp for Last-Modified.
     *
     * Vangnet: een Last-Modified die voor de serverklok uit loopt is ongeldig en zou conditionele
     * requests breken, dus geklemd op "nu".
     */
    public static function toTimestamp(?string $xsdDateTime): ?int
    {
        if (empty($xsdDateTime)) {
            return null;
        }

        $timestamp = strtotime($xsdDateTime);

        return $timestamp === false ? null : min($timestamp, time());
    }

    public static function validateRequiredParam($param, $paramName)
    {
        if (empty($param)) {
            self::error("Missende of ongeldige {$paramName}.", 400, 'INVALID_PARAMETER');
        }
    }

    public static function validateUri($uri, $paramName)
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL)) {
            self::error("Ongeldige {$paramName} URI.", 400, 'INVALID_URI');
        }
    }

    public static function getQueryParam($key, $default = null)
    {
        return isset($_GET[$key]) ? $_GET[$key] : $default;
    }

    public static function getIntQueryParam($key, $default = null)
    {
        $value = self::getQueryParam($key, $default);

        return $value !== null ? (int)$value : null;
    }

    public static function getFloatQueryParam($key, $default = null)
    {
        $value = self::getQueryParam($key, $default);

        return $value !== null ? (float)$value : null;
    }
}
