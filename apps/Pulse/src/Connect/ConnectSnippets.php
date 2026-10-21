<?php

declare(strict_types=1);

namespace App\Connect;

use App\Settings\PulseSettings;
use App\Settings\ServiceRoute;

final readonly class ConnectSnippets
{
    public const PLACEHOLDER_SERVICE = 'my_service';

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

    /**
     * @return array<string, array{title: string, php: string, wiring: string}>
     */
    public function for(string $service): array
    {
        $php = $this->serviceContextClass($service);
        $channel = $this->requestChannel($service);
        $licenceKey = '%env(ECOTONE_LICENCE_KEY)%';

        return [
            'lite' => [
                'title' => 'Ecotone Lite / PSR-11',
                'php' => $php,
                'wiring' => sprintf("\$ecotone = EcotoneLite::bootstrap(\n    classesToResolve: [PulseSetup::class],\n    containerOrAvailableServices: [/* your connection factory and handlers */],\n    licenceKey: getenv('ECOTONE_LICENCE_KEY'),\n);\n\n\$ecotone->run('%s');", $channel),
            ],
            'symfony' => [
                'title' => 'Symfony',
                'php' => $php,
                'wiring' => sprintf("# src/PulseSetup.php is discovered on its own\n# config/packages/ecotone.yaml\necotone:\n    licenceKey: '%s'\n\nbin/console ecotone:run %s", $licenceKey, $channel),
            ],
            'laravel' => [
                'title' => 'Laravel',
                'php' => $php,
                'wiring' => sprintf("// app/PulseSetup.php is discovered on its own\n// config/ecotone.php\n'licenceKey' => env('ECOTONE_LICENCE_KEY'),\n\nphp artisan ecotone:run %s", $channel),
            ],
        ];
    }

    private function requestChannel(string $service): string
    {
        return ($this->settings->routes[$service] ?? null)?->channel ?? $service;
    }

    private function serviceContextClass(string $service): string
    {
        $route = $this->settings->routes[$service] ?? null;
        $connection = $route instanceof ServiceRoute ? $this->settings->connections[$route->connection] : $this->settings->defaultConnection();
        $builder = $connection->transport->channelBuilderClass();
        $builderName = substr($builder, (int) strrpos($builder, '\\') + 1);
        $channel = $this->requestChannel($service);
        $replyChannel = PulseSettings::replyChannelName($connection, $this->settings->channel);

        $pulse = sprintf("PulseConfiguration::createWithDefaults(pulseServiceName: '%s')", $this->settings->serviceName);
        if ($channel !== $service) {
            $pulse .= sprintf("\n            ->withRequestChannel('%s')", $channel);
        }
        if ($replyChannel !== $this->settings->serviceName) {
            $pulse .= sprintf("\n            ->withReplyChannel('%s')", $replyChannel);
        }

        $imports = [
            $builder,
            'Ecotone\Api\Attribute\ServiceContext',
            'Ecotone\Api\ExtensionObject\ServiceConfiguration',
            'Ecotone\Api\Pulse\PulseConfiguration',
        ];
        sort($imports);
        $uses = implode("\n", array_map(static fn (string $class) => 'use ' . $class . ';', $imports));

        return <<<PHP
            <?php

            declare(strict_types=1);

            {$uses}

            final class PulseSetup
            {
                #[ServiceContext]
                public function serviceName(): ServiceConfiguration
                {
                    return ServiceConfiguration::createWithDefaults()->withServiceName('{$service}');
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
                        {$builderName}::create('{$channel}'),
                        {$builderName}::create('{$replyChannel}'),
                    ];
                }
            }
            PHP;
    }
}
