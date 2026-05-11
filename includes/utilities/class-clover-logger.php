<?php
/**
 * Clover Logger
 * Custom logging that writes to plugin's own log file
 */

if (!defined('ABSPATH')) {
    exit;
}

class Clover_Logger
{
    private static $enabled = null;
    private static $log_dir;

    private static function init()
    {
        if (self::$log_dir === null) {
            self::$log_dir = CLOVER_PLUGIN_PATH . 'logs';
            if (!file_exists(self::$log_dir)) {
                @mkdir(self::$log_dir, 0755, true);
            } elseif (!is_dir(self::$log_dir)) {
                @unlink(self::$log_dir);
                @mkdir(self::$log_dir, 0755, true);
            }
        }
    }

    public static function is_enabled()
    {
        if (self::$enabled === null) {
            self::$enabled = get_option('clover_enable_logs', '0') === '1';
        }
        return self::$enabled;
    }

    public static function log($message, $level = 'INFO')
    {
        if (!self::is_enabled()) {
            return;
        }

        self::init();

        $today = date('Y-m-d');
        $log_file = self::$log_dir . '/clover-' . $today . '.log';
        $timestamp = date('Y-m-d H:i:s');

        $msg = is_array($message) || is_object($message)
            ? print_r($message, true)
            : $message;

        $entry = "[{$timestamp}] [{$level}] {$msg}\n";

        @file_put_contents($log_file, $entry, FILE_APPEND);

        // Also write to WordPress debug.log if enabled
        if (get_option('clover_log_to_wp_debug', '0') === '1') {
            @error_log("[$level] $msg");
        }
    }

    public static function debug($message)
    {
        self::log($message, 'DEBUG');
    }

    public static function error($message)
    {
        self::log($message, 'ERROR');
    }

    public static function info($message)
    {
        self::log($message, 'INFO');
    }

    public static function get_logs($days = 7)
    {
        self::init();

        $logs = [];
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $log_file = self::$log_dir . '/clover-' . $date . '.log';

            if (file_exists($log_file)) {
                $logs[$date] = file_get_contents($log_file);
            }
        }

        return $logs;
    }

    public static function clear_old_logs($days = 30)
    {
        self::init();

        $files = glob(self::$log_dir . '/clover-*.log');
        $cutoff = strtotime("-{$days} days");

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    public static function clear_current_log()
    {
        self::init();

        $log_file = self::$log_dir . '/clover-' . date('Y-m-d') . '.log';
        if (file_exists($log_file)) {
            @unlink($log_file);
        }
    }
}

/**
 * Helper function for plugin logging
 * Use this instead of error_log() when clover_enable_logs is on
 */
function clover_log($message, $level = 'INFO')
{
    if (class_exists('Clover_Logger')) {
        Clover_Logger::log($message, $level);
    }
}