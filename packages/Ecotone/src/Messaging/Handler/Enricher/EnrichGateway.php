<?php

namespace Ecotone\Messaging\Handler\Enricher;

use Ecotone\Messaging\Message;

/**
 * Interface EnrichReferenceService
 * @package Ecotone\Messaging\Handler\Transformer
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 * @internal
 */
interface EnrichGateway
{
    public function execute(Message $message): ?Message;
}
