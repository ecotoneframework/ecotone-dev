<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

trait PulseServicesTrait
{
    use PulseEnvironmentTrait;

    private string $pulseChannel;

    /**
     * @var array<string, string>
     */
    private array $serviceChannels;

    private function configurePulseWithServices(string ...$services): void
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->serviceChannels = [];
        foreach ($services as $service) {
            $this->serviceChannels[$service] = $service . '_' . $suffix;
        }

        $this->configurePulse([
            'PULSE_CHANNEL' => $this->pulseChannel,
            'PULSE_SERVICES' => implode(',', array_map(fn (string $service) => $service . ':' . $this->serviceChannels[$service], $services)),
        ]);
    }

    private function advanceTimeBy(int $seconds): void
    {
        self::getContainer()->get('pulse.clock')->sleep($seconds);
    }

    private function realService(string $service): FlowTestSupport
    {
        return $this->serviceApplication($service, [], []);
    }

    private function serviceAnsweringWith(string $service, string $snapshotJson): FlowTestSupport
    {
        $handler = new class ($snapshotJson) {
            public function __construct(private string $snapshot)
            {
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request): string
            {
                return $this->snapshot;
            }
        };

        return $this->serviceApplication($service, [$handler::class], [$handler], false);
    }

    /**
     * @return array{FlowTestSupport, object}
     */
    private function countingService(string $service, string $snapshotJson): array
    {
        $handler = new class ($snapshotJson) {
            public int $requests = 0;

            public function __construct(private string $snapshot)
            {
            }

            #[Distributed]
            #[CommandHandler('ecotone.pulse.snapshot')]
            public function snapshot(string $request): string
            {
                $this->requests++;

                return $this->snapshot;
            }
        };

        return [$this->serviceApplication($service, [$handler::class], [$handler], false), $handler];
    }

    private function runService(FlowTestSupport $service, string $serviceName): void
    {
        $service->run($this->serviceChannels[$serviceName], ExecutionPollingMetadata::createWithTestingSetup());
    }

    private function setUpPulseInternalStorage(KernelInterface $kernel): void
    {
        $application = new Application($kernel);
        (new CommandTester($application->find('pulse:setup')))->execute([]);
        (new CommandTester($application->find('ecotone:migration:database:setup')))->execute(['--initialize' => true, '--connection' => DbalConnectionFactory::class]);
    }

    private function setUpPulseDatabase(KernelInterface $kernel): void
    {
        $application = new Application($kernel);
        (new CommandTester($application->find('pulse:setup')))->execute([]);
        $command = new CommandTester($application->find('ecotone:migration:database:setup'));
        $command->execute(['--initialize' => true]);
    }

    private function runPulseWorker(KernelInterface $kernel): void
    {
        $application = new Application($kernel);
        $command = new CommandTester($application->find('ecotone:run'));
        $command->execute(['consumerName' => $this->pulseChannel, '--finishWhenNoMessages' => true, '--executionTimeLimit' => 2000]);
    }

    /**
     * @param array<class-string> $classes
     * @param array<object> $handlers
     */
    private function serviceApplication(string $service, array $classes, array $handlers, bool $withPulseResponder = true): FlowTestSupport
    {
        $dsn = $_SERVER['PULSE_TRANSPORT_DSN'];

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classes,
            containerOrAvailableServices: [...$handlers, DbalConnectionFactory::class => new DbalConnectionFactory($dsn)],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create($this->serviceChannels[$service])->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName($service),
                ...($withPulseResponder ? [PulseConfiguration::createWithDefaults('ecotone_pulse')] : []),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
