<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\PulseServicesTrait;
use App\Tests\Support\ReleasingWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class ReadinessTest extends ReleasingWebTestCase
{
    use PulseServicesTrait;

    public function test_pulse_is_not_ready_before_the_reply_consumer_and_the_refresh_have_reported(): void
    {
        $client = $this->pulseClient();

        $client->request('GET', '/readyz');

        self::assertResponseStatusCodeSame(503);
        $checks = $this->checks($client);
        self::assertTrue($checks['internal database']['ok']);
        self::assertFalse($checks['reply consumer ' . $this->pulseChannel]['ok']);
        self::assertStringContainsString('has not reported yet. Check that "ecotone:run ' . $this->pulseChannel . '" is running', $checks['reply consumer ' . $this->pulseChannel]['message']);
        self::assertFalse($checks['periodic refresh']['ok']);
        self::assertStringContainsString('pulse:refresh', $checks['periodic refresh']['message']);
    }

    public function test_pulse_is_ready_once_the_consumer_and_the_refresh_have_reported(): void
    {
        $client = $this->pulseClient();

        $this->consoleCommand($client, 'pulse:heartbeat', ['processes' => ['consumer:' . $this->pulseChannel]]);
        $this->consoleCommand($client, 'pulse:refresh', ['--ticks' => 1]);
        $client->request('GET', '/readyz');

        self::assertResponseIsSuccessful();
        self::assertSame('ready', json_decode($client->getResponse()->getContent(), true)['status']);
    }

    public function test_pulse_stops_being_ready_when_the_consumer_stops_reporting(): void
    {
        $client = $this->pulseClient();
        $this->consoleCommand($client, 'pulse:heartbeat', ['processes' => ['consumer:' . $this->pulseChannel]]);
        $this->consoleCommand($client, 'pulse:refresh', ['--ticks' => 1]);

        $this->advanceTimeBy(70);
        $this->consoleCommand($client, 'pulse:refresh', ['--ticks' => 1]);
        $client->request('GET', '/readyz');

        self::assertResponseStatusCodeSame(503);
        $checks = $this->checks($client);
        self::assertFalse($checks['reply consumer ' . $this->pulseChannel]['ok']);
        self::assertStringContainsString('last reported 70 seconds ago', $checks['reply consumer ' . $this->pulseChannel]['message']);
        self::assertTrue($checks['periodic refresh']['ok']);
    }

    public function test_the_liveness_probe_stays_ok_while_pulse_is_not_ready(): void
    {
        $client = $this->pulseClient();

        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
    }

    private function pulseClient(): KernelBrowser
    {
        $this->configurePulseWithServices('orders');
        $client = static::createClient();
        $client->disableReboot();
        $this->setUpPulseDatabase($client->getKernel());

        return $client;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function consoleCommand(KernelBrowser $client, string $name, array $input): void
    {
        $command = new CommandTester((new Application($client->getKernel()))->find($name));
        $command->execute($input);
        $command->assertCommandIsSuccessful();
    }

    /**
     * @return array<string, array{ok: bool, message: string}>
     */
    private function checks(KernelBrowser $client): array
    {
        $checks = [];
        foreach (json_decode($client->getResponse()->getContent(), true)['checks'] as $check) {
            $checks[$check['name']] = $check;
        }

        return $checks;
    }
}
