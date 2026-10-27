<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create inventory schema and stock_positions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS inventory');
        $this->addSql('CREATE TABLE inventory.stock_positions (
            product_id VARCHAR(64) NOT NULL,
            on_hand INT NOT NULL,
            reserved INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT inventory_stock_positions_pkey PRIMARY KEY (product_id),
            CONSTRAINT check_stock_on_hand_gte_zero CHECK (on_hand >= 0),
            CONSTRAINT check_stock_reserved_gte_zero CHECK (reserved >= 0),
            CONSTRAINT check_stock_on_hand_gte_reserved CHECK (on_hand >= reserved)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS inventory.stock_positions');
        $this->addSql('DROP SCHEMA IF EXISTS inventory');
    }
}
