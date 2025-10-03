<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251003212825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE organization_members (id INT AUTO_INCREMENT NOT NULL, joined_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, is_active TINYINT(1) NOT NULL, role VARCHAR(50) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, expires_at DATETIME DEFAULT NULL, organization_id INT NOT NULL, user_id INT NOT NULL, invited_by_id INT DEFAULT NULL, INDEX IDX_88725ABC32C8A3DE (organization_id), INDEX IDX_88725ABCA76ED395 (user_id), INDEX IDX_88725ABCA7B4A7E3 (invited_by_id), UNIQUE INDEX unique_user_organization (user_id, organization_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE organizations (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, is_active TINYINT(1) NOT NULL, owner_id INT NOT NULL, INDEX IDX_427C1C7F7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team_invitation_used_by (team_invitation_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4B8283E0D63F5F61 (team_invitation_id), INDEX IDX_4B8283E0A76ED395 (user_id), PRIMARY KEY (team_invitation_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id)');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE organizations ADD CONSTRAINT FK_427C1C7F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE team_invitation_used_by ADD CONSTRAINT FK_4B8283E0D63F5F61 FOREIGN KEY (team_invitation_id) REFERENCES team_invitation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_invitation_used_by ADD CONSTRAINT FK_4B8283E0A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team ADD organization_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE team ADD CONSTRAINT FK_C4E0A61F32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id)');
        $this->addSql('CREATE INDEX IDX_C4E0A61F32C8A3DE ON team (organization_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABC32C8A3DE');
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABCA76ED395');
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABCA7B4A7E3');
        $this->addSql('ALTER TABLE organizations DROP FOREIGN KEY FK_427C1C7F7E3C61F9');
        $this->addSql('ALTER TABLE team_invitation_used_by DROP FOREIGN KEY FK_4B8283E0D63F5F61');
        $this->addSql('ALTER TABLE team_invitation_used_by DROP FOREIGN KEY FK_4B8283E0A76ED395');
        $this->addSql('DROP TABLE organization_members');
        $this->addSql('DROP TABLE organizations');
        $this->addSql('DROP TABLE team_invitation_used_by');
        $this->addSql('ALTER TABLE team DROP FOREIGN KEY FK_C4E0A61F32C8A3DE');
        $this->addSql('DROP INDEX IDX_C4E0A61F32C8A3DE ON team');
        $this->addSql('ALTER TABLE team DROP organization_id');
    }
}
