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
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization_members CHANGE joined_at joined_at DATETIME NOT NULL, CHANGE left_at left_at DATETIME DEFAULT NULL, CHANGE is_active is_active TINYINT(1) NOT NULL, CHANGE notes notes LONGTEXT DEFAULT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id)');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE organization_members RENAME INDEX idx_44c23ae432c8a3de TO IDX_88725ABC32C8A3DE');
        $this->addSql('ALTER TABLE organization_members RENAME INDEX idx_44c23ae4a76ed395 TO IDX_88725ABCA76ED395');
        $this->addSql('ALTER TABLE organization_members RENAME INDEX idx_44c23ae46171f5cf TO IDX_88725ABCA7B4A7E3');
        $this->addSql('ALTER TABLE organizations CHANGE description description LONGTEXT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE is_active is_active TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE organizations ADD CONSTRAINT FK_427C1C7F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE organizations RENAME INDEX idx_427fa1e57e3c61f9 TO IDX_427C1C7F7E3C61F9');
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
        $this->addSql('ALTER TABLE organization_members CHANGE joined_at joined_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE left_at left_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE is_active is_active TINYINT(1) DEFAULT 1 NOT NULL, CHANGE notes notes TEXT DEFAULT NULL, CHANGE expires_at expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE organization_members RENAME INDEX idx_88725abc32c8a3de TO IDX_44C23AE432C8A3DE');
        $this->addSql('ALTER TABLE organization_members RENAME INDEX idx_88725abca76ed395 TO IDX_44C23AE4A76ED395');
        $this->addSql('ALTER TABLE organization_members RENAME INDEX idx_88725abca7b4a7e3 TO IDX_44C23AE46171F5CF');
        $this->addSql('ALTER TABLE organizations DROP FOREIGN KEY FK_427C1C7F7E3C61F9');
        $this->addSql('ALTER TABLE organizations CHANGE description description TEXT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE is_active is_active TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE organizations RENAME INDEX idx_427c1c7f7e3c61f9 TO IDX_427FA1E57E3C61F9');
        $this->addSql('ALTER TABLE team DROP FOREIGN KEY FK_C4E0A61F32C8A3DE');
        $this->addSql('DROP INDEX IDX_C4E0A61F32C8A3DE ON team');
        $this->addSql('ALTER TABLE team DROP organization_id');
    }
}
