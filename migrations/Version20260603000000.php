<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add book_recommendations: the durable "more like this" cache. Each row links a seed
 * Book to a recommended Book at a co-occurrence rank, wiped-and-rewritten per seed by
 * BookRecommendationService. Keyed by the opened book's internal id so library and
 * Hardcover seeds share one table.
 */
final class Version20260603000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add book_recommendations table for "more like this" cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE book_recommendations (
                id SERIAL PRIMARY KEY,
                seed_book_id INT NOT NULL,
                book_id INT NOT NULL,
                rank INT NOT NULL,
                computed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT fk_book_recommendations_seed FOREIGN KEY (seed_book_id) REFERENCES books(id) ON DELETE CASCADE,
                CONSTRAINT fk_book_recommendations_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX book_recommendations_unique ON book_recommendations (seed_book_id, book_id)');
        $this->addSql('CREATE INDEX book_recommendations_rank_idx ON book_recommendations (seed_book_id, rank)');
        $this->addSql('CREATE INDEX book_recommendations_book_idx ON book_recommendations (book_id)');
        $this->addSql("COMMENT ON COLUMN book_recommendations.computed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN book_recommendations.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE book_recommendations');
    }
}
