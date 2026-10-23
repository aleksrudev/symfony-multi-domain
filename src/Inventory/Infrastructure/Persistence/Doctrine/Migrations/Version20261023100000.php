<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create inventory.reservations and inventory.reservation_items tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inventory.reservations (
            order_id VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL,
            rejection_reason VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT inventory_reservations_pkey PRIMARY KEY (order_id)
        )');

        $this->addSql('CREATE INDEX idx_inventory_reservations_status ON inventory.reservations (status)');

        $this->addSql('CREATE TABLE inventory.reservation_items (
            id SERIAL NOT NULL,
            order_id VARCHAR(64) NOT NULL,
            product_id VARCHAR(64) NOT NULL,
            quantity INT NOT NULL,
            CONSTRAINT inventory_reservation_items_pkey PRIMARY KEY (id),
            CONSTRAINT inventory_reservation_items_order_product_unique UNIQUE (order_id, product_id),
            CONSTRAINT fk_inventory_reservation_items_order FOREIGN KEY (order_id) REFERENCES inventory.reservations (order_id) ON DELETE CASCADE,
            CONSTRAINT check_reservation_item_quantity_gt_zero CHECK (quantity > 0)
        )');

        $this->addSql('CREATE INDEX idx_inventory_reservation_items_order_id ON inventory.reservation_items (order_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS inventory.reservation_items');
        $this->addSql('DROP TABLE IF EXISTS inventory.reservations');
    }
}

