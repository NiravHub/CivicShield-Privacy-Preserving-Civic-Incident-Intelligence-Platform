<?php
declare(strict_types=1);

/**
 * CivicShield - Lightweight Environment Variable Loader
 * Reads .env files into $_ENV and getenv() without external dependencies.
 */
class Env
{
    private static bool $loaded = false;

    /**
     * Load environment variables from the given file path.
     *
     * @param string $path Path to the .env file.
     * @return bool True if loaded or already loaded, false if file does not exist.
     */
    public static function load(string $path): bool
    {
        if (self::$loaded) {
            return true;
        }

        if (!file_exists($path) || !is_readable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split by the first '=' delimiter
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Strip enclosing quotes (single or double)
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Normalize boolean and null representations
            $lowered = strtolower($value);
            if ($lowered === 'true') {
                $parsedValue = true;
            } elseif ($lowered === 'false') {
                $parsedValue = false;
            } elseif ($lowered === 'null') {
                $parsedValue = null;
            } else {
                $parsedValue = $value;
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $parsedValue;
                $_SERVER[$key] = $parsedValue;
                if (is_scalar($parsedValue)) {
                    putenv(sprintf('%s=%s', $key, (string)$parsedValue));
                }
            }
        }

        self::$loaded = true;
        return true;
    }

    /**
     * Retrieve an environment variable with a fallback default.
     *
     * @param string $key Variable name.
     * @param mixed $default Fallback value if key is not found.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        $val = getenv($key);
        if ($val !== false) {
            return $val;
        }

        return $default;
    }
}
