<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251004151300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create timer_likes table for storing timer like states';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE timer_likes (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            retrospective_id INT NOT NULL,
            is_liked TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_TIMER_LIKES_USER (user_id),
            INDEX IDX_TIMER_LIKES_RETROSPECTIVE (retrospective_id),
            UNIQUE INDEX unique_user_retrospective (user_id, retrospective_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        $this->addSql('ALTER TABLE timer_likes ADD CONSTRAINT FK_TIMER_LIKES_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE timer_likes ADD CONSTRAINT FK_TIMER_LIKES_RETROSPECTIVE FOREIGN KEY (retrospective_id) REFERENCES retrospective (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE timer_likes');
    }
}
