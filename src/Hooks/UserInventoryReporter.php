<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Hooks;

use Panza\UptimeMonitor\Http\WebhookClient;

final class UserInventoryReporter
{
    public const CRON_HOOK = 'panza_uptime_monitor_push_users';

    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'push']);
        add_action('user_register', [$this, 'push']);
        add_action('profile_update', [$this, 'push']);
        add_action('deleted_user', [$this, 'push']);

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 90, 'daily', self::CRON_HOOK);
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
        $this->client->sendUsers($this->collect());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect(): array
    {
        return array_map(static function (\WP_User $user): array {
            return [
                'id' => (int) $user->ID,
                'user_login' => (string) $user->user_login,
                'user_email' => (string) $user->user_email,
                'display_name' => (string) $user->display_name,
                'roles' => array_values(array_map('strval', (array) $user->roles)),
            ];
        }, get_users([
            'fields' => 'all',
            'orderby' => 'login',
            'order' => 'ASC',
        ]));
    }
}
