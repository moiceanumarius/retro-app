<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251002170313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE retrospective_action ADD sprint_id INT DEFAULT NULL, ADD context_type VARCHAR(20) DEFAULT NULL, ADD context_id INT DEFAULT NULL, ADD team_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE retrospective_action ADD CONSTRAINT FK_704A2CF9296CD8AE FOREIGN KEY (team_id) REFERENCES team (id)');
        $this->addSql('CREATE INDEX IDX_704A2CF9296CD8AE ON retrospective_action (team_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE retrospective_action DROP FOREIGN KEY FK_704A2CF9296CD8AE');
        $this->addSql('DROP INDEX IDX_704A2CF9296CD8AE ON retrospective_action');
        $this->addSql('ALTER TABLE retrospective_action DROP sprint_id, DROP context_type, DROP context_id, DROP team_id');
    }
}
