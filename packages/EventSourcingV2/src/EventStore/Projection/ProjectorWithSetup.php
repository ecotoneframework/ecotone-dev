<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Projection;

use Ecotone\EventSourcingV2\EventStore\Projection\Projector;

interface ProjectorWithSetup extends Projector
{
    public function setUp(): void;
    public function tearDown(): void;
}