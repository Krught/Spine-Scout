<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Book;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verifies the format-aware ownership indexes that drive the Browse "downloaded" badge:
 * in audiobook mode only owned audio copies count; in book mode any owned format counts.
 */
final class BookRepositoryAudiobookTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private BookRepository $books;

    protected function setUp(): void
    {
        self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->books = $c->get(BookRepository::class);

        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();

        $this->seed('Dune', 'Herbert', '9780000000001', 'm4b');   // owned audiobook
        $this->seed('Foundation', 'Asimov', '9780000000002', 'epub'); // owned ebook
        $this->seed('Hyperion', 'Simmons', '9780000000003', null);    // owned, unknown format
        $this->em->flush();
    }

    public function testAudiobookOwnedIsbns(): void
    {
        $audio = $this->books->downloadedIsbns(true);
        self::assertArrayHasKey('9780000000001', $audio);
        self::assertArrayNotHasKey('9780000000002', $audio);
        self::assertArrayNotHasKey('9780000000003', $audio);
    }

    public function testNonAudiobookOwnedIsbnsIncludeNullFormat(): void
    {
        $nonAudio = $this->books->downloadedIsbns(false);
        self::assertArrayNotHasKey('9780000000001', $nonAudio);
        self::assertArrayHasKey('9780000000002', $nonAudio);
        self::assertArrayHasKey('9780000000003', $nonAudio);
    }

    public function testNullFilterReturnsEverythingOwned(): void
    {
        $all = $this->books->downloadedIsbns();
        self::assertCount(3, $all);
    }

    public function testAudiobookTitleAuthorKeys(): void
    {
        $audio = $this->books->downloadedTitleAuthorKeys(true);
        self::assertArrayHasKey(BookRepository::normalizeTitleAuthor('Dune', 'Herbert'), $audio);
        self::assertArrayNotHasKey(BookRepository::normalizeTitleAuthor('Foundation', 'Asimov'), $audio);
    }

    private function seed(string $title, string $author, string $isbn, ?string $format): void
    {
        $book = new Book(Book::SOURCE_GRIMMORY, 'komga-' . $isbn, $title);
        $book->setAuthor($author)->setIsbn($isbn)->setDownloaded(true)->setFormat($format);
        $this->em->persist($book);
    }
}
