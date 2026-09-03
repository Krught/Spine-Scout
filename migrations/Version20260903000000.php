<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add download_jobs.manual_name / manual_audiobook — Manual Search delivers files with no BookRequest behind them, so the operator-approved filename and the ebook/audiobook choice have to live on the job itself';
    }

    public function up(Schema $schema): void
    {
        // Both nullable with no default: a null means "this job came from a book
        // request", which is every existing row and every automatic job.
        $this->addSql('ALTER TABLE download_jobs ADD manual_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE download_jobs ADD manual_audiobook BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE download_jobs DROP manual_name');
        $this->addSql('ALTER TABLE download_jobs DROP manual_audiobook');
    }
}
