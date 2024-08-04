<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

class PassthroughMessageConverter implements ResultToMessageConverter
{
    public function convertToMessage(Message $requestMessage, mixed $result): ?Message
    {
        return $requestMessage;
    }
}