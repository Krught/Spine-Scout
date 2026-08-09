<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.home_sections (per-user Discover section order and hidden set)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD home_sections JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP home_sections');
    }
}
