<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251003123706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Skip this migration - tables will be created by the next migration
        // This migration was trying to modify tables that don't exist yet
    }

    public function down(Schema $schema): void
    {
        // Skip this migration - tables will be created by the next migration
        // This migration was trying to modify tables that don't exist yet
    }
}
