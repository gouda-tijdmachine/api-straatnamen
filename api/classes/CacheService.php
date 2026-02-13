<?php

declare(strict_types=1);

class CacheService
{
    private $redis = null;
    private $local = true;

    public function __construct()
    {
        if (!CACHE_ENABLED) {
            return;
        }

        $this->redis = new Redis();

        $redisUrl = getenv('REDIS_URL');
        $host = '127.0.0.1';
        $port = 6379;
        $user = null;
        $pass = null;
        $useTls = false;

        if (!empty($redisUrl)) {
            $this->local = false;

            $parsed = parse_url($redisUrl);
            $host = $parsed['host'] ?? $host;
            $port = (int)($parsed['port'] ?? $port);
            $user = $parsed['user'] ?? null;
            $pass = $parsed['pass'] ?? null;

            $scheme = strtolower($parsed['scheme'] ?? '');
            $useTls = ($scheme === 'rediss' || $scheme === 'tls');
        }

        try {
            $connectHost = $useTls ? ("tls://" . $host) : $host;

            $timeout = 2.5;
            $readTimeout = 2.5;

            $this->redis->connect($connectHost, $port, $timeout);

            if ($readTimeout !== null) {
                $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
            }

            if (!empty($pass)) {
                if (!empty($user)) {
                    $this->redis->auth([$user, $pass]);
                } else {
                    $this->redis->auth($pass);
                }
            }

        } catch (Throwable $e) {
            echo "Connection failed: " . $e->getMessage();
        }
    }

    public function get($key)
    {
        if (isset($_GET['no_cache']) || !CACHE_ENABLED) {
            return null;
        }
        try {
            $value = $this->redis->get("API-VIEWER:$key");

            return $value === false ? null : $value;
        } catch (Exception $e) {
            error_log("Local Redis Get Error: " . $e->getMessage());

            return null;
        }
    }

    public function put($key, $value, $seconds = 3600)
    {
        if (!CACHE_ENABLED) {
            return null;
        }

        try {
            return $this->redis->setex("API-VIEWER:$key", $seconds, $value);
        } catch (Exception $e) {
            error_log("Local Redis Put Error: " . $e->getMessage());

            return false;
        }
    }

    public function clear_cache()
    {
        $prefix = "API-STRAAT:*";

        $it = null;
        $deleted = 0;

        do {
            $keys = $this->redis->scan($it, $prefix, 1000);
            if ($keys !== false && !empty($keys)) {
                $deleted += $this->redis->del($keys);
            }
        } while ($it > 0);

        return $deleted;
    }
}
