<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Hooks;

use Panza\UptimeMonitor\Http\WebhookClient;
use WP_Upgrader;

final class UpdateReporter
{
    /** @var array<string, string> */
    private array $preUpgradeVersions = [];

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_filter('upgrader_pre_install', [$this, 'capturePreUpgradeVersion'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'onUpgraderComplete'], 10, 2);
    }

    public function capturePreUpgradeVersion(mixed $return, array $hookExtra): mixed
    {
        $type = $hookExtra['type'] ?? null;

        if (! empty($hookExtra['plugin'])) {
            $data = $this->getPluginData((string) $hookExtra['plugin']);
            $this->preUpgradeVersions['plugin:' . $hookExtra['plugin']] = (string) ($data['Version'] ?? '');
        } elseif ($type === 'plugin') {
            $this->snapshotAllPluginVersions();
        }

        if (! empty($hookExtra['theme'])) {
            $theme = wp_get_theme((string) $hookExtra['theme']);
            $this->preUpgradeVersions['theme:' . $hookExtra['theme']] = (string) $theme->get('Version');
        } elseif ($type === 'theme') {
            $this->snapshotAllThemeVersions();
        }

        if ($type === 'core') {
            $this->preUpgradeVersions['core:wordpress'] = $this->loadedCoreVersion();
        }

        return $return;
    }

    public function onUpgraderComplete(WP_Upgrader $upgrader, array $hookExtra): void
    {
        $type   = $hookExtra['type'] ?? null;
        $action = (string) ($hookExtra['action'] ?? 'update');

        $items = match ($type) {
            'plugin' => $this->collectPluginItems($upgrader, $hookExtra, $action),
            'theme'  => $this->collectThemeItems($upgrader, $hookExtra, $action),
            'core'   => $this->collectCoreItems($action),
            default  => [],
        };

        if ($items !== []) {
            $this->client->send($items);
        }
    }

    private function collectPluginItems(WP_Upgrader $upgrader, array $hookExtra, string $action): array
    {
        $plugins = (array) ($hookExtra['plugins'] ?? []);
        if ($plugins === [] && ! empty($hookExtra['plugin'])) {
            $plugins = [$hookExtra['plugin']];
        }
        if ($plugins === [] && method_exists($upgrader, 'plugin_info')) {
            $info = $upgrader->plugin_info();
            if ($info) {
                $plugins = [$info];
            }
        }

        $items = [];

        foreach ($plugins as $pluginFile) {
            $data       = $this->getPluginData((string) $pluginFile);
            $oldVersion = $this->preUpgradeVersions['plugin:' . $pluginFile] ?? '';

            $items[] = [
                'name'        => (string) ($data['Name'] ?? $pluginFile),
                'slug'        => dirname((string) $pluginFile),
                'type'        => 'plugin',
                'action'      => $this->resolveAction($action, $oldVersion),
                'old_version' => $oldVersion,
                'new_version' => (string) ($data['Version'] ?? ''),
            ];
        }

        return $items;
    }

    private function collectThemeItems(WP_Upgrader $upgrader, array $hookExtra, string $action): array
    {
        $themes = (array) ($hookExtra['themes'] ?? []);
        if ($themes === [] && ! empty($hookExtra['theme'])) {
            $themes = [$hookExtra['theme']];
        }
        if ($themes === [] && method_exists($upgrader, 'theme_info')) {
            $info = $upgrader->theme_info();
            if ($info) {
                $themes = [is_object($info) ? $info->get_stylesheet() : (string) $info];
            }
        }

        $items = [];

        foreach ($themes as $stylesheet) {
            $theme      = wp_get_theme((string) $stylesheet);
            $oldVersion = $this->preUpgradeVersions['theme:' . $stylesheet] ?? '';

            $items[] = [
                'name'        => (string) ($theme->get('Name') ?: $stylesheet),
                'slug'        => (string) $stylesheet,
                'type'        => 'theme',
                'action'      => $this->resolveAction($action, $oldVersion),
                'old_version' => $oldVersion,
                'new_version' => (string) $theme->get('Version'),
            ];
        }

        return $items;
    }

    private function collectCoreItems(string $action): array
    {
        $oldVersion = $this->preUpgradeVersions['core:wordpress'] ?? '';
        $newVersion = $this->installedCoreVersion();

        return [[
            'name'        => 'WordPress Core',
            'slug'        => 'wordpress',
            'type'        => 'core',
            'action'      => $this->resolveAction($action, $oldVersion, $newVersion),
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
        ]];
    }

    private function resolveAction(string $action, string $oldVersion, string $newVersion = ''): string
    {
        if ($oldVersion !== '' && $oldVersion === $newVersion) {
            return 'reinstall';
        }

        if ($action === 'install' && $oldVersion !== '') {
            return 'update';
        }

        return $action;
    }

    private function loadedCoreVersion(): string
    {
        global $wp_version;

        return (string) $wp_version;
    }

    private function installedCoreVersion(): string
    {
        $versionFile = ABSPATH . WPINC . '/version.php';

        if (! is_readable($versionFile)) {
            return $this->loadedCoreVersion();
        }

        $contents = (string) file_get_contents($versionFile);

        if (preg_match('/\\$wp_version\\s*=\\s*[\'"]([^\'"]+)[\'"]\\s*;/', $contents, $matches)) {
            return $matches[1];
        }

        return $this->loadedCoreVersion();
    }

    private function snapshotAllPluginVersions(): void
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach (get_plugins() as $pluginFile => $data) {
            $this->preUpgradeVersions['plugin:' . $pluginFile] = (string) ($data['Version'] ?? '');
        }
    }

    private function snapshotAllThemeVersions(): void
    {
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $this->preUpgradeVersions['theme:' . $stylesheet] = (string) $theme->get('Version');
        }
    }

    private function getPluginData(string $pluginFile): array
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $path = WP_PLUGIN_DIR . '/' . $pluginFile;
        return is_file($path) ? get_plugin_data($path, false, false) : [];
    }
}
