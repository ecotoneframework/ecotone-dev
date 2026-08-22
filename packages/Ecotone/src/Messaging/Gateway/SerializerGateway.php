<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Gateway;

/**
 * Interface SerializerGateway
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @TODO 2.0 change namespace and name to SerializerGateway
 */
/**
 * licence Apache-2.0
 */
interface SerializerGateway
{
    public function convertFromPHP($data, string $targetMediaType);

    public function convertToPHP($data, string $sourceMediaType, string $targetType);
}
