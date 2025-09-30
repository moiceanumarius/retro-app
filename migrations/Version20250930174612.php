<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250930174612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE retrospective_group (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, position_x INT NOT NULL, position_y INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, retrospective_id INT NOT NULL, INDEX IDX_335BDF2EBBC656A0 (retrospective_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE retrospective_group ADD CONSTRAINT FK_335BDF2EBBC656A0 FOREIGN KEY (retrospective_id) REFERENCES retrospective (id)');
        $this->addSql('ALTER TABLE retrospective_item ADD group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE retrospective_item ADD CONSTRAINT FK_5A79C066FE54D947 FOREIGN KEY (group_id) REFERENCES retrospective_group (id)');
        $this->addSql('CREATE INDEX IDX_5A79C066FE54D947 ON retrospective_item (group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE retrospective_group DROP FOREIGN KEY FK_335BDF2EBBC656A0');
        $this->addSql('DROP TABLE retrospective_group');
        $this->addSql('ALTER TABLE retrospective_item DROP FOREIGN KEY FK_5A79C066FE54D947');
        $this->addSql('DROP INDEX IDX_5A79C066FE54D947 ON retrospective_item');
        $this->addSql('ALTER TABLE retrospective_item DROP group_id');
    }
}
