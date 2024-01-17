<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

final class FetchMode
{
    public const ASSOCIATIVE = 0;
    public const FIRST_COLUMN = 1;
    public const FIRST_ROW = 2;
    public const FIRST_COLUMN_OF_FIRST_ROW = 3;
    public const ITERATE = 4;


    private function __construct()
    {

    }
}
