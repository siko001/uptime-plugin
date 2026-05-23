<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Hooks;

use Panza\UptimeMonitor\Http\WebhookClient;

final class InventoryReporter
{
    public const CRON_HOOK = 'panza_uptime_monitor_push_inventory';

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'push']);

        add_action('activated_plugin', [$this, 'push']);
        add_action('deactivated_plugin', [$this, 'push']);
        add_action('deleted_plugin', [$this, 'push']);
        add_action('upgrader_process_complete', [$this, 'push']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'daily', self::CRON_HOOK);
        }
    }

    public static function unregister(): void
    {
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    public function push(): void
    {
        $this->client->sendInventory($this->collect());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = (array) get_option('active_plugins', []);
        $activeMap = array_flip(array_map('strval', $active));

        $items = [];

        foreach (get_plugins() as $pluginFile => $data) {
            $items[] = [
                'name'    => (string) ($data['Name'] ?? $pluginFile),
                'slug'    => dirname((string) $pluginFile),
                'version' => (string) ($data['Version'] ?? ''),
                'type'    => 'plugin',
                'active'  => isset($activeMap[$pluginFile]),
            ];
        }

        global $wp_version;
        if (! empty($wp_version)) {
            $items[] = [
                'name'    => 'WordPress Core',
                'slug'    => 'wordpress',
                'version' => (string) $wp_version,
                'type'    => 'core',
                'active'  => true,
            ];
        }

        $current = wp_get_theme();
        if ($current && $current->exists()) {
            $items[] = [
                'name'    => (string) $current->get('Name'),
                'slug'    => (string) $current->get_stylesheet(),
                'version' => (string) $current->get('Version'),
                'type'    => 'theme',
                'active'  => true,
            ];
        }

        return $items;
    }
}
