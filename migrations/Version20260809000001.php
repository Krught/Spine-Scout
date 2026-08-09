<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add freeleech_items.free and freeleech_items.personal_freeleech (MAM global freeleech flags)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE freeleech_items ADD free BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE freeleech_items ADD personal_freeleech BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE INDEX freeleech_items_free_idx ON freeleech_items (free)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX freeleech_items_free_idx');
        $this->addSql('ALTER TABLE freeleech_items DROP free');
        $this->addSql('ALTER TABLE freeleech_items DROP personal_freeleech');
    }
}
