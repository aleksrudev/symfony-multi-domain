<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ordering schema, orders, order items and idempotency tables with unique partial draft index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS ordering');
        $this->addSql('CREATE TABLE ordering.orders (
            id VARCHAR(64) NOT NULL,
            buyer_id VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN ordering.orders.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN ordering.orders.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX ordering_buyer_draft_unique ON ordering.orders (buyer_id) WHERE status = \'draft\'');
        $this->addSql('CREATE INDEX idx_ordering_orders_buyer ON ordering.orders (buyer_id, created_at)');

        $this->addSql('CREATE TABLE ordering.order_items (
            id SERIAL NOT NULL,
            order_id VARCHAR(64) NOT NULL,
            product_id VARCHAR(64) NOT NULL,
            quantity INT NOT NULL,
            PRIMARY KEY(id),
            CONSTRAINT fk_ordering_order_items_order FOREIGN KEY (order_id) REFERENCES ordering.orders (id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX ordering_order_items_order_product_unique ON ordering.order_items (order_id, product_id)');

        $this->addSql('CREATE TABLE ordering.idempotency_keys (
            key VARCHAR(128) NOT NULL,
            buyer_id VARCHAR(64) NOT NULL,
            operation VARCHAR(64) NOT NULL,
            request_hash VARCHAR(64) NOT NULL,
            response_payload TEXT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(key, buyer_id)
        )');
        $this->addSql('COMMENT ON COLUMN ordering.idempotency_keys.created_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ordering.order_items');
        $this->addSql('DROP TABLE IF EXISTS ordering.idempotency_keys');
        $this->addSql('DROP TABLE IF EXISTS ordering.orders');
    }
}
