<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rejection_reason, next_check_at and reconciliation index to ordering.orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordering.orders ADD rejection_reason VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordering.orders ADD next_check_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN ordering.orders.next_check_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_ordering_orders_pending_check ON ordering.orders (status, next_check_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS ordering.idx_ordering_orders_pending_check');
        $this->addSql('ALTER TABLE ordering.orders DROP COLUMN IF EXISTS next_check_at');
        $this->addSql('ALTER TABLE ordering.orders DROP COLUMN IF EXISTS rejection_reason');
    }
}
