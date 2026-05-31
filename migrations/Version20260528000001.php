<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Download fulfillment foundation:
 *   - download_jobs table (per-attempt download tracker — see DownloadJob entity),
 *     including the ordered candidate_links failover list
 *   - book_requests.delivery_status (mirrors the latest job's status)
 *   - fulfillment_events activity log (human-readable events from the
 *     request→search→download loop, surfaced live on the Development page)
 *
 * The direct_download / best_match integration kinds reuse the existing
 * integrations.options JSONB column, so no schema change for those.
 */
final class Version20260528000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add download_jobs table, book_requests.delivery_status, and fulfillment_events log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE download_jobs (
                id SERIAL PRIMARY KEY,
                book_request_id INT DEFAULT NULL,
                source VARCHAR(40) NOT NULL,
                source_id VARCHAR(255) NOT NULL,
                protocol VARCHAR(16) NOT NULL,
                download_url TEXT DEFAULT NULL,
                format VARCHAR(16) DEFAULT NULL,
                size_bytes BIGINT DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                progress SMALLINT NOT NULL DEFAULT 0,
                status_message TEXT DEFAULT NULL,
                file_path TEXT DEFAULT NULL,
                candidate_links JSONB NOT NULL DEFAULT '[]',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_download_jobs_request
                    FOREIGN KEY (book_request_id) REFERENCES book_requests(id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX download_jobs_status_idx ON download_jobs (status)');
        $this->addSql('CREATE INDEX download_jobs_request_idx ON download_jobs (book_request_id)');
        $this->addSql("COMMENT ON COLUMN download_jobs.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN download_jobs.updated_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE book_requests ADD delivery_status VARCHAR(16) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE fulfillment_events (
                id SERIAL PRIMARY KEY,
                level VARCHAR(8) NOT NULL,
                message TEXT NOT NULL,
                subject VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX fulfillment_events_created_idx ON fulfillment_events (created_at)');
        $this->addSql("COMMENT ON COLUMN fulfillment_events.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fulfillment_events');

        $this->addSql('ALTER TABLE book_requests DROP delivery_status');

        $this->addSql('DROP INDEX download_jobs_request_idx');
        $this->addSql('DROP INDEX download_jobs_status_idx');
        $this->addSql('DROP TABLE download_jobs');
    }
}
