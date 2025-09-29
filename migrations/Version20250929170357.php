<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250929170357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE team_invitation (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, role VARCHAR(50) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, accepted_at DATETIME DEFAULT NULL, message LONGTEXT DEFAULT NULL, team_id INT NOT NULL, invited_by_id INT NOT NULL, accepted_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_CFC413675F37A13B (token), INDEX IDX_CFC41367296CD8AE (team_id), INDEX IDX_CFC41367A7B4A7E3 (invited_by_id), INDEX IDX_CFC4136720F699D9 (accepted_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE team_invitation ADD CONSTRAINT FK_CFC41367296CD8AE FOREIGN KEY (team_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE team_invitation ADD CONSTRAINT FK_CFC41367A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE team_invitation ADD CONSTRAINT FK_CFC4136720F699D9 FOREIGN KEY (accepted_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE team_invitation DROP FOREIGN KEY FK_CFC41367296CD8AE');
        $this->addSql('ALTER TABLE team_invitation DROP FOREIGN KEY FK_CFC41367A7B4A7E3');
        $this->addSql('ALTER TABLE team_invitation DROP FOREIGN KEY FK_CFC4136720F699D9');
        $this->addSql('DROP TABLE team_invitation');
    }
}
