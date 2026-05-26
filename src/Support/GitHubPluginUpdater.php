<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Support;

final class GitHubPluginUpdater
{
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

        $release = $this->latestRelease(true);
        if (! $release || $release['package'] === '') {
            return $transient;
        }

        if (! version_compare($release['version'], $this->installedVersion(), '>')) {
            unset($transient->response[$this->pluginBasename()]);
            $transient->no_update[$this->pluginBasename()] = $this->updatePayload($release);

            return $transient;
        }

        unset($transient->no_update[$this->pluginBasename()]);
        $transient->response[$this->pluginBasename()] = $this->updatePayload($release);

        return $transient;
    }

    /**
     * @return array{ok: bool, installed_version: string, latest_version?: string, update_available?: bool, error?: string}
     */
    public function checkForUpdate(): array
    {
        $installedVersion = $this->installedVersion();
        $release = $this->latestRelease(true);

        if (! $release || $release['package'] === '') {
            return [
                'ok' => false,
                'installed_version' => $installedVersion,
                'error' => sprintf('Could not fetch a valid %s release from GitHub.', $this->config('name')),
            ];
        }

        $updateAvailable = version_compare($release['version'], $installedVersion, '>');
        $this->storePluginUpdate($release, $updateAvailable);

        return [
            'ok' => true,
            'installed_version' => $installedVersion,
            'latest_version' => $release['version'],
            'update_available' => $updateAvailable,
        ];
    }

    /**
     * @param  array{version: string, package: string, notes: string, tested: string}  $release
     */
    private function updatePayload(array $release): object
    {
        return (object) [
            'id' => $this->repoUrl(),
            'slug' => $this->config('slug'),
            'plugin' => $this->pluginBasename(),
            'new_version' => $release['version'],
            'url' => $this->repoUrl(),
            'package' => $release['package'],
            'tested' => $release['tested'],
            'requires_php' => $this->config('requires_php'),
        ];
    }

    /**
     * @param  array{version: string, package: string, notes: string, tested: string}  $release
     */
    private function storePluginUpdate(array $release, bool $updateAvailable): void
    {
        $transient = get_site_transient('update_plugins');

        if (! is_object($transient)) {
            $transient = (object) [
                'last_checked' => time(),
                'checked' => [],
                'response' => [],
                'no_update' => [],
            ];
        }

        $basename = $this->pluginBasename();
        $transient->last_checked = time();
        $transient->checked = is_array($transient->checked ?? null) ? $transient->checked : [];
        $transient->response = is_array($transient->response ?? null) ? $transient->response : [];
        $transient->no_update = is_array($transient->no_update ?? null) ? $transient->no_update : [];
        $transient->checked[$basename] = $this->installedVersion();

        if ($updateAvailable) {
            unset($transient->no_update[$basename]);
            $transient->response[$basename] = $this->updatePayload($release);
        } else {
            unset($transient->response[$basename]);
            $transient->no_update[$basename] = $this->updatePayload($release);
        }

        set_site_transient('update_plugins', $transient);
    }

    public function pluginInfo(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->config('slug')) {
            return $result;
        }

        $release = $this->latestRelease();
        if (! $release) {
            return $result;
        }

        return (object) [
            'name' => $this->config('name'),
            'slug' => $this->config('slug'),
            'version' => $release['version'],
            'author' => '<a href="https://github.com/'.$this->config('owner').'">'.$this->config('author').'</a>',
            'homepage' => $this->repoUrl(),
            'requires_php' => $this->config('requires_php'),
            'tested' => $release['tested'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => $this->config('description'),
                'changelog' => nl2br(esc_html($release['notes'] ?: 'See the GitHub release notes.')),
            ],
        ];
    }

    /**
     * @return array{version: string, package: string, notes: string, tested: string}|null
     */
    private function latestRelease(bool $forceRefresh = false): ?array
    {
        $cached = get_site_transient($this->config('cache_key'));
        if (! $forceRefresh && is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($this->apiUrl(), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => $this->config('user_agent'),
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_site_transient($this->config('cache_key'), null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($data) || empty($data['tag_name'])) {
            set_site_transient($this->config('cache_key'), null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $package = $this->assetDownloadUrl($data);
        $release = [
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'package' => $package,
            'notes' => (string) ($data['body'] ?? ''),
            'tested' => (string) ($data['tested'] ?? ''),
        ];

        set_site_transient($this->config('cache_key'), $release, $package === '' ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);

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

            if (($asset['name'] ?? '') === $this->config('zip_asset') && ! empty($asset['browser_download_url'])) {
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
        return 'https://api.github.com/repos/'.$this->config('owner').'/'.$this->config('repo').'/releases/latest';
    }

    private function repoUrl(): string
    {
        return 'https://github.com/'.$this->config('owner').'/'.$this->config('repo');
    }

    private function config(string $key): string
    {
        static $config = null;

        if ($config === null) {
            $loaded = require PANZA_UPTIME_MONITOR_DIR . '/config/github-updater.php';
            $config = is_array($loaded) ? $loaded : [];
        }

        return (string) ($config[$key] ?? '');
    }
}
