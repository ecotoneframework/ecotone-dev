<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

/**
 * Interface ServiceInterfaceSendAndReceiveWithTwoArguments
 * @package Test\Ecotone\Messaging\Fixture\Service\ServiceInterface
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface ServiceInterfaceSendAndReceiveWithTwoArguments
{
    public function sendMail(string $personId, string $content): string;
}
