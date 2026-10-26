<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The VIP lounge pass granted to an issued ticket';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issued_tickets ADD vip_lounge_pass BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issued_tickets DROP vip_lounge_pass');
    }
}
