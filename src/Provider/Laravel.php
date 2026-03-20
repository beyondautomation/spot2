<?php

declare(strict_types=1);

namespace Spot\Provider;

use Illuminate\Support\ServiceProvider;
use Spot\Config;
use Spot\Locator;

class Laravel extends ServiceProvider
{
    /** @var array<string, mixed> */
    protected array $config = [];

    public function __construct(mixed $app)
    {
        $this->app = $app;

        $configObject = $this->app['config'];
        $connections = $configObject->get('database.connections');
        $config = $connections[$configObject->get('database.default')];

        // Munge Laravel array structure to match expected Doctrine DBAL's
        $config = [
            'dbname'    => $config['database'],
            'user'      => $config['username'] ?? null,
            'password'  => $config['password'] ?? null,
            'host'      => $config['host'] ?? null,
            'driver'    => 'pdo_' . $config['driver'],
        ];
        $this->config = $config;
    }

    public function register(): void
    {
        $this->app['spot'] = function (): \Spot\Locator {
            $config = new Config();
            $config->addConnection('default', $this->config);

            return new Locator($config);
        };
    }
}
