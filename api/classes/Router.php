<?php

declare(strict_types=1);

class Router
{
    private $routes = [];

    public function get($path, $handler)
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post($path, $handler)
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];

        $parsedUri = parse_url($requestUri);
        $path = preg_replace("/\/api-[a-z]+/", "", $parsedUri['path']);

        if (empty($path)) {
            $path = '/';
        }

        if (!isset($this->routes[$requestMethod])) {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);

            return;
        }

        $routeFound = false;
        $params = [];

        foreach ($this->routes[$requestMethod] as $routePath => $handler) {
            $pattern = $this->convertToRegex($routePath);
            if (preg_match($pattern, $path, $matches)) {
                $routeFound = true;

                preg_match_all('/\{([^}]+)\}/', $routePath, $paramNames);
                for ($i = 1; $i < count($matches); $i++) {
                    $paramName = $paramNames[1][$i - 1];
                    $params[$paramName] = urldecode($matches[$i]);
                }

                if (is_array($handler)) {
                    call_user_func($handler, $params);
                } else {
                    $handler($params);
                }

                break;
            }
        }

        if (!$routeFound) {
            if ($this->serveAsset($path)) {
                return;
            }
            $this->serveDocs();
        }
    }

    private function convertToRegex($path)
    {
        $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $path);
        $pattern = str_replace('/', '\/', $pattern);

        return '/^' . $pattern . '$/';
    }

    private function serveDocs()
    {
        $docsPath = __DIR__ . '/../docs.html';
        if (file_exists($docsPath)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($docsPath);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Route not found']);
        }
    }

    private function serveAsset($path)
    {
        if (preg_match('#/assets/(.+)$#', $path, $matches)) {
            $file = $matches[1];
        } elseif (preg_match('#qa-results#', $path, $matches)) {
            $file = readfile(__DIR__ . '/../../tests/qa-results/index.html');

            return true;
        } elseif ($path === '/favicon.ico') {
            $file = 'favicon.ico';
        } else {
            return false;
        }

        $filePath = __DIR__ . '/../../assets/' . $file;
        $realPath = realpath($filePath);
        $assetsDir = realpath(__DIR__ . '/../../assets');

        if (!$realPath || !$assetsDir || !str_starts_with($realPath, $assetsDir)) {
            return false;
        }

        if (!file_exists($realPath) || !is_file($realPath)) {
            return false;
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

        // Send headers and file content
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Access-Control-Allow-Origin: *');

        readfile($realPath);

        return true;
    }
}
