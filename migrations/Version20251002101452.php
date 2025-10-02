<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251002101452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE votes DROP FOREIGN KEY `FK_518B7ACF9F9F1305`');
        $this->addSql('DROP INDEX unique_user_item_vote ON votes');
        $this->addSql('ALTER TABLE votes ADD retrospective_group_id INT DEFAULT NULL, CHANGE retrospective_item_id retrospective_item_id INT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE votes ADD CONSTRAINT FK_518B7ACFC8386C2E FOREIGN KEY (retrospective_item_id) REFERENCES retrospective_item (id)');
        $this->addSql('ALTER TABLE votes ADD CONSTRAINT FK_518B7ACFAE8BFF45 FOREIGN KEY (retrospective_group_id) REFERENCES retrospective_group (id)');
        $this->addSql('CREATE INDEX IDX_518B7ACFAE8BFF45 ON votes (retrospective_group_id)');
        $this->addSql('ALTER TABLE votes RENAME INDEX idx_518b7acf9f9f1305 TO IDX_518B7ACFC8386C2E');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE votes DROP FOREIGN KEY FK_518B7ACFC8386C2E');
        $this->addSql('ALTER TABLE votes DROP FOREIGN KEY FK_518B7ACFAE8BFF45');
        $this->addSql('DROP INDEX IDX_518B7ACFAE8BFF45 ON votes');
        $this->addSql('ALTER TABLE votes DROP retrospective_group_id, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE retrospective_item_id retrospective_item_id INT NOT NULL');
        $this->addSql('ALTER TABLE votes ADD CONSTRAINT `FK_518B7ACF9F9F1305` FOREIGN KEY (retrospective_item_id) REFERENCES retrospective_item (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX unique_user_item_vote ON votes (user_id, retrospective_item_id)');
        $this->addSql('ALTER TABLE votes RENAME INDEX idx_518b7acfc8386c2e TO IDX_518B7ACF9F9F1305');
    }
}
