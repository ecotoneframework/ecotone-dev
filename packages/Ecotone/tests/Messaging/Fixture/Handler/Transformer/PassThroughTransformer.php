<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Transformer;

use Ecotone\Messaging\Message;

/**
 * Class PassThroughTransformer
 * @package Test\Ecotone\Messaging\Fixture\Handler\Transformer
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
class PassThroughTransformer
{
    /**
     * @param Message $message
     *
     * @return Message
     */
    public function transform(Message $message): Message
    {
        return $message;
    }
}
