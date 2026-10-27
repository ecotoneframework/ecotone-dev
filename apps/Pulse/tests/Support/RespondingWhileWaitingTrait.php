<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateTimeImmutable;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\StaticPsrClock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

trait RespondingWhileWaitingTrait
{
    private function awaitRepliesFor(int $seconds): void
    {
        $_SERVER['PULSE_AWAIT_TIMEOUT'] = $_ENV['PULSE_AWAIT_TIMEOUT'] = (string) $seconds;
        putenv('PULSE_AWAIT_TIMEOUT=' . $seconds);
    }

    /**
     * @param array<string, FlowTestSupport> $responders
     * @param array<string, int> $answeringAfterSeconds
     */
    private function respondWhileWaiting(KernelInterface $kernel, array $responders, array $answeringAfterSeconds = []): StaticPsrClock
    {
        $clock = $this->pinnedWaitClock();
        $startedAt = DateTimeImmutable::createFromInterface($clock->now());
        $clock->whenWaiting(function (DateTimeImmutable $now) use ($kernel, $responders, $answeringAfterSeconds, $startedAt): void {
            $replies = 0;
            foreach ($responders as $service => $responder) {
                if ($now < $startedAt->modify(sprintf('+%d seconds', $answeringAfterSeconds[$service] ?? 0))) {
                    continue;
                }
                $this->runService($responder, $service);
                $replies += count($responder->popRecordedMessagesFrom($this->pulseChannel));
            }
            if ($replies > 0) {
                $this->processReplies($kernel, $replies);
            }
        });

        return $clock;
    }

    private function processReplies(KernelInterface $kernel, int $replies): void
    {
        (new CommandTester((new Application($kernel))->find('ecotone:run')))->execute(['consumerName' => $this->pulseChannel, '--handledMessageLimit' => (string) $replies]);
    }
}
