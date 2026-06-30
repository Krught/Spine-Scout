<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user master flag and auto-approve flag; flag the first user as master';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD is_master BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE users ADD auto_approve_requests BOOLEAN NOT NULL DEFAULT FALSE');

        // Existing installs: protect the original admin (lowest id) as master.
        $this->addSql('UPDATE users SET is_master = TRUE WHERE id = (SELECT MIN(id) FROM users)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP auto_approve_requests');
        $this->addSql('ALTER TABLE users DROP is_master');
    }
}
