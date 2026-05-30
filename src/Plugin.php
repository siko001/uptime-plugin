<?php

declare(strict_types=1);

namespace ATX\UptimeMonitor;

use ATX\UptimeMonitor\Admin\SettingsPage;
use ATX\UptimeMonitor\Hooks\CliDeleteReporter;
use ATX\UptimeMonitor\Hooks\DeleteReporter;
use ATX\UptimeMonitor\Hooks\InventoryReporter;
use ATX\UptimeMonitor\Hooks\UpdateReporter;
use ATX\UptimeMonitor\Hooks\UserInventoryReporter;
use ATX\UptimeMonitor\Http\CommandEndpoint;
use ATX\UptimeMonitor\Http\WebhookClient;

final class Plugin
{
    public static function boot(): void
    {
        $client = new WebhookClient();

        (new SettingsPage($client))->register();
        (new UpdateReporter($client))->register();
        (new DeleteReporter($client))->register();
        (new CliDeleteReporter($client))->register();
        (new InventoryReporter($client))->register();
        (new UserInventoryReporter($client))->register();
        (new CommandEndpoint($client))->register();
    }
}
