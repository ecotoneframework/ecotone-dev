<?php

declare(strict_types=1);

namespace App\Tests\Doctor;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Amqp\AmqpLibConnectionFactory;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class DoctorTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    private const BROKER_DSN = 'amqp://guest:guest@rabbitmq:5672/%2f';

    private string $queue;

    public function test_a_healthy_setup_passes_every_check(): void
    {
        $this->pulseOn(self::BROKER_DSN);
        $this->serviceHavingCreatedItsQueue();
        $this->setUpInternalDatabase();

        [$status, $checks] = $this->diagnose();

        self::assertSame(0, $status, json_encode($checks));
        self::assertSame('ok', $checks['transport default']['status']);
        self::assertMatchesRegularExpression('/^reachable at rabbitmq:5672 in \d+ ms$/', $checks['transport default']['message']);
        self::assertSame('ok', $checks['login default']['status']);
        self::assertSame(sprintf("queue '%s' exists", $this->queue), $checks['service orders']['message']);
        self::assertSame('ok', $checks['service orders']['status']);
        self::assertSame('ok', $checks['internal database']['status']);
        self::assertSame('ok', $checks['licence']['status']);
        self::assertSame('info', $checks['reply channels']['status']);
        self::assertStringContainsString($this->pulseChannel, $checks['reply channels']['message']);
    }

    public function test_a_service_queue_that_does_not_exist_is_reported_with_the_builder_to_check(): void
    {
        $this->pulseOn(self::BROKER_DSN);

        [$status, $checks] = $this->diagnose();

        self::assertSame(1, $status);
        self::assertSame('fail', $checks['service orders']['status']);
        self::assertSame(sprintf("queue '%s' does not exist on connection default", $this->queue), $checks['service orders']['message']);
        self::assertSame(sprintf("The service has not started on this broker yet, or it consumes another queue. Check that it registers AmqpBackedMessageChannelBuilder::create('%s') and runs ecotone:run for it; when its queue is named differently, set it in PULSE_SERVICES as orders:channel#queue_name.", $this->queue), $checks['service orders']['fix']);
    }

    public function test_an_unreachable_host_is_reported_with_the_host_and_port(): void
    {
        $this->pulseOn('amqp://guest:guest@nohost:5672/%2f');

        [$status, $checks] = $this->diagnose();

        self::assertSame(1, $status);
        self::assertSame('fail', $checks['transport default']['status']);
        self::assertStringContainsString('cannot connect to nohost:5672', $checks['transport default']['message']);
        self::assertSame('Check the host and port in PULSE_TRANSPORT_DSN, and that the broker is on the same network as this container.', $checks['transport default']['fix']);
        self::assertArrayNotHasKey('login default', $checks);
    }

    public function test_refused_credentials_are_reported_naming_the_variable(): void
    {
        $this->pulseOn('amqp://guest:wrong@rabbitmq:5672/%2f');

        [$status, $checks] = $this->diagnose();

        self::assertSame(1, $status);
        self::assertSame('ok', $checks['transport default']['status']);
        self::assertSame('fail', $checks['login default']['status']);
        self::assertStringContainsString('ACCESS_REFUSED', $checks['login default']['message']);
        self::assertSame('Check the user and password in PULSE_TRANSPORT_DSN.', $checks['login default']['fix']);
    }

    public function test_the_text_format_prints_one_actionable_line_per_failure(): void
    {
        $this->pulseOn('amqp://guest:guest@nohost:5672/%2f');

        $command = new CommandTester((new Application(self::bootKernel()))->find('pulse:doctor'));
        $status = $command->execute([]);

        self::assertSame(1, $status);
        self::assertStringContainsString('[FAIL] transport default: cannot connect to nohost:5672', $command->getDisplay());
        self::assertStringContainsString('-> Check the host and port in PULSE_TRANSPORT_DSN', $command->getDisplay());
        self::assertStringContainsString('Some checks failed.', $command->getDisplay());
    }

    public function test_a_database_transport_states_that_its_channels_cannot_be_checked_and_shows_the_configured_name(): void
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        [$status, $checks] = $this->diagnose();

        self::assertSame(0, $status, json_encode($checks));
        self::assertSame('ok', $checks['login default']['status']);
        self::assertSame('info', $checks['service orders']['status']);
        self::assertSame(sprintf("queue '%s' cannot be checked on a database transport, it is created by the first message", $this->serviceChannels['orders']), $checks['service orders']['message']);
    }

    private function setUpInternalDatabase(): void
    {
        $this->setUpPulseInternalStorage(static::$kernel ?? self::bootKernel());
    }

    private function pulseOn(string $dsn): void
    {
        $suffix = uniqid();
        $this->pulseChannel = 'pulse_' . $suffix;
        $this->queue = 'orders_' . $suffix;
        $this->serviceChannels = ['orders' => $this->queue];
        $this->configurePulse([
            'PULSE_CHANNEL' => $this->pulseChannel,
            'PULSE_TRANSPORT_DSN' => $dsn,
            'PULSE_SERVICES' => 'orders:' . $this->queue,
        ]);
    }

    private function serviceHavingCreatedItsQueue(): void
    {
        $connection = new AmqpExtConnectionFactory(['dsn' => self::BROKER_DSN]);
        $orders = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [AmqpExtConnectionFactory::class => $connection, AmqpLibConnectionFactory::class => $connection],
            extensionObjects: [
                AmqpBackedMessageChannelBuilder::create($this->queue)->withReceiveTimeout(1),
                AmqpBackedMessageChannelBuilder::create($this->pulseChannel),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: $this->pulseChannel),
                ServiceConfiguration::createWithDefaults()->withServiceName('orders'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
            ],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $orders->run($this->queue, ExecutionPollingMetadata::createWithTestingSetup()->withMemoryLimitInMegabytes(4096));
    }

    /**
     * @return array{int, array<string, array{status: string, message: string, fix?: string}>}
     */
    private function diagnose(): array
    {
        $kernel = static::$kernel ?? self::bootKernel();
        $command = new CommandTester((new Application($kernel))->find('pulse:doctor'));
        $status = $command->execute(['--format' => 'json']);

        $checks = [];
        foreach (json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['checks'] as $check) {
            $checks[$check['name']] = $check;
        }

        return [$status, $checks];
    }

    public function test_the_banner_names_the_transport_the_services_and_the_health_paths(): void
    {
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => self::BROKER_DSN, 'PULSE_SERVICES' => 'orders,billing#billing_queue', 'PULSE_CONNECTION_LEGACY_DSN' => 'redis://redis:6379', 'PULSE_CONNECTION_LEGACY_REPLY_CHANNEL' => 'pulse_legacy']);

        $command = new CommandTester((new Application(self::bootKernel()))->find('pulse:banner'));
        $command->execute([]);

        self::assertSame("Pulse: transport default=amqp, legacy=redis\nPulse: services orders (orders), billing (billing_queue)\nPulse: UI on port 8080, liveness /healthz, readiness /readyz\n", $command->getDisplay());
    }
}
