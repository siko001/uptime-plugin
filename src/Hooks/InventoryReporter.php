<?php

declare(strict_types=1);

namespace ATX\UptimeMonitor\Hooks;

use ATX\UptimeMonitor\Http\WebhookClient;

final class InventoryReporter
{
    public const CRON_HOOK = 'atx_uptime_monitor_push_inventory';

    private bool $upgradeInProgress = false;

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'push']);

        add_filter('upgrader_pre_install', [$this, 'markUpgradeInProgress'], 1, 2);
        add_action('activated_plugin', [$this, 'pushUnlessUpgradeInProgress'], 10, 0);
        add_action('deactivated_plugin', [$this, 'pushUnlessUpgradeInProgress'], 10, 0);
        add_action('deleted_plugin', [$this, 'push']);
        add_action('upgrader_process_complete', [$this, 'pushAfterUpgrade'], 100, 2);

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

    public function markUpgradeInProgress(mixed $return, array $hookExtra): mixed
    {
        if (in_array($hookExtra['type'] ?? null, ['plugin', 'theme', 'core'], true)) {
            $this->upgradeInProgress = true;
        }

        return $return;
    }

    public function pushUnlessUpgradeInProgress(): void
    {
        if ($this->upgradeInProgress) {
            return;
        }

        $this->push();
    }

    public function pushAfterUpgrade(mixed $upgrader = null, array $hookExtra = []): void
    {
        $this->push();
        $this->upgradeInProgress = false;
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
        $pluginUpdates = $this->pluginUpdates();
        $themeUpdates = $this->themeUpdates();
        $coreUpdate = $this->coreUpdateVersion();

        $items = [];

        foreach (get_plugins() as $pluginFile => $data) {
            $availableVersion = $pluginUpdates[$pluginFile] ?? '';
            $installedVersion = (string) ($data['Version'] ?? '');
            $updateAvailable = $this->isNewerVersion($availableVersion, $installedVersion);
            $items[] = [
                'name'    => (string) ($data['Name'] ?? $pluginFile),
                'slug'    => dirname((string) $pluginFile),
                'version' => $installedVersion,
                'update_available' => $updateAvailable,
                'available_version' => $updateAvailable ? $availableVersion : '',
                'type'    => 'plugin',
                'active'  => isset($activeMap[$pluginFile]) || $this->isNetworkActivePlugin((string) $pluginFile),
            ];
        }

        global $wp_version;
        if (! empty($wp_version)) {
            $coreUpdateAvailable = $this->isNewerVersion($coreUpdate, (string) $wp_version);
            $items[] = [
                'name'    => 'WordPress Core',
                'slug'    => 'wordpress',
                'version' => (string) $wp_version,
                'update_available' => $coreUpdateAvailable,
                'available_version' => $coreUpdateAvailable ? $coreUpdate : '',
                'type'    => 'core',
                'active'  => true,
            ];
        }

        $current = wp_get_theme();
        if ($current && $current->exists()) {
            $stylesheet = (string) $current->get_stylesheet();
            $availableVersion = $themeUpdates[$stylesheet] ?? '';
            $installedVersion = (string) $current->get('Version');
            $updateAvailable = $this->isNewerVersion($availableVersion, $installedVersion);
            $items[] = [
                'name'    => (string) $current->get('Name'),
                'slug'    => $stylesheet,
                'version' => $installedVersion,
                'update_available' => $updateAvailable,
                'available_version' => $updateAvailable ? $availableVersion : '',
                'type'    => 'theme',
                'active'  => true,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function pluginUpdates(): array
    {
        if (! function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_update_plugins();

        $updates = [];
        foreach (get_plugin_updates() as $pluginFile => $update) {
            $version = (string) ($update->update->new_version ?? '');
            if ($version !== '') {
                $updates[(string) $pluginFile] = $version;
            }
        }

        return $updates;
    }

    /**
     * @return array<string, string>
     */
    private function themeUpdates(): array
    {
        if (! function_exists('get_theme_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_update_themes();

        $updates = [];
        foreach (get_theme_updates() as $stylesheet => $update) {
            $version = (string) ($update->update['new_version'] ?? '');
            if ($version !== '') {
                $updates[(string) $stylesheet] = $version;
            }
        }

        return $updates;
    }

    private function coreUpdateVersion(): string
    {
        if (! function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_version_check();

        foreach (get_core_updates() as $update) {
            $version = (string) ($update->current ?? '');
            if ($version !== '' && in_array($update->response ?? '', ['upgrade', 'latest'], true)) {
                return $version;
            }
        }

        return '';
    }

    private function isNewerVersion(string $availableVersion, string $installedVersion): bool
    {
        return $availableVersion !== ''
            && $installedVersion !== ''
            && version_compare($availableVersion, $installedVersion, '>');
    }

    private function isNetworkActivePlugin(string $pluginFile): bool
    {
        return function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network($pluginFile);
    }
}
