<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Support;

final class GitHubPluginUpdater
{
    private const OWNER = 'siko001';

    private const REPO = 'uptime-plugin';

    private const SLUG = 'panza-uptime-monitor';

    private const ZIP_ASSET = 'panza-uptime-monitor.zip';

    private const CACHE_KEY = 'panza_uptime_monitor_github_release';

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
    }

    public function injectUpdate(object $transient): object
    {
        if (empty($transient->checked) || ! isset($transient->checked[$this->pluginBasename()])) {
            return $transient;
        }

        $release = $this->latestRelease();
        if (! $release || $release['package'] === '') {
            return $transient;
        }

        if (! version_compare($release['version'], $this->installedVersion(), '>')) {
            unset($transient->response[$this->pluginBasename()]);

            return $transient;
        }

        $transient->response[$this->pluginBasename()] = (object) [
            'id' => $this->repoUrl(),
            'slug' => self::SLUG,
            'plugin' => $this->pluginBasename(),
            'new_version' => $release['version'],
            'url' => $this->repoUrl(),
            'package' => $release['package'],
            'tested' => $release['tested'],
            'requires_php' => '8.1',
        ];

        return $transient;
    }

    public function pluginInfo(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $release = $this->latestRelease();
        if (! $release) {
            return $result;
        }

        return (object) [
            'name' => 'Panza Uptime Monitor',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://github.com/'.self::OWNER.'">Panza</a>',
            'homepage' => $this->repoUrl(),
            'requires_php' => '8.1',
            'tested' => $release['tested'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Reports WordPress updates, inventory, users, and accepts signed commands from Panza Uptime Monitor.',
                'changelog' => nl2br(esc_html($release['notes'] ?: 'See the GitHub release notes.')),
            ],
        ];
    }

    /**
     * @return array{version: string, package: string, notes: string, tested: string}|null
     */
    private function latestRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($this->apiUrl(), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'panza-uptime-monitor-updater',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient(self::CACHE_KEY, null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data) || empty($data['tag_name'])) {
            set_site_transient(self::CACHE_KEY, null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $package = $this->assetDownloadUrl($data);
        $release = [
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'package' => $package,
            'notes' => (string) ($data['body'] ?? ''),
            'tested' => (string) ($data['tested'] ?? ''),
        ];

        set_site_transient(self::CACHE_KEY, $release, $package === '' ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function assetDownloadUrl(array $release): string
    {
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            if (($asset['name'] ?? '') === self::ZIP_ASSET && ! empty($asset['browser_download_url'])) {
                return (string) $asset['browser_download_url'];
            }
        }

        return '';
    }

    private function installedVersion(): string
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data(PANZA_UPTIME_MONITOR_FILE, false, false);

        return (string) ($data['Version'] ?? '0.0.0');
    }

    private function pluginBasename(): string
    {
        return plugin_basename(PANZA_UPTIME_MONITOR_FILE);
    }

    private function apiUrl(): string
    {
        return 'https://api.github.com/repos/'.self::OWNER.'/'.self::REPO.'/releases/latest';
    }

    private function repoUrl(): string
    {
        return 'https://github.com/'.self::OWNER.'/'.self::REPO;
    }
}
