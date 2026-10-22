<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use LogicException;
use RuntimeException;
use Throwable;

trait DeadLetterServicesTrait
{
    use PulseServicesTrait;

    private const WORK_CHANNEL = 'pulse_dead_letter_work';
    private const OTHER_CHANNEL = 'pulse_dead_letter_other';

    private object $work;

    /**
     * @var array<string, object>
     */
    private array $works = [];

    /**
     * @var array<string, FlowTestSupport>
     */
    private array $services = [];

    private function serviceWithDeadLetters(string $service, bool $deadLettersEnabled = true): FlowTestSupport
    {
        $work = new class () {
            public bool $failing = true;

            /**
             * @var list<string>
             */
            public array $handled = [];

            #[Asynchronous('pulse_dead_letter_work')]
            #[CommandHandler('order.place', endpointId: 'pulse.order.place.endpoint')]
            public function place(array $order): void
            {
                $this->handle('order.place', $order, new RuntimeException('place failed for order ' . $order['orderId']));
            }

            #[Asynchronous('pulse_dead_letter_work')]
            #[CommandHandler('order.cancel', endpointId: 'pulse.order.cancel.endpoint')]
            public function cancel(array $order): void
            {
                $this->handle('order.cancel', $order, new LogicException('cancel failed for order ' . $order['orderId']));
            }

            #[Asynchronous('pulse_dead_letter_work')]
            #[CommandHandler('order.ship', endpointId: 'pulse.order.ship.endpoint')]
            public function ship(array $order): void
            {
                $this->handle('order.ship', $order, $this->stockFailure($order));
            }

            #[Asynchronous('pulse_dead_letter_work')]
            #[CommandHandler('order.reserve', endpointId: 'pulse.order.reserve.endpoint')]
            public function reserve(array $order): void
            {
                $this->handle('order.reserve', $order, $this->stockFailure($order));
            }

            #[CommandHandler('order.audit', endpointId: 'pulse.order.audit.endpoint')]
            public function audit(array $order): void
            {
                $this->handled[] = 'order.audit:' . $order['orderId'];
            }

            private function stockFailure(array $order): Throwable
            {
                return new RuntimeException('no stock for order ' . $order['orderId']);
            }

            private function handle(string $name, array $order, Throwable $failure): void
            {
                if ($this->failing) {
                    throw $failure;
                }

                $this->handled[] = $name . ':' . $order['orderId'];
            }
        };

        if ($deadLettersEnabled) {
            $this->work = $work;
            $this->works[$service] = $work;
        }

        $application = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$work::class],
            containerOrAvailableServices: [$work, DbalConnectionFactory::class => OpenedConnections::factory($_SERVER['PULSE_TRANSPORT_DSN'])],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create($this->serviceChannels[$service])->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create(self::WORK_CHANNEL)->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create(self::OTHER_CHANNEL)->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create($this->pulseChannel),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName($service)->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter($deadLettersEnabled),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        if ($deadLettersEnabled) {
            $application->getGateway(DeadLetterGateway::class)->deleteAll();
        }
        $this->services[$service] = $application;

        return $application;
    }

    private function serviceWithoutDeadLetters(string $service): FlowTestSupport
    {
        return $this->serviceWithDeadLetters($service, deadLettersEnabled: false);
    }

    private function failOrder(FlowTestSupport $service, string $routingKey, int $orderId): void
    {
        $service->sendCommandWithRouting($routingKey, ['orderId' => $orderId]);
        $service->run(self::WORK_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false)->withMemoryLimitInMegabytes(4096));
    }

    private function runAllServices(): void
    {
        foreach ($this->services as $name => $service) {
            $this->runService($service, $name);
        }
    }

    private function replayWorkOf(FlowTestSupport $service): void
    {
        $service->run(self::WORK_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false)->withMemoryLimitInMegabytes(4096));
    }

    private function replayOtherChannelOf(FlowTestSupport $service): void
    {
        $service->run(self::OTHER_CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(10, 100, false)->withMemoryLimitInMegabytes(4096));
    }
}
