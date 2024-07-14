<?php

namespace Test\Ecotone\Messaging\Fixture\Conversion\Extra;

/**
 * Class GetFavouriteTrait
 * @package Fixture\Conversion\Extra
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
trait GetFavouriteTrait
{
    public function getYourVeryBestFavourite(?Favourite $favourite): ?Favourite
    {
    }

    public function getLessFavourite(Favourite $favourite): Favourite
    {
    }
}
