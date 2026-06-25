<?php

declare(strict_types=1);

namespace ATX\UptimeMonitor\Http;

use ATX\UptimeMonitor\Support\Options;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CommandEndpoint
{
    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('atx-uptime-monitor/v1', '/command', [
                'methods' => 'POST',
                'permission_callback' => [$this, 'verify'],
                'callback' => [$this, 'handle'],
            ]);
        });
    }

    public function verify(WP_REST_Request $request): bool|WP_Error
    {
        $secret = Options::getSecret();
        if ($secret === '') {
            return new WP_Error('atx_uptime_monitor_unconfigured', 'Command secret is not configured.', ['status' => 403]);
        }

        $timestamp = (string) $request->get_header('x-timestamp');
        $signature = (string) $request->get_header('x-signature');

        if ($timestamp === '' || $signature === '') {
            return new WP_Error('atx_uptime_monitor_missing_signature', 'Missing signature headers.', ['status' => 401]);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('atx_uptime_monitor_bad_timestamp', 'Timestamp is outside the allowed window.', ['status' => 401]);
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->get_body(), $secret);
        if (! hash_equals($expected, $signature)) {
            return new WP_Error('atx_uptime_monitor_bad_signature', 'Signature mismatch.', ['status' => 401]);
        }

        return true;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = json_decode($request->get_body(), true);
        if (! is_array($body)) {
            return $this->error('Invalid JSON body.', 422);
        }

        $command = (string) ($body['command'] ?? '');
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

        $result = match ($command) {
            'install_plugin' => $this->installPlugin($payload),
            'update_plugin' => $this->updatePlugin($payload),
            'activate_plugin' => $this->activatePlugin((string) ($payload['slug'] ?? '')),
            'deactivate_plugin' => $this->deactivatePlugin((string) ($payload['slug'] ?? '')),
            'delete_plugin' => $this->deletePlugin((string) ($payload['slug'] ?? '')),
            'update_all_plugins' => $this->updateAllPlugins(),
            'update_theme' => $this->updateTheme($payload),
            'delete_theme' => $this->deleteTheme((string) ($payload['slug'] ?? '')),
            'update_all_themes' => $this->updateAllThemes(),
            'update_core' => $this->updateCore(false),
            'reinstall_core' => $this->updateCore(true),
            'create_user' => $this->createUser($payload),
            'update_user' => $this->updateUser($payload),
            'delete_user' => $this->deleteUser($payload),
            'send_password_reset' => $this->sendPasswordReset($payload),
            'sync_inventory' => $this->syncInventory(),
            default => ['ok' => false, 'message' => "Unknown command: {$command}"],
        };

        if (($result['ok'] ?? false) === true && in_array($command, [
            'install_plugin',
            'update_plugin',
            'activate_plugin',
            'deactivate_plugin',
            'delete_plugin',
            'update_all_plugins',
            'update_theme',
            'delete_theme',
            'update_all_themes',
            'update_core',
            'reinstall_core',
        ], true)) {
            $this->pushInventory();
        }

        if (($result['ok'] ?? false) === true && in_array($command, [
            'create_user',
            'update_user',
            'delete_user',
        ], true)) {
            $this->pushUsers();
        }

        return new WP_REST_Response($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    private function updatePlugin(array|string $payload): array
    {
        $slug = is_array($payload) ? (string) ($payload['slug'] ?? '') : $payload;
        $targetVersion = is_array($payload) ? trim((string) ($payload['available_version'] ?? '')) : '';
        $activateAfterUpdate = is_array($payload) && ! empty($payload['activate_after_update']);
        $plugin = $this->resolvePluginFile($slug);
        if ($plugin === null) {
            return ['ok' => false, 'message' => "Plugin not found for slug {$slug}."];
        }

        $activationState = $this->pluginActivationState($plugin);
        if ($activationState === null && $activateAfterUpdate) {
            $activationState = 'site';
        }

        $this->loadUpgradeFiles();
        $this->refreshPluginUpdates();

        $updates = get_plugin_updates();
        if (! isset($updates[$plugin])) {
            $installedVersion = $this->pluginVersion($plugin);

            if ($this->versionSatisfiesTarget($installedVersion, $targetVersion)) {
                return ['ok' => true, 'message' => "Plugin {$slug} is already at {$installedVersion}."];
            }

            return ['ok' => false, 'message' => "No WordPress update is currently available for plugin {$slug}."];
        }

        $result = (new \Plugin_Upgrader(new \Automatic_Upgrader_Skin()))->upgrade($plugin);

        if ($result === false || $result === null) {
            $installedVersion = $this->pluginVersion($plugin);

            if ($this->versionSatisfiesTarget($installedVersion, $targetVersion)) {
                $reactivationError = $this->restorePluginActivation($plugin, $slug, $activationState);
                if ($reactivationError !== null) {
                    return $reactivationError;
                }

                return ['ok' => true, 'message' => "Plugin {$slug} is already at {$installedVersion}."];
            }
        }

        $reactivationError = $this->restorePluginActivation($plugin, $slug, $activationState);
        if ($reactivationError !== null) {
            return $reactivationError;
        }

        return $this->upgradeResult($result, "Plugin {$slug} updated.");
    }

    private function installPlugin(array $payload): array
    {
        $slug = sanitize_key((string) ($payload['slug'] ?? ''));
        $zipUrl = esc_url_raw((string) ($payload['zip_url'] ?? ''));
        $zipPackage = (string) ($payload['zip_package'] ?? '');
        $zipName = sanitize_file_name((string) ($payload['zip_name'] ?? 'uploaded-plugin.zip'));
        $activate = ! empty($payload['activate']);
        $temporaryPackage = null;

        if ($slug === '' && $zipUrl === '' && $zipPackage === '') {
            return ['ok' => false, 'message' => 'Plugin slug, ZIP URL, or uploaded ZIP is required.'];
        }

        $source = $zipUrl;
        if ($zipPackage !== '') {
            $decodedPackage = base64_decode($zipPackage, true);
            if (! is_string($decodedPackage) || $decodedPackage === '') {
                return ['ok' => false, 'message' => 'Uploaded plugin ZIP payload is invalid.'];
            }

            $temporaryPackage = trailingslashit(get_temp_dir()).wp_unique_filename(get_temp_dir(), $zipName ?: 'uploaded-plugin.zip');
            if (file_put_contents($temporaryPackage, $decodedPackage) === false) {
                return ['ok' => false, 'message' => 'Could not write uploaded plugin ZIP to a temporary file.'];
            }

            $source = $temporaryPackage;
        } elseif ($slug !== '') {
            if (! function_exists('plugins_api')) {
                require_once ABSPATH.'wp-admin/includes/plugin-install.php';
            }

            $plugin = plugins_api('plugin_information', [
                'slug' => $slug,
                'fields' => [
                    'sections' => false,
                ],
            ]);

            if (is_wp_error($plugin)) {
                return ['ok' => false, 'message' => $plugin->get_error_message()];
            }

            $source = (string) ($plugin->download_link ?? '');
        }

        if ($source === '') {
            return ['ok' => false, 'message' => 'Could not resolve a plugin download URL.'];
        }

        $this->loadUpgradeFiles();
        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result = $upgrader->install($source);
        if ($temporaryPackage !== null && file_exists($temporaryPackage)) {
            unlink($temporaryPackage);
        }

        if (is_wp_error($result) || $result === false || $result === null) {
            return $this->upgradeResult($result, 'Plugin installed.');
        }

        $pluginFile = method_exists($upgrader, 'plugin_info') ? $upgrader->plugin_info() : null;
        if (! is_string($pluginFile) || $pluginFile === '') {
            $pluginFile = $slug !== '' ? $this->resolvePluginFile($slug) : null;
        }

        if ($activate && is_string($pluginFile) && $pluginFile !== '') {
            $activation = activate_plugin($pluginFile);
            if (is_wp_error($activation)) {
                return ['ok' => false, 'message' => 'Plugin installed but activation failed: '.$activation->get_error_message()];
            }
        }

        if ($activate && (! is_string($pluginFile) || $pluginFile === '')) {
            return ['ok' => false, 'message' => 'Plugin installed but could not determine the plugin file to activate.'];
        }

        return ['ok' => true, 'message' => $activate ? 'Plugin installed and activated.' : 'Plugin installed.'];
    }

    private function deletePlugin(string $slug): array
    {
        $plugin = $this->resolvePluginFile($slug);
        if ($plugin === null) {
            return ['ok' => false, 'message' => "Plugin not found for slug {$slug}."];
        }

        if (! function_exists('delete_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        deactivate_plugins([$plugin], true);
        $result = delete_plugins([$plugin]);

        return $this->upgradeResult($result, "Plugin {$slug} deleted.");
    }

    private function deactivatePlugin(string $slug): array
    {
        $plugin = $this->resolvePluginFile($slug);
        if ($plugin === null) {
            return ['ok' => false, 'message' => "Plugin not found for slug {$slug}."];
        }

        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        deactivate_plugins([$plugin], true);

        return ['ok' => true, 'message' => "Plugin {$slug} deactivated."];
    }

    private function activatePlugin(string $slug): array
    {
        $plugin = $this->resolvePluginFile($slug);
        if ($plugin === null) {
            return ['ok' => false, 'message' => "Plugin not found for slug {$slug}."];
        }

        if (! function_exists('activate_plugin')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $activation = activate_plugin($plugin, '', false, true);
        if (is_wp_error($activation)) {
            return ['ok' => false, 'message' => $activation->get_error_message()];
        }

        return ['ok' => true, 'message' => "Plugin {$slug} activated."];
    }

    private function updateAllPlugins(): array
    {
        $this->loadUpgradeFiles();
        wp_update_plugins();

        $updates = get_plugin_updates();
        $plugins = array_keys($updates);
        if ($plugins === []) {
            return ['ok' => true, 'message' => 'No plugin updates available.', 'count' => 0];
        }

        $result = (new \Plugin_Upgrader(new \Automatic_Upgrader_Skin()))->bulk_upgrade($plugins);

        return $this->upgradeResult($result, 'Plugin updates completed.', ['count' => count($plugins)]);
    }

    private function updateTheme(array|string $payload): array
    {
        $slug = is_array($payload) ? (string) ($payload['slug'] ?? '') : $payload;
        $targetVersion = is_array($payload) ? trim((string) ($payload['available_version'] ?? '')) : '';

        $this->loadUpgradeFiles();
        $this->refreshThemeUpdates();

        $updates = get_theme_updates();
        if (! isset($updates[$slug])) {
            $installedVersion = $this->themeVersion($slug);

            if ($this->versionSatisfiesTarget($installedVersion, $targetVersion)) {
                return ['ok' => true, 'message' => "Theme {$slug} is already at {$installedVersion}."];
            }

            return ['ok' => false, 'message' => "No WordPress update is currently available for theme {$slug}."];
        }

        $result = (new \Theme_Upgrader(new \Automatic_Upgrader_Skin()))->upgrade($slug);

        if ($result === false || $result === null) {
            $installedVersion = $this->themeVersion($slug);

            if ($this->versionSatisfiesTarget($installedVersion, $targetVersion)) {
                return ['ok' => true, 'message' => "Theme {$slug} is already at {$installedVersion}."];
            }
        }

        return $this->upgradeResult($result, "Theme {$slug} updated.");
    }

    private function deleteTheme(string $slug): array
    {
        if (! function_exists('delete_theme')) {
            require_once ABSPATH.'wp-admin/includes/theme.php';
        }

        $result = delete_theme($slug);

        return $this->upgradeResult($result, "Theme {$slug} deleted.");
    }

    private function updateAllThemes(): array
    {
        $this->loadUpgradeFiles();
        wp_update_themes();

        $updates = get_theme_updates();
        $themes = array_keys($updates);
        if ($themes === []) {
            return ['ok' => true, 'message' => 'No theme updates available.', 'count' => 0];
        }

        $result = (new \Theme_Upgrader(new \Automatic_Upgrader_Skin()))->bulk_upgrade($themes);

        return $this->upgradeResult($result, 'Theme updates completed.', ['count' => count($themes)]);
    }

    private function updateCore(bool $reinstall): array
    {
        $this->loadUpgradeFiles();
        wp_version_check([], true);

        global $wp_version;
        $update = null;

        if ($reinstall && function_exists('find_core_update')) {
            $update = find_core_update((string) $wp_version, get_locale());
        }

        if (! $update) {
            $updates = get_core_updates();
            foreach ($updates as $candidate) {
                if ($reinstall || in_array($candidate->response ?? '', ['upgrade', 'latest'], true)) {
                    $update = $candidate;
                    break;
                }
            }
        }

        if (! $update) {
            return ['ok' => true, 'message' => 'No WordPress core update available.'];
        }

        $result = (new \Core_Upgrader(new \Automatic_Upgrader_Skin()))->upgrade($update);

        return $this->upgradeResult($result, $reinstall ? 'WordPress core reinstalled.' : 'WordPress core updated.');
    }

    private function createUser(array $payload): array
    {
        $data = $this->userData($payload);
        if (empty($data['user_login']) || empty($data['user_email'])) {
            return ['ok' => false, 'message' => 'Username and email are required to create a user.'];
        }

        if (empty($data['user_pass'])) {
            $data['user_pass'] = wp_generate_password(24, true, true);
        }

        $userId = wp_insert_user($data);
        if (is_wp_error($userId)) {
            return ['ok' => false, 'message' => $userId->get_error_message()];
        }

        $this->setUserRoles((int) $userId, $payload);
        $this->sendNewUserNotifications((int) $userId, $payload);

        return ['ok' => true, 'message' => 'WordPress user created.', 'user_id' => (int) $userId];
    }

    private function syncInventory(): array
    {
        $this->pushInventory();
        $this->pushUsers();

        return ['ok' => true, 'message' => 'Inventory sync pushed.'];
    }

    private function updateUser(array $payload): array
    {
        $user = $this->resolveUser($payload);
        if (! $user) {
            return ['ok' => false, 'message' => 'WordPress user not found.'];
        }

        $data = array_merge(['ID' => $user->ID], $this->userData($payload, false));
        unset($data['user_login']);

        $userId = wp_update_user($data);
        if (is_wp_error($userId)) {
            return ['ok' => false, 'message' => $userId->get_error_message()];
        }

        $this->setUserRoles((int) $userId, $payload);

        return ['ok' => true, 'message' => 'WordPress user updated.', 'user_id' => (int) $userId];
    }

    private function deleteUser(array $payload): array
    {
        $user = $this->resolveUser($payload);
        if (! $user) {
            return ['ok' => false, 'message' => 'WordPress user not found.'];
        }

        if (! function_exists('wp_delete_user')) {
            require_once ABSPATH.'wp-admin/includes/user.php';
        }

        $reassignTo = (int) ($payload['reassign_to'] ?? 0);
        $deleted = wp_delete_user((int) $user->ID, $reassignTo > 0 ? $reassignTo : null);

        return $deleted
            ? ['ok' => true, 'message' => 'WordPress user deleted.', 'user_id' => (int) $user->ID]
            : ['ok' => false, 'message' => 'WordPress user could not be deleted.'];
    }

    private function userData(array $payload, bool $includeLogin = true): array
    {
        $data = [];

        if ($includeLogin && ! empty($payload['user_login'])) {
            $data['user_login'] = sanitize_user((string) $payload['user_login'], true);
        }
        foreach (['user_email', 'display_name', 'first_name', 'last_name'] as $field) {
            if (isset($payload[$field]) && (string) $payload[$field] !== '') {
                $data[$field] = sanitize_text_field((string) $payload[$field]);
            }
        }
        if (! empty($payload['user_pass'])) {
            $data['user_pass'] = (string) $payload['user_pass'];
        }

        return $data;
    }

    private function setUserRoles(int $userId, array $payload): void
    {
        $roles = array_values(array_filter(array_map('sanitize_key', (array) ($payload['roles'] ?? []))));
        if ($roles === []) {
            return;
        }

        $user = new \WP_User($userId);
        $user->set_role(array_shift($roles));

        foreach ($roles as $role) {
            $user->add_role($role);
        }
    }

    private function sendNewUserNotifications(int $userId, array $payload): void
    {
        $notifyUser = ! empty($payload['send_user_notification']);
        $notifyAdmin = ! empty($payload['send_admin_notification']);

        if ($notifyUser || $notifyAdmin) {
            $notify = match (true) {
                $notifyUser && $notifyAdmin => 'both',
                $notifyAdmin => 'admin',
                default => 'user',
            };

            wp_new_user_notification($userId, null, $notify);
        }

    }

    private function sendPasswordReset(array $payload): array
    {
        $user = $this->resolveUser($payload);
        if (! $user) {
            return ['ok' => false, 'message' => 'WordPress user not found.'];
        }

        $resetKey = get_password_reset_key($user);
        if (is_wp_error($resetKey)) {
            return ['ok' => false, 'message' => $resetKey->get_error_message()];
        }

        $siteName = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $resetUrl = network_site_url(
            'wp-login.php?action=rp&key='.$resetKey.'&login='.rawurlencode($user->user_login),
            'login'
        );

        $message = sprintf(
            "Someone requested a password reset for your account on %s.\r\n\r\nUsername: %s\r\n\r\nSet your password here:\r\n%s\r\n\r\nIf you did not request this, you can ignore this email.",
            $siteName,
            $user->user_login,
            $resetUrl,
        );

        $sent = wp_mail(
            $user->user_email,
            sprintf('[%s] Password Reset', $siteName),
            $message
        );

        return $sent
            ? ['ok' => true, 'message' => 'Set-password email sent.', 'user_id' => (int) $user->ID]
            : ['ok' => false, 'message' => 'Set-password email could not be sent.'];
    }

    private function resolveUser(array $payload): ?\WP_User
    {
        if (! empty($payload['user_id'])) {
            $user = get_user_by('id', (int) $payload['user_id']);
            return $user instanceof \WP_User ? $user : null;
        }

        if (! empty($payload['user_login'])) {
            $user = get_user_by('login', (string) $payload['user_login']);
            return $user instanceof \WP_User ? $user : null;
        }

        if (! empty($payload['user_email'])) {
            $user = get_user_by('email', (string) $payload['user_email']);
            return $user instanceof \WP_User ? $user : null;
        }

        return null;
    }

    private function resolvePluginFile(string $slug): ?string
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        foreach (get_plugins() as $pluginFile => $data) {
            if ($pluginFile === $slug || dirname((string) $pluginFile) === $slug) {
                return (string) $pluginFile;
            }
        }

        return null;
    }

    private function refreshPluginUpdates(): void
    {
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        } else {
            delete_site_transient('update_plugins');
        }

        wp_update_plugins();
    }

    private function refreshThemeUpdates(): void
    {
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(true);
        } else {
            delete_site_transient('update_themes');
        }

        wp_update_themes();
    }

    private function pluginVersion(string $pluginFile): string
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $path = WP_PLUGIN_DIR.'/'.$pluginFile;
        if (! is_file($path)) {
            return '';
        }

        $data = get_plugin_data($path, false, false);

        return (string) ($data['Version'] ?? '');
    }

    private function pluginActivationState(string $pluginFile): ?string
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        if (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($pluginFile)) {
            return 'network';
        }

        return is_plugin_active($pluginFile) ? 'site' : null;
    }

    /**
     * @return null|array{ok: false, message: string}
     */
    private function restorePluginActivation(string $pluginFile, string $slug, ?string $activationState): ?array
    {
        if ($activationState === null || $this->pluginActivationState($pluginFile) !== null) {
            return null;
        }

        $activation = activate_plugin($pluginFile, '', $activationState === 'network', true);
        if (is_wp_error($activation)) {
            return [
                'ok' => false,
                'message' => "Plugin {$slug} updated, but WordPress could not reactivate it: ".$activation->get_error_message(),
            ];
        }

        if ($this->pluginActivationState($pluginFile) === null) {
            return [
                'ok' => false,
                'message' => "Plugin {$slug} updated, but WordPress left it inactive.",
            ];
        }

        return null;
    }

    private function themeVersion(string $slug): string
    {
        $theme = wp_get_theme($slug);

        return $theme->exists() ? (string) $theme->get('Version') : '';
    }

    private function versionSatisfiesTarget(string $installedVersion, string $targetVersion): bool
    {
        return $installedVersion !== ''
            && $targetVersion !== ''
            && version_compare($installedVersion, $targetVersion, '>=');
    }

    private function loadUpgradeFiles(): void
    {
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.'wp-admin/includes/update.php';
        require_once ABSPATH.'wp-admin/includes/update-core.php';
    }

    private function upgradeResult(mixed $result, string $successMessage, array $extra = []): array
    {
        if (is_wp_error($result)) {
            return array_merge(['ok' => false, 'message' => $result->get_error_message()], $extra);
        }

        if ($result === false || $result === null) {
            return array_merge(['ok' => false, 'message' => 'WordPress did not complete the requested operation.'], $extra);
        }

        return array_merge(['ok' => true, 'message' => $successMessage], $extra);
    }

    private function pushInventory(): void
    {
        if (class_exists(\ATX\UptimeMonitor\Hooks\InventoryReporter::class)) {
            (new \ATX\UptimeMonitor\Hooks\InventoryReporter($this->client))->push();
        }
    }

    private function pushUsers(): void
    {
        if (class_exists(\ATX\UptimeMonitor\Hooks\UserInventoryReporter::class)) {
            (new \ATX\UptimeMonitor\Hooks\UserInventoryReporter($this->client))->push();
        }
    }

    private function error(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'message' => $message], $status);
    }
}
