<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250929180246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE retrospective (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL, scheduled_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, team_id INT NOT NULL, facilitator_id INT NOT NULL, INDEX IDX_F54AF71B296CD8AE (team_id), INDEX IDX_F54AF71BEFB37882 (facilitator_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE retrospective_action (id INT AUTO_INCREMENT NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(50) NOT NULL, due_date DATE DEFAULT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, retrospective_id INT NOT NULL, assigned_to_id INT NOT NULL, created_by_id INT NOT NULL, INDEX IDX_704A2CF9BBC656A0 (retrospective_id), INDEX IDX_704A2CF9F4BD7827 (assigned_to_id), INDEX IDX_704A2CF9B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE retrospective_item (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, category VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, votes INT NOT NULL, retrospective_id INT NOT NULL, author_id INT NOT NULL, INDEX IDX_5A79C066BBC656A0 (retrospective_id), INDEX IDX_5A79C066F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE retrospective ADD CONSTRAINT FK_F54AF71B296CD8AE FOREIGN KEY (team_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE retrospective ADD CONSTRAINT FK_F54AF71BEFB37882 FOREIGN KEY (facilitator_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE retrospective_action ADD CONSTRAINT FK_704A2CF9BBC656A0 FOREIGN KEY (retrospective_id) REFERENCES retrospective (id)');
        $this->addSql('ALTER TABLE retrospective_action ADD CONSTRAINT FK_704A2CF9F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE retrospective_action ADD CONSTRAINT FK_704A2CF9B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE retrospective_item ADD CONSTRAINT FK_5A79C066BBC656A0 FOREIGN KEY (retrospective_id) REFERENCES retrospective (id)');
        $this->addSql('ALTER TABLE retrospective_item ADD CONSTRAINT FK_5A79C066F675F31B FOREIGN KEY (author_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE retrospective DROP FOREIGN KEY FK_F54AF71B296CD8AE');
        $this->addSql('ALTER TABLE retrospective DROP FOREIGN KEY FK_F54AF71BEFB37882');
        $this->addSql('ALTER TABLE retrospective_action DROP FOREIGN KEY FK_704A2CF9BBC656A0');
        $this->addSql('ALTER TABLE retrospective_action DROP FOREIGN KEY FK_704A2CF9F4BD7827');
        $this->addSql('ALTER TABLE retrospective_action DROP FOREIGN KEY FK_704A2CF9B03A8386');
        $this->addSql('ALTER TABLE retrospective_item DROP FOREIGN KEY FK_5A79C066BBC656A0');
        $this->addSql('ALTER TABLE retrospective_item DROP FOREIGN KEY FK_5A79C066F675F31B');
        $this->addSql('DROP TABLE retrospective');
        $this->addSql('DROP TABLE retrospective_action');
        $this->addSql('DROP TABLE retrospective_item');
    }
}
