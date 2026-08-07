<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hot-path indexes: partial books(downloaded) for live owned lookups, download_jobs(protocol,status) and (book_request_id,created_at)';
    }

    public function up(Schema $schema): void
    {
        // Serves BookRepository::downloadedIsbns / downloadedTitleAuthorKeys / findDownloadedPage,
        // which all filter on removed_at IS NULL AND downloaded = true, so the index only carries
        // the live, owned rows. Partial indexes cannot be expressed as ORM attributes; this is
        // migration-only on purpose. Safe because every environment (dev, prod, test) builds its
        // schema by running migrations, not from the mapping metadata.
        $this->addSql('CREATE INDEX IF NOT EXISTS books_downloaded_live_idx ON books (downloaded) WHERE removed_at IS NULL AND downloaded');

        // DownloadJobRepository::activeTorrentJobs / reclaimStale filter protocol + status together.
        $this->addSql('CREATE INDEX IF NOT EXISTS download_jobs_protocol_status_idx ON download_jobs (protocol, status)');

        // DownloadJobRepository::latestByRequestIds filters book_request_id IN (...) ORDER BY created_at.
        $this->addSql('CREATE INDEX IF NOT EXISTS download_jobs_request_created_idx ON download_jobs (book_request_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS download_jobs_request_created_idx');
        $this->addSql('DROP INDEX IF EXISTS download_jobs_protocol_status_idx');
        $this->addSql('DROP INDEX IF EXISTS books_downloaded_live_idx');
    }
}
