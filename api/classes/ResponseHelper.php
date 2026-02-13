<?php

declare(strict_types=1);

class ResponseHelper
{
    public static function json($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE); #  | JSON_PRETTY_PRINT
        exit();
    }

    public static function error($message, $statusCode = 400, $code = null)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        $error = [
            'code' => $code ?: (string)$statusCode,
            'message' => $message
        ];
        echo json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    public static function geoJson($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/geo+json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE); #  | JSON_PRETTY_PRINT
        exit();
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
