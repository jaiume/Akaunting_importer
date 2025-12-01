<?php

namespace App\Services;

class ConfigService
{
    private static $config = null;

    public static function get($key, $default = null)
    {
        if (self::$config === null) {
            self::$config = parse_ini_file(BASE_DIR . '/config/config.ini', true);
        }

        if (strpos($key, '.') !== false) {
            list($section, $name) = explode('.', $key, 2);
            //if value is a string and is "true" or "false" convert to boolean
            if (is_string(self::$config[$section][$name]) && (self::$config[$section][$name] === 'true' || self::$config[$section][$name] === 'false')) {
                return self::$config[$section][$name] === 'true';
            }
            return self::$config[$section][$name] ?? $default;
        }

        // Search all sections if no section specified
        foreach (self::$config as $section) {
            if (isset($section[$key])) {
                return $section[$key];
            }
        }

        return $default;
    }
}

