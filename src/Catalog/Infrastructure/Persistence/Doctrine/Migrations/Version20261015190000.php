<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create catalog schema and products table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS catalog');
        $this->addSql('CREATE TABLE catalog.products (id VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, price_amount INT NOT NULL, currency VARCHAR(3) NOT NULL, published BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN catalog.products.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_catalog_products_published ON catalog.products (published, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE catalog.products');
    }
}

