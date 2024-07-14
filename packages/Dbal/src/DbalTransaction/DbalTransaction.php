<?php

namespace Ecotone\Dbal\DbalTransaction;

use Attribute;
use Enqueue\Dbal\DbalConnectionFactory;

#[Attribute]
/**
 * licence Apache-2.0
 */
class DbalTransaction
{
    public $connectionReferenceNames = [DbalConnectionFactory::class];
}
