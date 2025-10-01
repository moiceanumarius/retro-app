<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename discussion step to voting step';
    }

    public function up(Schema $schema): void
    {
        // Update current_step from 'discussion' to 'voting'
        $this->addSql("UPDATE retrospective SET current_step = 'voting' WHERE current_step = 'discussion'");
    }

    public function down(Schema $schema): void
    {
        // Revert voting back to discussion
        $this->addSql("UPDATE retrospective SET current_step = 'discussion' WHERE current_step = 'voting'");
    }
}
