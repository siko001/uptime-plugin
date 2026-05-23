<?php

declare(strict_types=1);

namespace Panza\UptimeMonitor;

use Panza\UptimeMonitor\Admin\SettingsPage;
use Panza\UptimeMonitor\Hooks\CliDeleteReporter;
use Panza\UptimeMonitor\Hooks\DeleteReporter;
use Panza\UptimeMonitor\Hooks\InventoryReporter;
use Panza\UptimeMonitor\Hooks\UpdateReporter;
use Panza\UptimeMonitor\Hooks\UserInventoryReporter;
use Panza\UptimeMonitor\Http\CommandEndpoint;
use Panza\UptimeMonitor\Http\WebhookClient;
use Panza\UptimeMonitor\Support\GitHubPluginUpdater;

final class Plugin
{
    public static function boot(): void
    {
        $client = new WebhookClient();

        (new SettingsPage($client))->register();
        (new GitHubPluginUpdater())->register();
        (new UpdateReporter($client))->register();
        (new DeleteReporter($client))->register();
        (new CliDeleteReporter($client))->register();
        (new InventoryReporter($client))->register();
        (new UserInventoryReporter($client))->register();
        (new CommandEndpoint($client))->register();
    }
}
