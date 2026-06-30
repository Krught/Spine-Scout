<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add books.format — the owned file's format token (lowercased, e.g. "epub", "m4b", "mp3"),
 * captured from Komga during library sync. Lets the Browse Books/Audiobooks toggle and the
 * "downloaded" badge distinguish an owned audiobook from an ebook (see App\Support\AudioFormat).
 * Null for metadata-only rows (Hardcover/OpenLibrary trending).
 */
final class Version20260629000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add books.format for owned audiobook/ebook distinction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books ADD format VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books DROP format');
    }
}
