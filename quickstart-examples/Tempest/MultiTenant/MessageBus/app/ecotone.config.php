<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

use Ecotone\Tempest\EcotoneConfig;

/** Multi-tenancy is an Ecotone Enterprise feature, see README.md for how to get a key */
return new EcotoneConfig(
    licenceKey: (string) getenv('ECOTONE_LICENSE_KEY'),
);
