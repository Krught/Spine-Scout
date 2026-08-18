<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen freeleech_items.dl_hash to TEXT — MAM download tokens exceed 64 characters and blew up the whole freeleech refresh';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE freeleech_items ALTER dl_hash TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE freeleech_items SET dl_hash = NULL WHERE LENGTH(dl_hash) > 64');
        $this->addSql('ALTER TABLE freeleech_items ALTER dl_hash TYPE VARCHAR(64)');
    }
}
