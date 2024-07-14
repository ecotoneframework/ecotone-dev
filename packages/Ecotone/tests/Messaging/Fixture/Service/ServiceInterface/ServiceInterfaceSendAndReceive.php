<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

/**
 * Interface ServiceInterfaceWithRequestAndReply
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface ServiceInterfaceSendAndReceive
{
    public function getById(int $id): ?string;
}
