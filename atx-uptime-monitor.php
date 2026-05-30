<?php
/**
     * Plugin Name: ATX Uptime Monitor
     * Description: Monitors website uptime and sends notifications via Slack, Report wordpress changes.
     * Version: 2.0.0
     * Author: ATX - Neil VM
     * Author URI: https://neilmallia.com
     * Text Domain: atx-uptime-monitor
     * License: GPL-2.0-or-later
     * License URI: https://www.gnu.org/licenses/gpl-2.0.html
     * Domain Path: /languages
     * Requires at least: 6.0
     * Requires PHP: 8.1
     * 
     * git tag -a v{VERSION}.0.0 -m "Release v{VERSION}.0.0"
     * git push origin v{VERSION}.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'ATX\\UptimeMonitor\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

define('ATX_UPTIME_MONITOR_FILE', __FILE__);
define('ATX_UPTIME_MONITOR_DIR', __DIR__);
define('ATX_UPTIME_MONITOR_VERSION', '2.0.0');


add_action('plugins_loaded', static function (): void {
    \ATX\UptimeMonitor\Plugin::boot();
});




add_action('plugins_loaded', function () {
	if (is_admin() && class_exists('\\ATX\\UptimeMonitor\\Support\\GitHubPluginUpdater')) {
		(new \ATX\UptimeMonitor\Support\GitHubPluginUpdater(__FILE__, __DIR__))->register();
	}
});