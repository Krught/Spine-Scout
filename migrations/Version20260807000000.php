<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create blocked_releases table (per-book failure blocklist for direct-download/torrent releases)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE blocked_releases (
                id SERIAL NOT NULL,
                book_id INT NOT NULL,
                source VARCHAR(40) NOT NULL,
                source_id VARCHAR(255) NOT NULL,
                protocol VARCHAR(16) NOT NULL,
                url TEXT DEFAULT NULL,
                client_ref VARCHAR(64) DEFAULT NULL,
                reason TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE blocked_releases
                ADD CONSTRAINT blocked_releases_book_id_source_source_id_uniq
                UNIQUE (book_id, source, source_id)
        SQL);
        $this->addSql('CREATE INDEX blocked_releases_book_id_expires_at_idx ON blocked_releases (book_id, expires_at)');
        $this->addSql("COMMENT ON COLUMN blocked_releases.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN blocked_releases.expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            ALTER TABLE blocked_releases
                ADD CONSTRAINT fk_blocked_releases_book
                FOREIGN KEY (book_id) REFERENCES books (id)
                ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE blocked_releases');
    }
}
