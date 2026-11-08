<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Venues and concerts of the ticketing catalogue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE venues (venue_id UUID NOT NULL, name VARCHAR(255) NOT NULL, rows JSON NOT NULL, seats_per_row INT NOT NULL, vip_rows JSON NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (venue_id))');
        $this->addSql('CREATE TABLE concerts (concert_id UUID NOT NULL, venue_id UUID NOT NULL, venue_name VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, standard_price_cents INT NOT NULL, vip_price_cents INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(255) NOT NULL, seats JSON NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (concert_id))');
        $this->addSql('CREATE INDEX concerts_venue_id ON concerts (venue_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE concerts');
        $this->addSql('DROP TABLE venues');
    }
}
