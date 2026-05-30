<?php

declare(strict_types=1);

namespace ATX\UptimeMonitor\Support;

final class GitHubPluginUpdater
{
    private string $lastError = '';

    public function __construct(
        private readonly string $pluginFile,
        private readonly string $pluginDir
    ) {
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginInfo'], 20, 3);
        add_filter('plugin_action_links_'.$this->pluginBasename(), [$this, 'pluginActionLinks']);
        add_action('admin_post_'.$this->manualCheckAction(), [$this, 'handleManualUpdateCheck']);
        add_action('admin_notices', [$this, 'manualUpdateNotice']);
        add_action('admin_init', [$this, 'normalizeStoredUpdateTransient']);
    }

    public function injectUpdate(object $transient): object
    {
        $transient->checked = is_array($transient->checked ?? null) ? $transient->checked : [];
        $transient->response = is_array($transient->response ?? null) ? $transient->response : [];
        $transient->no_update = is_array($transient->no_update ?? null) ? $transient->no_update : [];

        // Some updaters return entries without ->plugin, which triggers WP list warnings.
        foreach ($transient->response as $pluginFile => $payload) {
            if (is_object($payload) && ! isset($payload->plugin)) {
                $payload->plugin = (string) $pluginFile;
            }
        }
        foreach ($transient->no_update as $pluginFile => $payload) {
            if (is_object($payload) && ! isset($payload->plugin)) {
                $payload->plugin = (string) $pluginFile;
            }
        }

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
                'error' => $this->lastError !== ''
                    ? $this->lastError
                    : sprintf('Could not fetch a valid %s release from GitHub.', $this->config('name')),
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
     * @param  array<string, string>  $links
     * @return array<string, string>
     */
    public function pluginActionLinks(array $links): array
    {
        if (! current_user_can('manage_options')) {
            return $links;
        }

        $checkLink = sprintf(
            '<a href="%s">%s</a>',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action='.$this->manualCheckAction()), $this->manualCheckNonceAction())),
            esc_html('Check for updates')
        );

        $updatedLinks = [];
        $inserted = false;

        foreach ($links as $key => $link) {
            $updatedLinks[$key] = $link;

            if ($key === 'deactivate') {
                $updatedLinks['github_plugin_updater_check'] = $checkLink;
                $inserted = true;
            }
        }

        if (! $inserted) {
            $updatedLinks['github_plugin_updater_check'] = $checkLink;
        }

        return $updatedLinks;
    }

    public function handleManualUpdateCheck(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die(esc_html('Sorry, you are not allowed to update plugins.'));
        }

        check_admin_referer($this->manualCheckNonceAction());

        $result = $this->checkForUpdate();
        $status = 'error';

        if ($result['ok']) {
            $status = $result['update_available'] ? 'update_available' : 'current';
        }

        $redirectArgs = [
            'github_plugin_updater_checked' => $this->config('slug'),
            'github_plugin_updater_status' => $status,
        ];

        if (! $result['ok'] && ! empty($result['error'])) {
            $redirectArgs['github_plugin_updater_error'] = $result['error'];
        }

        wp_safe_redirect(add_query_arg($redirectArgs, admin_url('plugins.php')));
        exit;
    }

    public function manualUpdateNotice(): void
    {
        $checked = isset($_GET['github_plugin_updater_checked'])
            ? sanitize_text_field(wp_unslash($_GET['github_plugin_updater_checked']))
            : '';

        if ($checked !== $this->config('slug')) {
            return;
        }

        $status = isset($_GET['github_plugin_updater_status'])
            ? sanitize_key(wp_unslash($_GET['github_plugin_updater_status']))
            : '';

        $class = 'notice notice-success is-dismissible';
        $message = sprintf('%s is up to date.', $this->config('name'));

        if ($status === 'update_available') {
            $message = sprintf('An update is available for %s.', $this->config('name'));
        } elseif ($status === 'error') {
            $class = 'notice notice-error is-dismissible';
            $message = isset($_GET['github_plugin_updater_error'])
                ? sanitize_text_field(wp_unslash($_GET['github_plugin_updater_error']))
                : sprintf('Could not check updates for %s.', $this->config('name'));
        }

        printf(
            '<div class="%s"><p>%s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    public function normalizeStoredUpdateTransient(): void
    {
        $transient = get_site_transient('update_plugins');

        if (! is_object($transient)) {
            return;
        }

        $changed = false;
        $transient->response = is_array($transient->response ?? null) ? $transient->response : [];
        $transient->no_update = is_array($transient->no_update ?? null) ? $transient->no_update : [];

        foreach (['response', 'no_update'] as $bucket) {
            foreach ($transient->{$bucket} as $pluginFile => $payload) {
                if (is_object($payload) && ! isset($payload->plugin)) {
                    $payload->plugin = (string) $pluginFile;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            set_site_transient('update_plugins', $transient);
        }
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

    /**
     * @return array{version: string, package: string, notes: string, tested: string}|null
     */
    private function latestRelease(bool $forceRefresh = false): ?array
    {
        $this->lastError = '';

        $cached = get_site_transient($this->config('cache_key'));
        if (! $forceRefresh && is_array($cached)) {
            return $cached;
        }

        if ($this->config('owner') === '' || $this->config('repo') === '' || $this->config('zip_asset') === '') {
            $this->lastError = sprintf(
                'Updater config is incomplete for %s. Check owner, repo, and zip_asset.',
                $this->config('name') ?: 'this plugin'
            );
            set_site_transient($this->config('cache_key'), null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $response = wp_remote_get($this->apiUrl(), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => $this->config('user_agent'),
            ],
        ]);

        if (is_wp_error($response)) {
            $this->lastError = sprintf('GitHub request failed for %s: %s', $this->config('name'), $response->get_error_message());

            return $this->latestReleaseFromRedirect();
        }

        $responseCode = (int) wp_remote_retrieve_response_code($response);

        if ($responseCode !== 200) {
            $this->lastError = sprintf('GitHub request failed for %s with HTTP %d.', $this->config('name'), $responseCode);

            return $this->latestReleaseFromRedirect();
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $selected = $this->selectReleasePayload($data);
        if (! is_array($selected) || empty($selected['tag_name'])) {
            $this->lastError = sprintf('GitHub returned an invalid release response for %s.', $this->config('name'));
            set_site_transient($this->config('cache_key'), null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $package = $this->assetDownloadUrl($selected);

        if ($package === '') {
            $assetNames = array_map(
                static fn ($asset): string => is_array($asset) ? (string) ($asset['name'] ?? '') : '',
                is_array($selected['assets'] ?? null) ? $selected['assets'] : []
            );
            $assetList = implode(', ', array_filter($assetNames));
            $this->lastError = sprintf(
                'GitHub release %s was found, but asset %s was missing. Available assets: %s',
                (string) $selected['tag_name'],
                $this->config('zip_asset'),
                $assetList !== '' ? $assetList : 'none'
            );
        }

        $release = [
            'version' => ltrim((string) $selected['tag_name'], 'vV'),
            'package' => $package,
            'notes' => (string) ($selected['body'] ?? ''),
            'tested' => (string) ($selected['tested'] ?? ''),
        ];

        set_site_transient($this->config('cache_key'), $release, $package === '' ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);

        return $release;
    }

    /**
     * @return array{version: string, package: string, notes: string, tested: string}|null
     */
    private function latestReleaseFromRedirect(): ?array
    {
        $apiError = $this->lastError;
        $response = wp_remote_head($this->repoUrl().'/releases/latest', [
            'timeout' => 10,
            'redirection' => 0,
            'headers' => [
                'User-Agent' => $this->config('user_agent'),
            ],
        ]);

        if (is_wp_error($response)) {
            $this->lastError = $apiError.' Fallback failed: '.$response->get_error_message();
            set_site_transient($this->config('cache_key'), null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $location = $this->headerValue($response, 'location');

        if ($location === '') {
            $response = wp_remote_get($this->repoUrl().'/releases/latest', [
                'timeout' => 10,
                'redirection' => 5,
                'headers' => [
                    'User-Agent' => $this->config('user_agent'),
                ],
            ]);

            if (! is_wp_error($response)) {
                $location = $this->responseUrl($response);

                if ($location === '') {
                    $location = $this->headerValue($response, 'location');
                }
            }
        }

        $tag = $this->tagFromReleaseUrl($location);

        if ($tag === '') {
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $this->lastError = sprintf(
                '%s Fallback could not resolve latest release redirect. HTTP %d. Location: %s',
                $apiError,
                $code,
                $location !== '' ? $location : 'none'
            );
            set_site_transient($this->config('cache_key'), null, 5 * MINUTE_IN_SECONDS);

            return null;
        }

        $release = [
            'version' => ltrim($tag, 'vV'),
            'package' => $this->repoUrl().'/releases/download/'.$tag.'/'.$this->config('zip_asset'),
            'notes' => '',
            'tested' => '',
        ];

        set_site_transient($this->config('cache_key'), $release, 6 * HOUR_IN_SECONDS);

        return $release;
    }

    private function tagFromReleaseUrl(string $url): string
    {
        $parts = wp_parse_url(trim($url));
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : $url;
        $marker = '/releases/tag/';
        $position = strpos($path, $marker);

        if ($position === false) {
            return '';
        }

        $tag = substr($path, $position + strlen($marker));
        $tag = strtok($tag, "?#/");

        return is_string($tag) ? rawurldecode($tag) : '';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function headerValue(array $response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);

        if (is_array($value)) {
            $value = end($value);
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function responseUrl(array $response): string
    {
        $httpResponse = $response['http_response'] ?? null;

        if (! is_object($httpResponse) || ! method_exists($httpResponse, 'get_response_object')) {
            return '';
        }

        $responseObject = $httpResponse->get_response_object();

        return is_object($responseObject) && isset($responseObject->url)
            ? (string) $responseObject->url
            : '';
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

        $data = get_plugin_data($this->pluginFile, false, false);

        return (string) ($data['Version'] ?? '0.0.0');
    }

    private function pluginBasename(): string
    {
        return plugin_basename($this->pluginFile);
    }

    private function apiUrl(): string
    {
        return 'https://api.github.com/repos/'.$this->config('owner').'/'.$this->config('repo').'/releases?per_page=30';
    }

    /**
     * Select the highest semantic version release from a GitHub releases response.
     *
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function selectReleasePayload(mixed $payload): ?array
    {
        if (is_array($payload) && isset($payload['tag_name'])) {
            return $payload;
        }

        if (! is_array($payload) || ! array_is_list($payload)) {
            return null;
        }

        $best = null;
        $bestVersion = null;

        foreach ($payload as $release) {
            if (! is_array($release)) {
                continue;
            }

            if (! empty($release['draft']) || ! empty($release['prerelease'])) {
                continue;
            }

            $tag = (string) ($release['tag_name'] ?? '');
            if ($tag === '') {
                continue;
            }

            $version = ltrim($tag, 'vV');
            if (! preg_match('/^\d+\.\d+\.\d+([.-][A-Za-z0-9]+)?$/', $version)) {
                continue;
            }

            if ($bestVersion === null || version_compare($version, $bestVersion, '>')) {
                $best = $release;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    private function repoUrl(): string
    {
        return 'https://github.com/'.$this->config('owner').'/'.$this->config('repo');
    }

    private function manualCheckAction(): string
    {
        return 'github_plugin_updater_check_'.$this->config('slug');
    }

    private function manualCheckNonceAction(): string
    {
        return $this->manualCheckAction().'_'.$this->pluginBasename();
    }

    private function config(string $key): string
    {
        static $config = null;

        if ($config === null) {
            $primary = $this->pluginDir . '/config/plugin-updater.php';
            $fallback = $this->pluginDir . '/config/github-updater.php';
            $configFile = file_exists($primary) ? $primary : $fallback;
            $loaded = file_exists($configFile) ? require $configFile : [];
            $config = is_array($loaded) ? $loaded : [];
        }

        return (string) ($config[$key] ?? '');
    }
}