<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\EnumHeaderConversion;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\EventHandler;

/**
 * licence Apache-2.0
 */
class Playground
{
    public $typeHintedStringBackedEnum;
    public $nonTypeHintedStringBackedEnum;
    public $typeHintedIntBackedEnum;
    public $nonTypeHintedIntBackedEnum;
    public $typeHintedBasicEnum;
    public $nonTypeHintedBasicEnum;

    #[Asynchronous(channelName: 'async')]
    #[EventHandler(listenTo: 'message', endpointId: 'message.async')]
    public function eventHandler(
        #[Header('typeHintedStringBackedEnum')] StringEnum $typeHintedStringBackedEnum,
        #[Header('nonTypeHintedStringBackedEnum')] $nonTypeHintedStringBackedEnum,
        #[Header('typeHintedIntBackedEnum')] NumericEnum $typeHintedIntBackedEnum,
        #[Header('nonTypeHintedIntBackedEnum')] $nonTypeHintedIntBackedEnum,
        #[Header('typeHintedBasicEnum')] BasicEnum $typeHintedBasicEnum,
        #[Header('nonTypeHintedBasicEnum')] $nonTypeHintedBasicEnum,
    ): void {
        $this->typeHintedStringBackedEnum = $typeHintedStringBackedEnum;
        $this->nonTypeHintedStringBackedEnum = $nonTypeHintedStringBackedEnum;
        $this->typeHintedIntBackedEnum = $typeHintedIntBackedEnum;
        $this->nonTypeHintedIntBackedEnum = $nonTypeHintedIntBackedEnum;
        $this->typeHintedBasicEnum = $typeHintedBasicEnum;
        $this->nonTypeHintedBasicEnum = $nonTypeHintedBasicEnum;
    }
}
