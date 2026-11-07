<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbalTransaction;

/**
 * licence Apache-2.0
 */
interface TransactionBoundaryAwareDriver
{
    public function leaveTransactionBoundary(): void;
}
