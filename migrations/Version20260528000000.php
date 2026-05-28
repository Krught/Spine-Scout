<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalize the Hardcover/OpenLibrary shelf cache into the books table:
 *   - drops integrations.cache_data (the per-integration JSONB blob)
 *   - adds book_section_entries (link table: source/section/book/rank)
 *   - adds popular_rank / popular_fetched_at to authors
 *
 * On deploy the homepage will appear empty until the next refresh repopulates
 * the new tables. Operators should hit "Refresh Hardcover" in Settings →
 * Metadata immediately after running this migration to avoid a visible gap.
 */
final class Version20260528000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize integration cache into books/sections; add author popularity ranks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books ADD cover_url VARCHAR(1000) DEFAULT NULL');
        $this->addSql("ALTER TABLE books ADD isbns JSONB NOT NULL DEFAULT '[]'::jsonb");

        $this->addSql('ALTER TABLE authors ADD popular_rank INT DEFAULT NULL');
        $this->addSql('ALTER TABLE authors ADD popular_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN authors.popular_fetched_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX authors_popular_rank_idx ON authors (source, popular_rank) WHERE popular_rank IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE book_section_entries (
                id SERIAL PRIMARY KEY,
                source VARCHAR(32) NOT NULL,
                section VARCHAR(32) NOT NULL,
                book_id INT NOT NULL,
                rank INT NOT NULL,
                fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_book_section_entries_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX book_section_entries_unique ON book_section_entries (source, section, book_id)');
        $this->addSql('CREATE INDEX book_section_entries_rank_idx ON book_section_entries (source, section, rank)');
        $this->addSql('CREATE INDEX book_section_entries_book_idx ON book_section_entries (book_id)');
        $this->addSql("COMMENT ON COLUMN book_section_entries.fetched_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN book_section_entries.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE integrations DROP COLUMN cache_data');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE integrations ADD cache_data JSONB NOT NULL DEFAULT '{}'::jsonb");

        $this->addSql('DROP TABLE book_section_entries');

        $this->addSql('DROP INDEX authors_popular_rank_idx');
        $this->addSql('ALTER TABLE authors DROP popular_fetched_at');
        $this->addSql('ALTER TABLE authors DROP popular_rank');

        $this->addSql('ALTER TABLE books DROP isbns');
        $this->addSql('ALTER TABLE books DROP cover_url');
    }
}
