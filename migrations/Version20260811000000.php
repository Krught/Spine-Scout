<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add freeleech_items.popularity (Hardcover users_count captured at resolve time, drives the trending shelf order)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE freeleech_items ADD popularity INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE freeleech_items DROP popularity');
    }
}
