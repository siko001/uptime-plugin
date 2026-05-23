<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Http;

use Panza\UptimeMonitor\Support\Options;
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
            register_rest_route('panza-uptime-monitor/v1', '/command', [
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
            return new WP_Error('panza_uptime_monitor_unconfigured', 'Command secret is not configured.', ['status' => 403]);
        }

        $timestamp = (string) $request->get_header('x-timestamp');
        $signature = (string) $request->get_header('x-signature');

        if ($timestamp === '' || $signature === '') {
            return new WP_Error('panza_uptime_monitor_missing_signature', 'Missing signature headers.', ['status' => 401]);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('panza_uptime_monitor_bad_timestamp', 'Timestamp is outside the allowed window.', ['status' => 401]);
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->get_body(), $secret);
        if (! hash_equals($expected, $signature)) {
            return new WP_Error('panza_uptime_monitor_bad_signature', 'Signature mismatch.', ['status' => 401]);
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
            'update_plugin' => $this->updatePlugin((string) ($payload['slug'] ?? '')),
            'delete_plugin' => $this->deletePlugin((string) ($payload['slug'] ?? '')),
            'update_all_plugins' => $this->updateAllPlugins(),
            'update_theme' => $this->updateTheme((string) ($payload['slug'] ?? '')),
            'delete_theme' => $this->deleteTheme((string) ($payload['slug'] ?? '')),
            'update_all_themes' => $this->updateAllThemes(),
            'update_core' => $this->updateCore(false),
            'reinstall_core' => $this->updateCore(true),
            'create_user' => $this->createUser($payload),
            'update_user' => $this->updateUser($payload),
            'delete_user' => $this->deleteUser($payload),
            'sync_inventory' => $this->syncInventory(),
            default => ['ok' => false, 'message' => "Unknown command: {$command}"],
        };

        if (($result['ok'] ?? false) === true && in_array($command, [
            'update_plugin',
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

    private function updatePlugin(string $slug): array
    {
        $plugin = $this->resolvePluginFile($slug);
        if ($plugin === null) {
            return ['ok' => false, 'message' => "Plugin not found for slug {$slug}."];
        }

        $this->loadUpgradeFiles();
        $result = (new \Plugin_Upgrader(new \Automatic_Upgrader_Skin()))->upgrade($plugin);

        return $this->upgradeResult($result, "Plugin {$slug} updated.");
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

    private function updateTheme(string $slug): array
    {
        $this->loadUpgradeFiles();
        $result = (new \Theme_Upgrader(new \Automatic_Upgrader_Skin()))->upgrade($slug);

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
        if (empty($data['user_login']) || empty($data['user_email']) || empty($data['user_pass'])) {
            return ['ok' => false, 'message' => 'Username, email, and password are required to create a user.'];
        }

        $userId = wp_insert_user($data);
        if (is_wp_error($userId)) {
            return ['ok' => false, 'message' => $userId->get_error_message()];
        }

        $this->setUserRoles((int) $userId, $payload);

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
        if (class_exists(\Panza\UptimeMonitor\Hooks\InventoryReporter::class)) {
            (new \Panza\UptimeMonitor\Hooks\InventoryReporter($this->client))->push();
        }
    }

    private function pushUsers(): void
    {
        if (class_exists(\Panza\UptimeMonitor\Hooks\UserInventoryReporter::class)) {
            (new \Panza\UptimeMonitor\Hooks\UserInventoryReporter($this->client))->push();
        }
    }

    private function error(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'message' => $message], $status);
    }
}
