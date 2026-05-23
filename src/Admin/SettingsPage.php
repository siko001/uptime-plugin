<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Admin;

use Panza\UptimeMonitor\Http\WebhookClient;
use Panza\UptimeMonitor\Support\Options;

final class SettingsPage
{
    private const NONCE_SAVE = 'panza_um_save';
    private const NONCE_TEST = 'panza_um_test';
    private const MENU_SLUG  = 'panza-uptime-monitor';
    private const PERMISSION = 'manage_options';
    private const PAGE_TITLE = 'Uptime Monitor';
    private const MENU_TITLE = 'Uptime Monitor';
    private const SAVE_ACTION = 'panza_um_save';
    private const TEST_ACTION = 'panza_um_test';

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('wp_ajax_' . self::TEST_ACTION, [$this, 'handleTest']);
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

        $url        = Options::getUrl();
        $secret     = Options::getSecret();
        $saved      = isset($_GET['saved']);
        $nonceSave  = wp_create_nonce(self::NONCE_SAVE);
        $nonceTest  = wp_create_nonce(self::NONCE_TEST);
        $saveAction = self::SAVE_ACTION;
        $testAction = self::TEST_ACTION;

        require PANZA_UPTIME_MONITOR_DIR . '/views/settings.php';
    }

    public function handleSave(): void
    {
        if (! current_user_can(self::PERMISSION)) {
            wp_die('Unauthorized.');
        }

        check_admin_referer(self::NONCE_SAVE);

        Options::setUrl(esc_url_raw(trim((string) ($_POST['url'] ?? ''))));
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
}
