<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor\Hooks;

use Panza\UptimeMonitor\Http\WebhookClient;

final class DeleteReporter
{
    public function __construct(private readonly WebhookClient $client)
    {
    }

    public function register(): void
    {
        add_action('deleted_plugin', [$this, 'onPluginDeleted'], 10, 2);
        add_action('deleted_theme', [$this, 'onThemeDeleted'], 10, 2);
    }

    public function onPluginDeleted(string $pluginFile, bool $deleted): void
    {
        if (! $deleted) {
            return;
        }

        $this->client->send([[
            'name'   => $pluginFile,
            'slug'   => dirname($pluginFile),
            'type'   => 'plugin',
            'action' => 'delete',
        ]]);
    }

    public function onThemeDeleted(string $stylesheet, bool $deleted): void
    {
        if (! $deleted) {
            return;
        }

        $this->client->send([[
            'name'   => $stylesheet,
            'slug'   => $stylesheet,
            'type'   => 'theme',
            'action' => 'delete',
        ]]);
    }
}
