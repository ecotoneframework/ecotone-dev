<?php

declare(strict_types=1);

use Tempest\Core\AppConfig;
use Tempest\Core\Environment;

return new AppConfig(
    environment: Environment::TESTING,
    debug: true,
);
