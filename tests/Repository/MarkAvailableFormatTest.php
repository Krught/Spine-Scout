<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The post-sync AVAILABLE flip must be format-aware: an owned ebook must not mark a
 * pending AUDIOBOOK request "In Library" (the reported bug), and vice-versa. The
 * request carries the format; the owned copy's Book::$format is the signal.
 */
final class MarkAvailableFormatTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private BookRequestRepository $requests;
    private BookRepository $books;
    private User $user;

    protected function setUp(): void
    {
        self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->requests = $c->get(BookRequestRepository::class);
        $this->books = $c->get(BookRepository::class);

        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();

        $this->user = new User('reader');
        $this->user->setPassword('hash-not-checked');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testOwnedEbookDoesNotMarkAudiobookRequestAvailable(): void
    {
        // Own the ebook of Red Rising (epub), request the AUDIOBOOK.
        $this->seedOwned('Red Rising', 'Pierce Brown', 'epub');
        $request = $this->seedRequest('Red Rising', 'Pierce Brown', audiobook: true);

        $flipped = $this->flip();

        self::assertSame(0, $flipped);
        $this->em->refresh($request);
        self::assertSame(BookRequest::STATUS_APPROVED, $request->getStatus());
    }

    public function testNullFormatOwnedCopyDoesNotMarkAudiobookAvailable(): void
    {
        // A library copy synced before format was captured (format NULL) is treated as
        // non-audio, so it must not satisfy an audiobook request.
        $this->seedOwned('Red Rising', 'Pierce Brown', null);
        $request = $this->seedRequest('Red Rising', 'Pierce Brown', audiobook: true);

        self::assertSame(0, $this->flip());
        $this->em->refresh($request);
        self::assertSame(BookRequest::STATUS_APPROVED, $request->getStatus());
    }

    public function testOwnedAudiobookMarksAudiobookRequestAvailable(): void
    {
        $this->seedOwned('Dune', 'Herbert', 'm4b');
        $request = $this->seedRequest('Dune', 'Herbert', audiobook: true);

        self::assertSame(1, $this->flip());
        $this->em->refresh($request);
        self::assertSame(BookRequest::STATUS_AVAILABLE, $request->getStatus());
    }

    public function testOwnedEbookStillMarksBookRequestAvailable(): void
    {
        $this->seedOwned('Foundation', 'Asimov', 'epub');
        $request = $this->seedRequest('Foundation', 'Asimov', audiobook: false);

        self::assertSame(1, $this->flip());
        $this->em->refresh($request);
        self::assertSame(BookRequest::STATUS_AVAILABLE, $request->getStatus());
    }

    private function flip(): int
    {
        return $this->requests->markAvailableForDownloaded(
            $this->books->downloadedIsbns(true),
            $this->books->downloadedTitleAuthorKeys(true),
            $this->books->downloadedIsbns(false),
            $this->books->downloadedTitleAuthorKeys(false),
        );
    }

    private function seedOwned(string $title, string $author, ?string $format): void
    {
        $book = new Book(Book::SOURCE_GRIMMORY, 'komga-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor($author)->setDownloaded(true);
        if ($format !== null) {
            $book->setFormat($format);
        }
        $this->em->persist($book);
        $this->em->flush();
    }

    private function seedRequest(string $title, string $author, bool $audiobook): BookRequest
    {
        $book = new Book(Book::SOURCE_HARDCOVER, 'hc-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor($author);
        $this->em->persist($book);

        $request = new BookRequest($this->user, $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);
        $request->setAudiobook($audiobook);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }
}
