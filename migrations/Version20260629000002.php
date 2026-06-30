<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audiobook-edition fields surfaced in the detail modal when viewing the audiobook:
 *   - books.narrator: narrator(s) from the Hardcover audiobook edition's contributors.
 *   - books.audio_seconds: the audiobook's runtime in seconds.
 */
final class Version20260629000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add books.narrator and books.audio_seconds for audiobook display';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books ADD narrator VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE books ADD audio_seconds INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE books DROP audio_seconds');
        $this->addSql('ALTER TABLE books DROP narrator');
    }
}
