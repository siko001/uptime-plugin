<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Support;

final class Options
{
    public const URL_KEY    = 'panza_uptime_monitor_url';
    public const SECRET_KEY = 'panza_uptime_monitor_key';

    public static function getUrl(): string
    {
        return (string) get_option(self::URL_KEY, '');
    }

    public static function getSecret(): string
    {
        return (string) get_option(self::SECRET_KEY, '');
    }

    public static function setUrl(string $url): void
    {
        update_option(self::URL_KEY, $url);
    }

    public static function setSecret(string $secret): void
    {
        update_option(self::SECRET_KEY, $secret);
    }
}
