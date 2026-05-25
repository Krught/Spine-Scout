<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: integrations, books, messenger_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE integrations (
                id SERIAL NOT NULL,
                kind VARCHAR(50) NOT NULL,
                name VARCHAR(100) DEFAULT NULL,
                base_url VARCHAR(500) DEFAULT NULL,
                auth_type VARCHAR(20) NOT NULL,
                credentials JSONB NOT NULL,
                enabled BOOLEAN NOT NULL,
                last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                sync_interval_minutes SMALLINT NOT NULL DEFAULT 15,
                discovered_libraries JSONB NOT NULL DEFAULT '[]',
                selected_libraries JSONB NOT NULL DEFAULT '[]',
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX integrations_kind_uniq ON integrations (kind)');

        $this->addSql(<<<'SQL'
            CREATE TABLE books (
                id SERIAL NOT NULL,
                source VARCHAR(32) NOT NULL,
                external_id VARCHAR(191) NOT NULL,
                title VARCHAR(500) NOT NULL,
                author VARCHAR(500) DEFAULT NULL,
                series VARCHAR(255) DEFAULT NULL,
                series_index VARCHAR(32) DEFAULT NULL,
                external_url VARCHAR(1000) DEFAULT NULL,
                first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                removed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                added_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_modified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                komga_library_id VARCHAR(64) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX books_source_external_uniq ON books (source, external_id)');
        $this->addSql('CREATE INDEX books_source_idx ON books (source)');
        $this->addSql('CREATE INDEX books_removed_at_idx ON books (removed_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGSERIAL NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_notify('messenger_messages', NEW.queue_name::text);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages
            FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql('DROP FUNCTION IF EXISTS notify_messenger_messages()');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE books');
        $this->addSql('DROP TABLE integrations');
    }
}
