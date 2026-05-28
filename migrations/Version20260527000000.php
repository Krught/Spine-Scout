<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create book_requests table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE book_requests (
                id SERIAL NOT NULL,
                book_id INT NOT NULL,
                requested_by_id INT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX book_requests_status_idx ON book_requests (status)');
        $this->addSql('CREATE INDEX book_requests_requested_by_idx ON book_requests (requested_by_id)');
        $this->addSql('CREATE INDEX book_requests_book_idx ON book_requests (book_id)');
        $this->addSql("COMMENT ON COLUMN book_requests.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN book_requests.updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql(<<<'SQL'
            ALTER TABLE book_requests
                ADD CONSTRAINT fk_book_requests_book
                FOREIGN KEY (book_id) REFERENCES books (id)
                ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE book_requests
                ADD CONSTRAINT fk_book_requests_user
                FOREIGN KEY (requested_by_id) REFERENCES users (id)
                ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE book_requests');
    }
}
