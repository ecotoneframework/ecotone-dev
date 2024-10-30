<?php

declare(strict_types=1);

namespace Test\Ecotone\GDPR\Fixture\User;

use Ecotone\GDPR\PersonalData\PersonalDataEncryptionConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

final class Configuration
{
    #[ServiceContext]
    public function gdprConfiguration(): PersonalDataEncryptionConfiguration
    {
        return PersonalDataEncryptionConfiguration::createWithDefaults()
            ->useByDefault();
    }
}
