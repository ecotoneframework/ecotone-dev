<?php

namespace Ecotone\Dbal\DbalTransaction;

use Attribute;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;

#[Attribute]
/**
 * licence Apache-2.0
 */
class DbalTransaction
{
    public $connectionReferenceNames = [DbalConnectionReference::DEFAULT];
}
