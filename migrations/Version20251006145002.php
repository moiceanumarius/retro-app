<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251006145002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_user CHANGE is_active is_active TINYINT(1) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL, CHANGE last_login_at last_login_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_user RENAME INDEX uniq_admin_user_email TO UNIQ_AD8A54A9E7927C74');
        
        // Add foreign keys only if they don't exist
        $this->addSql('SET @sql = (SELECT IF(
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = "organization_members" 
             AND CONSTRAINT_NAME = "FK_88725ABC32C8A3DE") = 0,
            "ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id)",
            "SELECT 1"
        ));
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;');
        
        $this->addSql('SET @sql = (SELECT IF(
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = "organization_members" 
             AND CONSTRAINT_NAME = "FK_88725ABCA76ED395") = 0,
            "ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)",
            "SELECT 1"
        ));
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;');
        
        $this->addSql('SET @sql = (SELECT IF(
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = "organization_members" 
             AND CONSTRAINT_NAME = "FK_88725ABCA7B4A7E3") = 0,
            "ALTER TABLE organization_members ADD CONSTRAINT FK_88725ABCA7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES user (id)",
            "SELECT 1"
        ));
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;');
        
        $this->addSql('SET @sql = (SELECT IF(
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = "organizations" 
             AND CONSTRAINT_NAME = "FK_427C1C7F7E3C61F9") = 0,
            "ALTER TABLE organizations ADD CONSTRAINT FK_427C1C7F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)",
            "SELECT 1"
        ));
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;');
        
        $this->addSql('ALTER TABLE retrospective CHANGE vote_numbers vote_numbers INT NOT NULL');
        
        $this->addSql('SET @sql = (SELECT IF(
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = "team_invitation_used_by" 
             AND CONSTRAINT_NAME = "FK_4B8283E0D63F5F61") = 0,
            "ALTER TABLE team_invitation_used_by ADD CONSTRAINT FK_4B8283E0D63F5F61 FOREIGN KEY (team_invitation_id) REFERENCES team_invitation (id) ON DELETE CASCADE",
            "SELECT 1"
        ));
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;');
        
        $this->addSql('SET @sql = (SELECT IF(
            (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = "team_invitation_used_by" 
             AND CONSTRAINT_NAME = "FK_4B8283E0A76ED395") = 0,
            "ALTER TABLE team_invitation_used_by ADD CONSTRAINT FK_4B8283E0A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE",
            "SELECT 1"
        ));
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;');
        
        $this->addSql('ALTER TABLE timer_likes DROP FOREIGN KEY `FK_TIMER_LIKES_RETROSPECTIVE`');
        $this->addSql('ALTER TABLE timer_likes DROP FOREIGN KEY `FK_TIMER_LIKES_USER`');
        $this->addSql('ALTER TABLE timer_likes CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE timer_likes ADD CONSTRAINT FK_346EB54BA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE timer_likes ADD CONSTRAINT FK_346EB54BBBC656A0 FOREIGN KEY (retrospective_id) REFERENCES retrospective (id)');
        $this->addSql('ALTER TABLE timer_likes RENAME INDEX idx_timer_likes_user TO IDX_346EB54BA76ED395');
        $this->addSql('ALTER TABLE timer_likes RENAME INDEX idx_timer_likes_retrospective TO IDX_346EB54BBBC656A0');
        $this->addSql('ALTER TABLE user ADD is_active TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_user CHANGE is_active is_active TINYINT(1) DEFAULT 1 NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE last_login_at last_login_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE admin_user RENAME INDEX uniq_ad8a54a9e7927c74 TO UNIQ_ADMIN_USER_EMAIL');
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABC32C8A3DE');
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABCA76ED395');
        $this->addSql('ALTER TABLE organization_members DROP FOREIGN KEY FK_88725ABCA7B4A7E3');
        $this->addSql('ALTER TABLE organizations DROP FOREIGN KEY FK_427C1C7F7E3C61F9');
        $this->addSql('ALTER TABLE retrospective CHANGE vote_numbers vote_numbers INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE team_invitation_used_by DROP FOREIGN KEY FK_4B8283E0D63F5F61');
        $this->addSql('ALTER TABLE team_invitation_used_by DROP FOREIGN KEY FK_4B8283E0A76ED395');
        $this->addSql('ALTER TABLE timer_likes DROP FOREIGN KEY FK_346EB54BA76ED395');
        $this->addSql('ALTER TABLE timer_likes DROP FOREIGN KEY FK_346EB54BBBC656A0');
        $this->addSql('ALTER TABLE timer_likes CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE timer_likes ADD CONSTRAINT `FK_TIMER_LIKES_RETROSPECTIVE` FOREIGN KEY (retrospective_id) REFERENCES retrospective (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE timer_likes ADD CONSTRAINT `FK_TIMER_LIKES_USER` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE timer_likes RENAME INDEX idx_346eb54bbbc656a0 TO IDX_TIMER_LIKES_RETROSPECTIVE');
        $this->addSql('ALTER TABLE timer_likes RENAME INDEX idx_346eb54ba76ed395 TO IDX_TIMER_LIKES_USER');
        $this->addSql('ALTER TABLE user DROP is_active');
    }
}
