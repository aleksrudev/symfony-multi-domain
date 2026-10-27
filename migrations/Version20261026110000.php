<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications schema and notifications table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS notifications');
        $this->addSql('CREATE TABLE notifications.notifications (
            id UUID NOT NULL,
            order_id VARCHAR(36) NOT NULL,
            type VARCHAR(50) NOT NULL,
            buyer_id VARCHAR(100) NOT NULL,
            status VARCHAR(30) NOT NULL,
            attempts INT DEFAULT 0 NOT NULL,
            payload JSON NOT NULL,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
            sent_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_notifications_order_type ON notifications.notifications (order_id, type)');
        $this->addSql('CREATE INDEX idx_notifications_order_id ON notifications.notifications (order_id)');
        $this->addSql('CREATE INDEX idx_notifications_created_at ON notifications.notifications (created_at DESC, id ASC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS notifications.notifications');
        $this->addSql('DROP SCHEMA IF EXISTS notifications');
    }
}
