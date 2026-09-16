<?php

namespace Ecotone\Amqp\Transaction;

use Attribute;
use Ecotone\Api\Amqp\AmqpConnectionReference;

#[Attribute]
/**
 * licence Apache-2.0
 */
class AmqpTransaction
{
    public $connectionReferenceNames = [AmqpConnectionReference::DEFAULT];
}
