<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
use App\Settings\Runtime;
use App\Tests\Support\PulseEnvironmentTrait;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Amqp\AmqpLibConnectionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 */
final class RuntimeTest extends KernelTestCase
{
    use PulseEnvironmentTrait;

    public function test_pulse_runs_on_php_unless_told_otherwise(): void
    {
        self::assertSame(Runtime::Php, Runtime::fromEnvironment([]));
        self::assertSame(Runtime::Php, Runtime::fromEnvironment(['PULSE_RUNTIME' => ' ']));
    }

    public function test_an_unknown_runtime_is_refused_naming_both_runtimes(): void
    {
        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage('PULSE_RUNTIME "roadrunner" is not a runtime Pulse runs on. Set it to "php", the default, which serves Pulse with FrankenPHP and consumes each reply channel in its own process, or to "swoole", which serves Pulse with OpenSwoole and consumes each reply channel on PULSE_CONSUMER_COROUTINES coroutines.');

        Runtime::fromEnvironment(['PULSE_RUNTIME' => 'roadrunner']);
    }

    public function test_the_broker_is_read_with_the_extension_on_php(): void
    {
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672']);

        self::bootKernel();

        self::assertInstanceOf(AmqpExtConnectionFactory::class, self::getContainer()->get(AmqpExtConnectionFactory::class));
    }

    public function test_the_broker_is_read_with_the_library_coroutines_can_wait_on_when_running_on_swoole(): void
    {
        $this->configurePulse(['PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672', 'PULSE_RUNTIME' => 'swoole']);

        self::bootKernel();

        self::assertInstanceOf(AmqpLibConnectionFactory::class, self::getContainer()->get(AmqpExtConnectionFactory::class));
    }
}
