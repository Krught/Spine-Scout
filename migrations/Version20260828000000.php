<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen books.external_id and authors.slug to VARCHAR(500) — Hardcover slugs carry the full subtitle and overflow 191 characters, aborting the whole trending write-through';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books ALTER external_id TYPE VARCHAR(500)');
        $this->addSql('ALTER TABLE authors ALTER slug TYPE VARCHAR(500)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM books WHERE LENGTH(external_id) > 191');
        $this->addSql('DELETE FROM authors WHERE LENGTH(slug) > 191');
        $this->addSql('ALTER TABLE books ALTER external_id TYPE VARCHAR(191)');
        $this->addSql('ALTER TABLE authors ALTER slug TYPE VARCHAR(191)');
    }
}
