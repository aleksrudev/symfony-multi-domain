<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add price and snapshot fields to ordering.orders and ordering.order_items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordering.orders ADD total_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering.orders ADD currency VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering.order_items ADD product_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering.order_items ADD price_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering.order_items ADD currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordering.orders DROP COLUMN IF EXISTS total_amount');
        $this->addSql('ALTER TABLE ordering.orders DROP COLUMN IF EXISTS currency');
        $this->addSql('ALTER TABLE ordering.order_items DROP COLUMN IF EXISTS product_name');
        $this->addSql('ALTER TABLE ordering.order_items DROP COLUMN IF EXISTS price_amount');
        $this->addSql('ALTER TABLE ordering.order_items DROP COLUMN IF EXISTS currency');
    }
}

