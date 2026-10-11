<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App;

use Composer\Autoload\ClassLoader;

use function dirname;

use Ecotone\Api\Messaging\ConfiguredMessagingSystem;

use function getenv;

use LogicException;
use ReflectionClass;

use function str_contains;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Test\Ecotone\EventSourcing\Projecting\App\Tooling\WaitBeforeExecutingProjectionInterceptor;

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
    ): Process {
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

        $process = new Process(
            command: $command,
            env: [...getenv(), 'COMPOSER_AUTOLOAD_FILE' => $this->getCurrentComposerAutoloadPath()],
            input: $input = new InputStream(),
            timeout: 10,
        );
        $this->processesInputs[spl_object_id($process)] = $input;

        $process->start();

        return $process;
    }

    public function waitingToExecuteProjection($type, string $output): bool
    {
        return str_contains($output, WaitBeforeExecutingProjectionInterceptor::getMessage());
    }

    public function continueProcess(Process $process): void
    {
        $inputStream = $this->processesInputs[spl_object_id($process)] ?? null;
        if ($inputStream === null) {
            throw new LogicException('Process not found');
        }
        $inputStream->write("\n");
    }

    private function getCurrentComposerAutoloadPath(): string
    {
        $reflect = new ReflectionClass(ClassLoader::class);
        return dirname($reflect->getFileName(), 2) . '/autoload.php';
    }
}
