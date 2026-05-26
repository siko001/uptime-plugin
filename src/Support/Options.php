<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Support;

final class Options
{
    public const URL_KEY    = 'panza_uptime_monitor_url';
    public const SECRET_KEY = 'panza_uptime_monitor_key';

    public static function getUrl(): string
    {
        return WebhookConfig::url('updates');
    }

    public static function getMonitorDomain(): string
    {
        $domain = WebhookConfig::normalizeDomain((string) get_option(self::URL_KEY, ''));

        return $domain !== '' ? $domain : WebhookConfig::fallbackMonitorDomain();
    }

    public static function getSecret(): string
    {
        return (string) get_option(self::SECRET_KEY, '');
    }

    public static function setUrl(string $url): void
    {
        self::setMonitorDomain($url);
    }

    public static function setMonitorDomain(string $domain): void
    {
        update_option(self::URL_KEY, WebhookConfig::normalizeDomain($domain));
    }

    public static function setSecret(string $secret): void
    {
        update_option(self::SECRET_KEY, $secret);
    }
}
