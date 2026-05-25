<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add integration cache_data/options, extend books with metadata columns, create users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE integrations ADD cache_data JSONB NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE integrations ADD options JSONB NOT NULL DEFAULT '{}'::jsonb");

        $this->addSql('ALTER TABLE books ADD downloaded BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE books ADD isbn VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX books_isbn_idx ON books (isbn)');
        $this->addSql('ALTER TABLE books ADD publisher VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE books ADD published_date VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE books ADD language VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE books ADD description TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE books ADD genres JSONB NOT NULL DEFAULT '[]'::jsonb");
        $this->addSql('ALTER TABLE books ADD series_total INT DEFAULT NULL');
        $this->addSql('ALTER TABLE books ADD metadata_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN books.metadata_fetched_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(60) NOT NULL,
                password VARCHAR(255) NOT NULL,
                roles JSONB NOT NULL DEFAULT '[]'::jsonb,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX users_username_uniq ON users (username)');
        $this->addSql("COMMENT ON COLUMN users.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN users.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE users');

        $this->addSql('ALTER TABLE books DROP metadata_fetched_at');
        $this->addSql('ALTER TABLE books DROP series_total');
        $this->addSql('ALTER TABLE books DROP genres');
        $this->addSql('ALTER TABLE books DROP description');
        $this->addSql('ALTER TABLE books DROP language');
        $this->addSql('ALTER TABLE books DROP published_date');
        $this->addSql('ALTER TABLE books DROP publisher');
        $this->addSql('DROP INDEX books_isbn_idx');
        $this->addSql('ALTER TABLE books DROP isbn');
        $this->addSql('ALTER TABLE books DROP downloaded');

        $this->addSql('ALTER TABLE integrations DROP options');
        $this->addSql('ALTER TABLE integrations DROP cache_data');
    }
}
