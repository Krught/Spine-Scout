<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create freeleech_items table (MyAnonamouse freeleech availability rows, resolved against books)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE freeleech_items (
                id SERIAL NOT NULL,
                book_id INT DEFAULT NULL,
                mam_torrent_id INT NOT NULL,
                title VARCHAR(500) NOT NULL,
                authors JSONB DEFAULT '[]' NOT NULL,
                authors_text TEXT DEFAULT '' NOT NULL,
                narrators JSONB DEFAULT '[]' NOT NULL,
                narrators_text TEXT DEFAULT '' NOT NULL,
                audiobook BOOLEAN NOT NULL,
                cat_name VARCHAR(100) DEFAULT NULL,
                lang_code VARCHAR(16) DEFAULT NULL,
                filetypes VARCHAR(255) DEFAULT NULL,
                size_bytes BIGINT DEFAULT NULL,
                seeders INT DEFAULT 0 NOT NULL,
                leechers INT DEFAULT 0 NOT NULL,
                times_completed INT DEFAULT 0 NOT NULL,
                vip BOOLEAN DEFAULT false NOT NULL,
                fl_vip BOOLEAN DEFAULT false NOT NULL,
                dl_hash VARCHAR(64) DEFAULT NULL,
                thumbnail_url TEXT DEFAULT NULL,
                added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                resolution VARCHAR(16) DEFAULT 'pending' NOT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE freeleech_items
                ADD CONSTRAINT freeleech_items_mam_torrent_id_uniq
                UNIQUE (mam_torrent_id)
        SQL);
        $this->addSql('CREATE INDEX freeleech_items_resolution_idx ON freeleech_items (resolution)');
        $this->addSql('CREATE INDEX freeleech_items_audiobook_idx ON freeleech_items (audiobook)');
        $this->addSql('CREATE INDEX freeleech_items_last_seen_at_idx ON freeleech_items (last_seen_at)');
        $this->addSql('CREATE INDEX IDX_581699C316A2B381 ON freeleech_items (book_id)');
        $this->addSql("COMMENT ON COLUMN freeleech_items.added_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN freeleech_items.first_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN freeleech_items.last_seen_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            ALTER TABLE freeleech_items
                ADD CONSTRAINT fk_freeleech_items_book
                FOREIGN KEY (book_id) REFERENCES books (id)
                ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE freeleech_items');
    }
}
