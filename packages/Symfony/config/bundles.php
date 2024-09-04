<?php

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Ecotone\SymfonyBundle\EcotoneSymfonyBundle;
use Fixture\TestBundle;
use FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    TestBundle::class => ['all' => true],
    EcotoneSymfonyBundle::class => ['all' => true],
    FriendsOfBehatSymfonyExtensionBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    MonologBundle::class => ['test_monolog_integration' => true],
];
