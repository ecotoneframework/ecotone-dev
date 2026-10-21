<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

foreach (glob(dirname(__DIR__) . '/var/mermaid/*.mmd') ?: [] as $staleDiagram) {
    unlink($staleDiagram);
}
