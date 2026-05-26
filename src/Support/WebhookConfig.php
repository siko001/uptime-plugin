<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Support;

final class WebhookConfig
{
    /**
     * @return array{fallback_monitor_domain: string, endpoints: array<string, string>}
     */
    public static function all(): array
    {
        $config = require PANZA_UPTIME_MONITOR_DIR . '/config/webhooks.php';

        return is_array($config) ? $config : [
            'fallback_monitor_domain' => 'https://whitesmoke-camel-166125.hostingersite.com',
            'endpoints' => [],
        ];
    }

    public static function fallbackMonitorDomain(): string
    {
        return self::normalizeDomain((string) (self::all()['fallback_monitor_domain'] ?? ''));
    }

    public static function endpoint(string $key): string
    {
        $endpoints = self::all()['endpoints'] ?? [];

        return (string) ($endpoints[$key] ?? '');
    }

    public static function url(string $endpointKey): string
    {
        return rtrim(Options::getMonitorDomain(), '/') . self::endpoint($endpointKey);
    }

    public static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return '';
        }

        if (! preg_match('~^https?://~i', $domain)) {
            $domain = 'https://' . $domain;
        }

        $parts = wp_parse_url($domain);

        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (string) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
