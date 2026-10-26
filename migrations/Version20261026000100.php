<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create independent idempotent consumer receipts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS shared');
        $this->addSql('CREATE TABLE shared.processed_messages (consumer_id VARCHAR(190) NOT NULL, namespace VARCHAR(190) NOT NULL, message_id VARCHAR(190) NOT NULL, processed_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (consumer_id, namespace, message_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared.processed_messages');
    }
}
