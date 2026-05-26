<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Http;

use Panza\UptimeMonitor\Support\Options;
use Panza\UptimeMonitor\Support\WebhookConfig;

final class WebhookClient
{
    public function send(array $items): void
    {
        $url    = WebhookConfig::url('updates');
        $secret = Options::getSecret();

        if ($url === '' || $secret === '' || $items === []) {
            return;
        }

        $body    = $this->buildBody(['items' => $items]);
        $headers = $this->signedHeaders($body, $secret);

        wp_remote_post($url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => $headers,
            'body'     => $body,
        ]);
    }

    /**
     * Push the current installed plugin inventory to the configured
     * `wp-plugins` endpoint.
     *
     * @param  list<array<string, mixed>>  $plugins
     */
    public function sendInventory(array $plugins): void
    {
        $url    = WebhookConfig::url('plugins');
        $secret = Options::getSecret();

        if ($url === '' || $secret === '' || $plugins === []) {
            return;
        }

        $body    = $this->buildBody(['plugins' => $plugins]);
        $headers = $this->signedHeaders($body, $secret);

        wp_remote_post($url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => $headers,
            'body'     => $body,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @param  list<array<string, mixed>>  $roles
     */
    public function sendUsers(array $users, array $roles = []): void
    {
        $url = WebhookConfig::url('users');
        $secret = Options::getSecret();

        if ($url === '' || $secret === '' || ($users === [] && $roles === [])) {
            return;
        }

        $body = $this->buildBody(['users' => $users, 'roles' => $roles]);
        $headers = $this->signedHeaders($body, $secret);

        wp_remote_post($url, [
            'timeout' => 5,
            'blocking' => false,
            'headers' => $headers,
            'body' => $body,
        ]);
    }

    /**
     * @return array{ok: bool, status?: int, data?: ?array, raw?: string, error?: string}
     */
    public function ping(): array
    {
        $url    = WebhookConfig::url('ping');
        $secret = Options::getSecret();

        if ($url === '') {
            return ['ok' => false, 'error' => 'Monitor domain not configured.'];
        }
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Webhook secret not configured.'];
        }

        $body    = $this->buildBody([]);
        $headers = $this->signedHeaders($body, $secret);

        $response = wp_remote_post($url, [
            'timeout'  => 10,
            'blocking' => true,
            'headers'  => $headers,
            'body'     => $body,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        return [
            'ok'     => $code === 200 && is_array($data) && ($data['ok'] ?? false) === true,
            'status' => $code,
            'data'   => is_array($data) ? $data : null,
            'raw'    => $raw,
        ];
    }

    private function buildBody(array $extra): string
    {
        return (string) wp_json_encode(array_merge(['site_url' => home_url()], $extra));
    }

    private function signedHeaders(string $body, string $secret): array
    {
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        return [
            'Content-Type' => 'application/json',
            'X-Timestamp'  => $timestamp,
            'X-Signature'  => $signature,
        ];
    }
}
