<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Conversion;

/***
 * Interface Shop
 * @package Test\Ecotone\Messaging\Fixture\Conversion
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface Shop
{
    #[ExampleTestAnnotation]
    public function buy($product): void;
}
