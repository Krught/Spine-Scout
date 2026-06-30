<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audiobook edition support for the detail modal:
 *   - books.audiobook_available: the upstream provider lists an audiobook edition for the work
 *     (drives the Book/Audiobook toggle).
 *   - book_requests.audiobook: the request targets the audiobook rather than the ebook, so the
 *     modal can track per-format request/ownership status.
 */
final class Version20260629000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add books.audiobook_available and book_requests.audiobook';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books ADD audiobook_available BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE book_requests ADD audiobook BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_requests DROP audiobook');
        $this->addSql('ALTER TABLE books DROP audiobook_available');
    }
}
