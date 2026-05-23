<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Hooks;

use Panza\UptimeMonitor\Http\WebhookClient;

final class CliDeleteReporter
{
    /** @var array<int, string> */
    private array $pluginsBefore = [];

    /** @var array<int, string> */
    private array $themesBefore = [];

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        \WP_CLI::add_hook('before_invoke:plugin delete', [$this, 'capturePluginsBefore']);
        \WP_CLI::add_hook('after_invoke:plugin delete', [$this, 'reportDeletedPlugins']);
        \WP_CLI::add_hook('before_invoke:theme delete', [$this, 'captureThemesBefore']);
        \WP_CLI::add_hook('after_invoke:theme delete', [$this, 'reportDeletedThemes']);
    }

    public function capturePluginsBefore(): void
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->pluginsBefore = array_keys(get_plugins());
    }

    public function reportDeletedPlugins(): void
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        wp_cache_delete('plugins', 'plugins');

        $deleted = array_diff($this->pluginsBefore, array_keys(get_plugins()));
        if ($deleted === []) {
            return;
        }

        $items = [];
        foreach ($deleted as $pluginFile) {
            $items[] = [
                'name'   => $pluginFile,
                'slug'   => dirname($pluginFile),
                'type'   => 'plugin',
                'action' => 'delete',
            ];
        }

        $this->client->send($items);
    }

    public function captureThemesBefore(): void
    {
        $this->themesBefore = array_keys(wp_get_themes());
    }

    public function reportDeletedThemes(): void
    {
        wp_clean_themes_cache();

        $deleted = array_diff($this->themesBefore, array_keys(wp_get_themes()));
        if ($deleted === []) {
            return;
        }

        $items = [];
        foreach ($deleted as $stylesheet) {
            $items[] = [
                'name'   => $stylesheet,
                'slug'   => $stylesheet,
                'type'   => 'theme',
                'action' => 'delete',
            ];
        }

        $this->client->send($items);
    }
}
