<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * licence Apache-2.0
 */
trait RestoresGlobalHandlers
{
    /** @var list<callable> */
    private array $errorHandlersBeforeTest = [];
    /** @var list<callable> */
    private array $exceptionHandlersBeforeTest = [];

    #[Before]
    public function rememberGlobalHandlers(): void
    {
        $this->errorHandlersBeforeTest = self::unwindErrorHandlers();
        $this->exceptionHandlersBeforeTest = self::unwindExceptionHandlers();
        $this->reinstallRememberedHandlers();
    }

    #[After]
    public function restoreGlobalHandlers(): void
    {
        self::unwindErrorHandlers();
        self::unwindExceptionHandlers();
        $this->reinstallRememberedHandlers();
    }

    private function reinstallRememberedHandlers(): void
    {
        foreach ($this->errorHandlersBeforeTest as $handler) {
            set_error_handler($handler);
        }
        foreach ($this->exceptionHandlersBeforeTest as $handler) {
            set_exception_handler($handler);
        }
    }

    /** @return list<callable> */
    private static function unwindErrorHandlers(): array
    {
        $handlers = [];
        while (($handler = set_error_handler(static fn (): bool => false)) !== null) {
            restore_error_handler();
            restore_error_handler();
            $handlers[] = $handler;
        }
        restore_error_handler();

        return array_reverse($handlers);
    }

    /** @return list<callable> */
    private static function unwindExceptionHandlers(): array
    {
        $handlers = [];
        while (($handler = set_exception_handler(static fn () => null)) !== null) {
            restore_exception_handler();
            restore_exception_handler();
            $handlers[] = $handler;
        }
        restore_exception_handler();

        return array_reverse($handlers);
    }
}
