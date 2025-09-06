<?php

namespace Ecotone\Amqp\Transaction;

use Attribute;
use Enqueue\AmqpLib\AmqpConnectionFactory;

#[Attribute]
/**
 * licence Apache-2.0
 */
class AmqpTransaction
{
    public $connectionReferenceNames = [AmqpConnectionFactory::class];
}
