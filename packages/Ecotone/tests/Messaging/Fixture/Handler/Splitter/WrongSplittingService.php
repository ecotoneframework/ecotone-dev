<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Splitter;

/**
 * Class WrongSplittingService
 * @package Test\Ecotone\Messaging\Fixture\Handler\Splitter
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class WrongSplittingService
{
    public function splittingWithReturnString(): string
    {
        return 'some';
    }
}
