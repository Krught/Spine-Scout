<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create authors and sessions tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE authors (
                id SERIAL NOT NULL,
                source VARCHAR(32) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                name VARCHAR(255) NOT NULL,
                bio TEXT DEFAULT NULL,
                image_url VARCHAR(1000) DEFAULT NULL,
                external_url VARCHAR(1000) DEFAULT NULL,
                location VARCHAR(255) DEFAULT NULL,
                born_year INT DEFAULT NULL,
                death_year INT DEFAULT NULL,
                books_count INT DEFAULT NULL,
                users_count INT DEFAULT NULL,
                top_books JSONB NOT NULL DEFAULT '[]'::jsonb,
                metadata_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX authors_source_slug_uniq ON authors (source, slug)');
        $this->addSql('CREATE INDEX authors_source_idx ON authors (source)');
        $this->addSql("COMMENT ON COLUMN authors.metadata_fetched_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN authors.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN authors.updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX sessions_lifetime_time_idx ON sessions (sess_lifetime, sess_time)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE authors');
    }
}
