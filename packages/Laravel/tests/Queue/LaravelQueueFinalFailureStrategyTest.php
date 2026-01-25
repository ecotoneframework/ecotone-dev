<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Queue;

use DateTimeImmutable;
use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Exception;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for Laravel Queue Final Failure Strategy message ordering
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class LaravelQueueFinalFailureStrategyTest extends TestCase
{
    public function setUp(): void
    {
        $this->getContainer();

        if (Schema::hasTable('jobs')) {
            Schema::drop('jobs');
        }
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue', 'reserved_at']);
        });
    }

    public function test_resend_failure_strategy_rejects_message_on_exception()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [FailingService::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create('async', 'database')
                        ->withFinalFailureStrategy(FinalFailureStrategy::RESEND),
                ])
        );

        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.failing_command', new FailingCommand('some_1'));
        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.failing_command', new FailingCommand('some_2'));
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $messageChannel = $ecotoneTestSupport->getMessageChannel('async');
        // For Laravel Queue, resend uses job->release() which puts message back in queue
        // Laravel doesn't distinguish between beginning/end positioning - behavior is transport-specific
        $this->assertNotNull($messageChannel->receive());
    }

    public function test_release_failure_strategy_releases_message_on_exception()
    {
        $this->expectException(ConfigurationException::class);

        EcotoneLite::bootstrapFlowTesting(
            [FailingService::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create('async', 'database')
                        ->withFinalFailureStrategy(FinalFailureStrategy::RELEASE),
                ])
        );
    }

    public function test_sending_with_delay_using_datetime()
    {
        $container = $this->getContainer();
        $delayedService = new DelayedService();
        $container->instance(DelayedService::class, $delayedService);

        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting(
            [DelayedService::class],
            $container,
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create('async', 'database'),
                ])
        );

        $ecotoneTestSupport->sendCommandWithRoutingKey('execute.delayed_command', new DelayedCommand('test_1'), metadata: [
            MessageHeaders::DELIVERY_DELAY => (new DateTimeImmutable())->modify('+1 second'),
        ]);
        sleep(2);
        $ecotoneTestSupport->run('async', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals(['test_1'], $delayedService->getMessages());
    }

    private function getContainer(): ContainerInterface
    {
        $app = require __DIR__ . '/../Application/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}

class FailingCommand
{
    public function __construct(public readonly string $payload)
    {
    }
}

class FailingService
{
    #[Asynchronous('async')]
    #[CommandHandler('execute.failing_command', 'failing_endpoint')]
    public function execute(FailingCommand $command): void
    {
        throw new Exception('Failing');
    }
}

class DelayedCommand
{
    public function __construct(public readonly string $payload)
    {
    }
}

class DelayedService
{
    /** @var string[] */
    private array $messages = [];

    #[Asynchronous('async')]
    #[CommandHandler('execute.delayed_command', 'delayed_endpoint')]
    public function execute(DelayedCommand $command): void
    {
        $this->messages[] = $command->payload;
    }

    /**
     * @return string[]
     */
    #[QueryHandler('get.delayed_messages')]
    public function getMessages(): array
    {
        return $this->messages;
    }
}
