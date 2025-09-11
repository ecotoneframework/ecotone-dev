<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Monorepo\Projecting;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\Projecting\Tooling\WaitBeforeExecutingProjectionInterceptor;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

trait ConsoleProcessTrait
{
    /** @var array<string, InputStream> the key is spl_object_id */
    protected array $processesInputs = [];
    protected static function filename(): string
    {
        return __DIR__ . '/console.php';
    }

    protected static function bootEcotone(): ConfiguredMessagingSystem
    {
        return require __DIR__ . '/app.php';
    }

    protected function placeOrder(
        ?string $orderId = null,
        string $product = 'a-book',
        int $quantity = 1,
        bool $shouldFail = false,
        bool $manualCommit = false,
        bool $manualProjection = false,
    ): Process
    {
        $orderId ??= uniqid('order-');
        $command = [
            'php',
            self::filename(),
            'order:place',
            $orderId,
            '--product', $product,
            '--quantity', $quantity,
        ];

        if ($shouldFail) {
            $command[] = '--fail';
        }
        if ($manualCommit) {
            $command[] = '--manual-commit';
        }
        if ($manualProjection) {
            $command[] = '--manual-projection';
        }

        $process = new Process($command, input: $input = new InputStream());
        $this->processesInputs[spl_object_id($process)] = $input;

        $process->start();

        return $process;
    }

    public function waitingToExecuteProjection($type, string $output): bool
    {
        return \str_contains($output, WaitBeforeExecutingProjectionInterceptor::getMessage());
    }

    public function continueProcess(Process $process): void
    {
        $inputStream = $this->processesInputs[spl_object_id($process)] ?? null;
        if ($inputStream === null) {
            throw new \LogicException('Process not found');
        }
        $inputStream->write("\n");
    }
}