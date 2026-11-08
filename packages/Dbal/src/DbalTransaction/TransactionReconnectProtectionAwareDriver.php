<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbalTransaction;

/**
 * licence Apache-2.0
 */
interface TransactionReconnectProtectionAwareDriver
{
    public function leaveTransactionBoundary(): void;
}
