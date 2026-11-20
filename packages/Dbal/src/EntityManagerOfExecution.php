<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\ORM\EntityManager;

/**
 * licence Apache-2.0
 */
final class EntityManagerOfExecution
{
    public ?EntityManager $entityManager = null;
}
