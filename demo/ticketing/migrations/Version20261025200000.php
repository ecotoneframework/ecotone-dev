<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seat map and sales ledger projections of each concert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE concert_seat_map (concert_id UUID NOT NULL, seat_id VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, order_id UUID DEFAULT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (concert_id, seat_id))');
        $this->addSql('CREATE TABLE concert_sales_ledger (concert_id UUID NOT NULL, tier VARCHAR(16) NOT NULL, seats_sold INT NOT NULL, revenue_cents BIGINT NOT NULL, PRIMARY KEY (concert_id, tier))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE concert_sales_ledger');
        $this->addSql('DROP TABLE concert_seat_map');
    }
}
