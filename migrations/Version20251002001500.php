<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251002001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create votes table for tracking user votes on retrospective items';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE votes (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            retrospective_item_id INT NOT NULL,
            vote_count INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_518B7ACFA76ED395 (user_id),
            INDEX IDX_518B7ACF9F9F1305 (retrospective_item_id),
            UNIQUE INDEX unique_user_item_vote (user_id, retrospective_item_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        $this->addSql('ALTER TABLE votes ADD CONSTRAINT FK_518B7ACFA76ED395 
            FOREIGN KEY (user_id) REFERENCES user (id)');
        
        $this->addSql('ALTER TABLE votes ADD CONSTRAINT FK_518B7ACF9F9F1305 
            FOREIGN KEY (retrospective_item_id) REFERENCES retrospective_item (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE votes DROP FOREIGN KEY FK_518B7ACFA76ED395');
        $this->addSql('ALTER TABLE votes DROP FOREIGN KEY FK_518B7ACF9F9F1305');
        $this->addSql('DROP TABLE votes');
    }
}

