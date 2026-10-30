<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tickets issued for sold seats, with their entry codes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE issued_tickets (ticket_number UUID NOT NULL, order_id UUID NOT NULL, concert_id UUID NOT NULL, seat_id VARCHAR(255) NOT NULL, tier VARCHAR(16) NOT NULL, entry_code VARCHAR(16) NOT NULL, issued_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (ticket_number))');
        $this->addSql('CREATE UNIQUE INDEX issued_tickets_order_seat ON issued_tickets (order_id, seat_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE issued_tickets');
    }
}
