<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Admin;

use Panza\UptimeMonitor\Hooks\InventoryReporter;
use Panza\UptimeMonitor\Http\WebhookClient;
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

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('wp_ajax_' . self::TEST_ACTION, [$this, 'handleTest']);
        add_action('wp_ajax_' . self::CHECK_ACTION, [$this, 'handleCheckUpdates']);
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

        if (! function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');

        wp_update_plugins();
        wp_update_themes();
        wp_version_check([], true);

        (new InventoryReporter($this->client))->push();

        wp_send_json([
            'ok' => true,
            'plugins' => count(get_plugin_updates()),
            'themes' => count(get_theme_updates()),
            'core' => $this->hasCoreUpdate(),
        ]);
    }

    private function hasCoreUpdate(): bool
    {
        foreach (get_core_updates() as $update) {
            if (($update->response ?? '') === 'upgrade') {
                return true;
            }
        }

        return false;
    }
}
