<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250929135218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE permissions (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, code VARCHAR(50) NOT NULL, resource VARCHAR(50) DEFAULT NULL, action VARCHAR(50) DEFAULT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_2DEDCC6F5E237E06 (name), UNIQUE INDEX UNIQ_2DEDCC6F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE roles (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, code VARCHAR(50) NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_B63E2EC75E237E06 (name), UNIQUE INDEX UNIQ_B63E2EC777153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role_permissions (role_id INT NOT NULL, permission_id INT NOT NULL, INDEX IDX_1FBA94E6D60322AC (role_id), INDEX IDX_1FBA94E6FED90CCA (permission_id), PRIMARY KEY (role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_roles (id INT AUTO_INCREMENT NOT NULL, assigned_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, is_active TINYINT(1) NOT NULL, assigned_by VARCHAR(255) DEFAULT NULL, user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_54FCD59FA76ED395 (user_id), INDEX IDX_54FCD59FD60322AC (role_id), UNIQUE INDEX unique_user_role (user_id, role_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');
        $this->addSql('ALTER TABLE user ADD avatar VARCHAR(255) DEFAULT NULL, ADD bio VARCHAR(500) DEFAULT NULL, ADD timezone VARCHAR(50) DEFAULT NULL, ADD language VARCHAR(10) DEFAULT NULL, ADD email_notifications TINYINT(1) NOT NULL, ADD push_notifications TINYINT(1) NOT NULL, ADD weekly_digest TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6D60322AC');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6FED90CCA');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FD60322AC');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('ALTER TABLE user DROP avatar, DROP bio, DROP timezone, DROP language, DROP email_notifications, DROP push_notifications, DROP weekly_digest');
    }
}
