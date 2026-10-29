<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Attribute;

use Attribute;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;

#[Attribute]
/**
 * licence Apache-2.0
 */
final class DbalTransaction
{
    /**
     * @param string[] $connectionReferenceNames
     */
    public function __construct(
        public readonly array $connectionReferenceNames = [DbalConnectionReference::DEFAULT],
    ) {
    }
}
