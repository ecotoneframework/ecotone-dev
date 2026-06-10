<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\SharedConnection;

use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;

/**
 * licence Apache-2.0
 */
final class SharedConnectionItem
{
    use IsDatabaseModel;

    public PrimaryKey $id;
    public string $name;
}
