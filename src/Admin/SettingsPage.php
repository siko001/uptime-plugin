<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Admin;

use Panza\UptimeMonitor\Http\WebhookClient;
use Panza\UptimeMonitor\Support\GitHubPluginUpdater;
use Panza\UptimeMonitor\Support\Options;

final class SettingsPage
{
    private const NONCE_SAVE = 'panza_um_save';
    private const NONCE_TEST = 'panza_um_test';
    private const NONCE_CHECK = 'panza_um_check_updates';
    private const MENU_SLUG  = 'panza-uptime-monitor';
    private const PERMISSION = 'manage_options';
    private const PAGE_TITLE = 'Uptime Monitor';
    private const MENU_TITLE = 'Uptime Monitor';
    private const SAVE_ACTION = 'panza_um_save';
    private const TEST_ACTION = 'panza_um_test';
    private const CHECK_ACTION = 'panza_um_check_updates';
    private const CHECK_POST_ACTION = 'panza_um_check_updates_post';

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::CHECK_POST_ACTION, [$this, 'handleCheckUpdatesPost']);
        add_action('wp_ajax_' . self::TEST_ACTION, [$this, 'handleTest']);
        add_action('wp_ajax_' . self::CHECK_ACTION, [$this, 'handleCheckUpdates']);
        add_filter('plugin_action_links_' . plugin_basename(PANZA_UPTIME_MONITOR_FILE), [$this, 'pluginActionLinks']);
        add_action('admin_notices', [$this, 'showUpdateCheckNotice']);
    }

    public function addMenu(): void
    {
        add_options_page(
            self::PAGE_TITLE,
            self::MENU_TITLE,
            self::PERMISSION,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::PERMISSION)) {
            return;
        }

        $url        = Options::getMonitorDomain();
        $secret     = Options::getSecret();
        $saved      = isset($_GET['saved']);
        $nonceSave  = wp_create_nonce(self::NONCE_SAVE);
        $nonceTest  = wp_create_nonce(self::NONCE_TEST);
        $nonceCheck = wp_create_nonce(self::NONCE_CHECK);
        $saveAction = self::SAVE_ACTION;
        $testAction = self::TEST_ACTION;
        $checkAction = self::CHECK_ACTION;

        require PANZA_UPTIME_MONITOR_DIR . '/views/settings.php';
    }

    public function handleSave(): void
    {
        if (! current_user_can(self::PERMISSION)) {
            wp_die('Unauthorized.');
        }

        check_admin_referer(self::NONCE_SAVE);

        Options::setMonitorDomain(trim((string) ($_POST['url'] ?? '')));
        Options::setSecret(trim((string) ($_POST['secret'] ?? '')));

        wp_safe_redirect(admin_url('options-general.php?page=' . self::MENU_SLUG . '&saved=1'));
        exit;
    }

    public function handleTest(): void
    {
        if (! current_user_can(self::PERMISSION)) {
            wp_send_json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_TEST);

        wp_send_json($this->client->ping());
    }

    public function handleCheckUpdates(): void
    {
        if (! current_user_can(self::PERMISSION)) {
            wp_send_json(['ok' => false, 'error' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_CHECK);

        wp_send_json($this->checkUpdates());
    }

    public function handleCheckUpdatesPost(): void
    {
        if (! current_user_can(self::PERMISSION)) {
            wp_die('Unauthorized.');
        }

        check_admin_referer(self::NONCE_CHECK);

        $result = $this->checkUpdates();
        $redirect = add_query_arg([
            'panza_um_updates_checked' => '1',
            'panza_um_update_ok' => $result['ok'] ? '1' : '0',
            'panza_um_installed_version' => (string) ($result['installed_version'] ?? ''),
            'panza_um_latest_version' => (string) ($result['latest_version'] ?? ''),
            'panza_um_update_available' => ! empty($result['update_available']) ? '1' : '0',
            'panza_um_update_error' => (string) ($result['error'] ?? ''),
        ], admin_url('plugins.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function pluginActionLinks(array $links): array
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::CHECK_POST_ACTION),
            self::NONCE_CHECK
        );

        $links['panza-check-updates'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Check for updates', 'panza-uptime-monitor')
        );

        return $links;
    }

    public function showUpdateCheckNotice(): void
    {
        if (! current_user_can(self::PERMISSION) || empty($_GET['panza_um_updates_checked'])) {
            return;
        }

        $ok = ! empty($_GET['panza_um_update_ok']);
        $installedVersion = sanitize_text_field((string) ($_GET['panza_um_installed_version'] ?? ''));
        $latestVersion = sanitize_text_field((string) ($_GET['panza_um_latest_version'] ?? ''));
        $updateAvailable = ! empty($_GET['panza_um_update_available']);

        if (! $ok) {
            $error = sanitize_text_field((string) ($_GET['panza_um_update_error'] ?? 'Could not check for updates.'));
            printf(
                '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Panza Uptime Monitor update check failed.', 'panza-uptime-monitor'),
                esc_html($error)
            );

            return;
        }

        $message = $updateAvailable
            ? sprintf('Update available: %s -> %s.', $installedVersion, $latestVersion)
            : sprintf('Already up to date at version %s.', $installedVersion);

        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Panza Uptime Monitor checked.', 'panza-uptime-monitor'),
            esc_html($message)
        );
    }

    /**
     * @return array{ok: bool, installed_version: string, latest_version?: string, update_available?: bool, error?: string}
     */
    private function checkUpdates(): array
    {
        return (new GitHubPluginUpdater())->checkForUpdate();
    }
}
