<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add download_jobs.client_ref: the download client's native handle for an
 * async job (the qBittorrent torrent hash). The HTTP path leaves it null; the
 * torrent poller uses it to query qBittorrent for status and to issue the move.
 */
final class Version20260630000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add download_jobs.client_ref for the qBittorrent torrent hash';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_jobs ADD client_ref VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_jobs DROP client_ref');
    }
}
