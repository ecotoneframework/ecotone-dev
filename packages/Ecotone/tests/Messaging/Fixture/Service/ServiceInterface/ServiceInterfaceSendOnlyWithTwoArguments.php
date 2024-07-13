<?php

namespace Test\Ecotone\Messaging\Fixture\Service\ServiceInterface;

/**
 * Interface ServiceInterface
 * @package Test\Ecotone\Messaging\Fixture\Service
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface ServiceInterfaceSendOnlyWithTwoArguments
{
    public function sendMail(int $personId, string $content): void;
}
