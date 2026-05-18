<?php

/*
 * licence Apache-2.0
 */

use Ecotone\SymfonyBundle\EcotoneSymfonyBundle;

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    EcotoneSymfonyBundle::class => ['all' => true],
];
