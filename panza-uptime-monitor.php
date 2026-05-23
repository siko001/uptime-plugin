<?php
/**
 * Plugin Name:       Panza Uptime Monitor
 * Description:       Manage WordPress plugin, theme, and core updates to the Panza Uptime Monitor app via signed webhook.
 * Version:           1.0.0
 * Requires PHP:      8.1
 * Author:            Panza
 * License:           Proprietary
 * Text Domain:       panza-uptime-monitor
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Panza\\UptimeMonitor\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

define('PANZA_UPTIME_MONITOR_FILE', __FILE__);
define('PANZA_UPTIME_MONITOR_DIR', __DIR__);

add_action('plugins_loaded', static function (): void {
    \Panza\UptimeMonitor\Plugin::boot();
});
