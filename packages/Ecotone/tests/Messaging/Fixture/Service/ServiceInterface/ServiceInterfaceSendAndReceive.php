<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

/**
 * Interface ServiceInterfaceWithRequestAndReply
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface ServiceInterfaceSendAndReceive
{
    public function getById(int $id): ?string;
}
