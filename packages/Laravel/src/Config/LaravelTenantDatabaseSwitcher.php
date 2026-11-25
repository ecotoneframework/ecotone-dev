<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Config;

use Ecotone\Api\ExtensionObject\ConnectionReference;
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Execution\HeldByOneExecutionAtATime;
use Illuminate\Support\Facades\Config;

/**
 * licence Apache-2.0
 */
final class LaravelTenantDatabaseSwitcher
{
    private HeldByOneExecutionAtATime $defaultConnectionOfLaravel;

    public function __construct(
        private string $defaultConnectionName,
    ) {
        $this->defaultConnectionOfLaravel = new HeldByOneExecutionAtATime();
    }

    public static function create(): self
    {
        return new self(Config::get('database.default'));
    }

    public function switchOn(string|ConnectionReference $activatedConnection): void
    {
        if ($activatedConnection instanceof LaravelConnectionReference) {
            if ($this->defaultConnectionOfLaravel->isHeldByAnotherExecution()) {
                throw ConfigurationException::create(
                    'A tenant connection is activated while another message, handled at once in this process on a coroutine or a fiber, has its tenant active. '
                    . "Laravel's default database connection is one setting for the whole process, so the Eloquent models and DB facade calls of both messages would go to the tenant activated last. "
                    . 'Handle one message at a time: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not suspend a fiber while it handles a message.'
                );
            }
            $this->defaultConnectionOfLaravel->hold();
            Config::set('database.default', $activatedConnection->laravelConnectionName);
        }
    }

    public function switchOff(): void
    {
        if ($this->defaultConnectionOfLaravel->isHeldByAnotherExecution()) {
            return;
        }
        $this->defaultConnectionOfLaravel->release();
        Config::set('database.default', $this->defaultConnectionName);
    }
}
