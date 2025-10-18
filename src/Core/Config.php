<?php

namespace PriceGrabber\Core;

class Config
{
    private static $config = [];
    private static $loaded = false;

    public static function load($envFile = '.env')
    {
        if (self::$loaded) {
            return;
        }

        $envPath = dirname(__DIR__, 2) . '/' . $envFile;

        if (!file_exists($envPath)) {
            throw new \Exception(".env file not found at: {$envPath}");
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                $value = trim($value, '"\'');

                self::$config[$key] = $value;

                // Also set as environment variable
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config[$key] ?? $default;
    }

    public static function all()
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$config;
    }
}
