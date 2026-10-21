<?php

declare(strict_types=1);

namespace App\Connect;

use App\Settings\Connection;
use App\Settings\PulseSettings;
use App\Settings\ServiceRoute;
use App\Settings\Transport;
use InvalidArgumentException;

final readonly class ConnectSnippets
{
    public const PLACEHOLDER_SERVICE = 'my_service';
    public const ALLOWED_CHARACTERS = '[A-Za-z0-9_.-]';
    private const NAME_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    public function __construct(private PulseSettings $settings)
    {
    }

    public function pulseServicesEntry(string $service): string
    {
        $route = $this->settings->routes[$service] ?? null;
        if ($route === null) {
            return $service;
        }

        $entry = $route->channel === $service ? $service : $service . ':' . $route->channel;
        $connection = $this->settings->connections[$route->connection];
        if (! $connection->isDefault()) {
            $entry .= '@' . $connection->name;
        }

        return $entry;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * @return array<string, array{title: string, location: string, php: string, bootstrap: ?string, wiring: string, consumer: string}>
     */
    public function for(string $service): array
    {
        if (! self::isValidName($service)) {
            throw new InvalidArgumentException(sprintf('"%s" cannot be a service name in generated code. Use only the characters %s.', $service, self::ALLOWED_CHARACTERS));
        }

        $channel = $this->requestChannel($service);
        $connection = $this->connectionOf($service);

        return [
            'lite' => [
                'title' => 'Ecotone Lite / PSR-11',
                'location' => 'any autoloaded file',
                'php' => $this->serviceContextClass($service, null),
                'bootstrap' => $this->liteBootstrap($connection),
                'wiring' => '',
                'consumer' => sprintf("\$ecotone->run('%s');", $channel),
            ],
            'symfony' => [
                'title' => 'Symfony',
                'location' => 'src/PulseSetup.php, discovered with the other classes of the App namespace',
                'php' => $this->serviceContextClass($service, 'App'),
                'bootstrap' => null,
                'wiring' => "# config/packages/ecotone.yaml\necotone:\n    licenceKey: '%env(ECOTONE_LICENCE_KEY)%'",
                'consumer' => 'bin/console ecotone:run ' . $channel,
            ],
            'laravel' => [
                'title' => 'Laravel',
                'location' => 'app/PulseSetup.php, discovered with the other classes of the App namespace',
                'php' => $this->serviceContextClass($service, 'App'),
                'bootstrap' => null,
                'wiring' => "// config/ecotone.php\n'licenceKey' => env('ECOTONE_LICENCE_KEY'),",
                'consumer' => 'php artisan ecotone:run ' . $channel,
            ],
        ];
    }

    private function connectionOf(string $service): Connection
    {
        $route = $this->settings->routes[$service] ?? null;

        return $route instanceof ServiceRoute ? $this->settings->connections[$route->connection] : $this->settings->defaultConnection();
    }

    private function liteBootstrap(Connection $connection): string
    {
        [$imports, $services] = match ($connection->transport) {
            Transport::Dbal => [['Ecotone\\Api\\Dbal\\DbalConnectionFactory'], "DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))"],
            Transport::Amqp => [['Ecotone\\Api\\Amqp\\AmqpExtConnectionFactory', 'Ecotone\\Api\\Amqp\\AmqpLibConnectionFactory'], "AmqpExtConnectionFactory::class => \$amqp = new AmqpExtConnectionFactory(['dsn' => getenv('AMQP_DSN')]),\n        AmqpLibConnectionFactory::class => \$amqp"],
            Transport::Redis => [['Ecotone\\Api\\Redis\\RedisConnectionFactory'], "RedisConnectionFactory::class => new RedisConnectionFactory(['dsn' => getenv('REDIS_DSN'), 'scheme_extensions' => ['phpredis']])"],
            Transport::Sqs => [['Ecotone\\Api\\Sqs\\SqsConnectionFactory'], "SqsConnectionFactory::class => new SqsConnectionFactory(getenv('SQS_DSN'))"],
            Transport::Kafka => [['Ecotone\\Api\\Kafka\\KafkaBrokerConfiguration'], "KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(['kafka:9092'])"],
        };
        $imports = [...$imports, 'Ecotone\\Api\\Lite\\EcotoneLite'];
        sort($imports);
        $uses = implode("\n", array_map(static fn (string $class) => 'use ' . $class . ';', $imports));

        return <<<PHP
<?php

{$uses}

\$ecotone = EcotoneLite::bootstrap(
    classesToResolve: [PulseSetup::class],
    containerOrAvailableServices: [
        {$services},
    ],
    licenceKey: getenv('ECOTONE_LICENCE_KEY'),
);
PHP;
    }

    private function requestChannel(string $service): string
    {
        return ($this->settings->routes[$service] ?? null)?->channel ?? $service;
    }

    private function serviceContextClass(string $service, ?string $namespace): string
    {
        $connection = $this->connectionOf($service);
        $builder = $connection->transport->channelBuilderClass();
        $builderName = substr($builder, (int) strrpos($builder, '\\') + 1);
        $channel = $this->requestChannel($service);
        $replyChannel = PulseSettings::replyChannelName($connection, $this->settings->channel);

        $pulse = sprintf('PulseConfiguration::createWithDefaults(pulseServiceName: %s)', var_export($this->settings->serviceName, true));
        if ($channel !== $service) {
            $pulse .= sprintf("\n            ->withRequestChannel(%s)", var_export($channel, true));
        }
        if ($replyChannel !== $this->settings->serviceName) {
            $pulse .= sprintf("\n            ->withReplyChannel(%s)", var_export($replyChannel, true));
        }

        $imports = [
            $builder,
            'Ecotone\Api\Attribute\ServiceContext',
            'Ecotone\Api\ExtensionObject\ServiceConfiguration',
            'Ecotone\Api\Pulse\PulseConfiguration',
        ];
        sort($imports);
        $uses = implode("\n", array_map(static fn (string $class) => 'use ' . $class . ';', $imports));
        $namespaceLine = $namespace === null ? '' : "namespace {$namespace};\n\n";
        [$serviceLiteral, $channelLiteral, $replyLiteral] = [var_export($service, true), var_export($channel, true), var_export($replyChannel, true)];

        return <<<PHP
            <?php

            declare(strict_types=1);

            {$namespaceLine}{$uses}

            final class PulseSetup
            {
                #[ServiceContext]
                public function serviceName(): ServiceConfiguration
                {
                    return ServiceConfiguration::createWithDefaults()->withServiceName({$serviceLiteral});
                }

                #[ServiceContext]
                public function pulse(): PulseConfiguration
                {
                    return {$pulse};
                }

                #[ServiceContext]
                public function channels(): array
                {
                    return [
                        {$builderName}::create({$channelLiteral}),
                        {$builderName}::create({$replyLiteral}),
                    ];
                }
            }
            PHP;
    }
}
