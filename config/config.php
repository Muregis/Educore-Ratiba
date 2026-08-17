<?php
declare(strict_types=1);

/**
 * Hybrid Configuration System
 * Automatically detects and configures for both online and offline environments
 */

class Config {
    private static array $config = [];
    private static string $environment;
    
    public static function init(): void {
        self::$environment = self::detectEnvironment();
        self::$config = self::loadConfig();
    }
    
    /**
     * Detect if running online or offline
     */
    private static function detectEnvironment(): string {
        // Check if explicitly set via environment variable
        $env = getenv('APP_ENV');
        if ($env !== false) {
            return $env;
        }
        
        // Auto-detect based on server software and OS
        $isOnline = self::isOnlineEnvironment();
        
        return $isOnline ? 'online' : 'offline';
    }
    
    /**
     * Determine if running in online environment
     */
    private static function isOnlineEnvironment(): bool {
        // Check for common web server indicators
        $isWebServer = isset($_SERVER['HTTP_HOST']) || isset($_SERVER['SERVER_NAME']);
        
        // Check if database host is not localhost (indicates remote DB)
        $dbHost = getenv('DB_HOST');
        $isRemoteDb = $dbHost && $dbHost !== 'localhost' && $dbHost !== '127.0.0.1';
        
        // Check if explicitly configured for online via environment
        $explicitOnline = getenv('APP_ENV') === 'online';
        
        return $isWebServer && ($isRemoteDb || $explicitOnline);
    }
    
    /**
     * Load configuration based on environment
     */
    private static function loadConfig(): array {
        $baseDir = dirname(__DIR__);
        
        return [
            'environment' => self::$environment,
            'paths' => [
                'base' => $baseDir,
                'engine' => self::getEnginePath($baseDir),
                'output' => self::getOutputPath($baseDir),
                'data' => $baseDir . '/data',
                'temp' => sys_get_temp_dir(),
            ],
            'database' => [
                'driver' => getenv('DB_DRIVER') ?: ($baseDir === 'C:/laragon/www/fet-timetable/Educore Ratiba' ? 'mysql' : 'mysql'), // 'mysql' or 'pgsql'
                'host' => getenv('DB_HOST') ?: ($baseDir === 'C:/laragon/www/fet-timetable/Educore Ratiba' ? 'localhost' : 'localhost'),
                'port' => getenv('DB_PORT') ?: '',
                'name' => getenv('DB_NAME') ?: 'fet_timetable',
                'user' => getenv('DB_USER') ?: 'root',
                'pass' => getenv('DB_PASS') ?: '',
            ],
            'security' => [
                'sso_secret' => getenv('SSO_SHARED_SECRET') ?: '',
                'central_server' => getenv('CENTRAL_SERVER_URL') ?: '',
                'sync_token' => getenv('LOCAL_SYNC_TOKEN') ?: '',
            ],
            'sync' => [
                'enabled' => self::$environment === 'offline',
                'auto_sync' => getenv('AUTO_SYNC') === 'true',
                'sync_interval' => (int) (getenv('SYNC_INTERVAL') ?: 3600), // 1 hour default
            ],
        ];
    }
    
    /**
     * Get FET engine path based on OS and environment
     */
    private static function getEnginePath(string $baseDir): string {
        // Check if explicitly set via environment
        $envPath = getenv('FET_ENGINE_PATH');
        if ($envPath !== false && file_exists($envPath)) {
            return $envPath;
        }
        
        // Auto-detect based on OS
        if (PHP_OS_FAMILY === 'Windows') {
            $windowsPath = $baseDir . '/engine/fet-cl.exe';
            if (file_exists($windowsPath)) {
                return $windowsPath;
            }
            // Fallback for Laragon structure
            $laragonPath = 'C:/laragon/www/fet-timetable/engine/fet-cl.exe';
            if (file_exists($laragonPath)) {
                return $laragonPath;
            }
        } else {
            // Linux/Unix
            $linuxPath = $baseDir . '/engine/fet-cl';
            if (file_exists($linuxPath)) {
                return $linuxPath;
            }
        }
        
        // Return default path (will need to be configured)
        return PHP_OS_FAMILY === 'Windows' 
            ? $baseDir . '/engine/fet-cl.exe' 
            : $baseDir . '/engine/fet-cl';
    }
    
    /**
     * Get output directory path
     */
    private static function getOutputPath(string $baseDir): string {
        $envPath = getenv('OUTPUT_DIR');
        if ($envPath !== false) {
            return $envPath;
        }
        
        return $baseDir . '/output';
    }
    
    /**
     * Get configuration value
     */
    public static function get(string $key, mixed $default = null): mixed {
        if (empty(self::$config)) {
            self::init();
        }
        
        $keys = explode('.', $key);
        $value = self::$config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Check if running in online mode
     */
    public static function isOnline(): bool {
        if (empty(self::$config)) {
            self::init();
        }
        return self::$environment === 'online';
    }
    
    /**
     * Check if running in offline mode
     */
    public static function isOffline(): bool {
        return !self::isOnline();
    }
    
    /**
     * Get current environment
     */
    public static function getEnvironment(): string {
        if (empty(self::$config)) {
            self::init();
        }
        return self::$environment;
    }
    
    /**
     * Get all configuration
     */
    public static function all(): array {
        if (empty(self::$config)) {
            self::init();
        }
        return self::$config;
    }
}

// Auto-initialize
Config::init();
