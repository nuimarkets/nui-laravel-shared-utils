<?php

namespace NuiMarkets\LaravelSharedUtils\Database;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Keeps IAM token acquisition at Laravel's lazy PDO boundary.
 */
class IamRdsConnectionFactory extends ConnectionFactory
{
    public function __construct(Container $container, private IamRdsConnector $iam)
    {
        parent::__construct($container);
    }

    /**
     * Resolve credentials on each PDO opening attempt, rather than capturing
     * a token when Laravel first constructs the connection object.
     *
     * @param  array<string, mixed>  $config
     * @return \Closure
     */
    protected function createPdoResolver(array $config)
    {
        return function () use ($config) {
            $config['password'] = $this->iam->tokenFor($config);

            return parent::createPdoResolver($config)();
        };
    }
}
