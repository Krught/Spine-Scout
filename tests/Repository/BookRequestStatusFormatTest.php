<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\BookRepository;
use App\Repository\BookRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Browse "downloaded/requested" overlay must be format-aware in audiobook mode: a request
 * fulfilled as an ebook must NOT show as an owned audiobook (the reported bug), and an
 * audio-fulfilled request must show only in audiobook mode.
 */
final class BookRequestStatusFormatTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private BookRequestRepository $requests;
    private User $user;

    protected function setUp(): void
    {
        self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->requests = $c->get(BookRequestRepository::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();

        $this->user = new User('reader');
        $this->user->setPassword('hash-not-checked');
        $this->em->persist($this->user);
    }

    public function testEbookRequestDoesNotCountAsAudiobook(): void
    {
        // A book (ebook) request — the audiobook flag is the format signal.
        $this->seedFulfilledRequest('Foundation', 'Asimov', '9780000000010', 'epub', audiobook: false);

        $key = BookRepository::normalizeTitleAuthor('Foundation', 'Asimov');
        self::assertArrayHasKey($key, $this->requests->statusMapsForUser($this->user)['titleAuthor'], 'unfiltered overlay sees it');
        self::assertArrayHasKey($key, $this->requests->statusMapsForUser($this->user, false)['titleAuthor'], 'book mode keeps the ebook request');
        self::assertArrayNotHasKey($key, $this->requests->statusMapsForUser($this->user, true)['titleAuthor'], 'audiobook mode must NOT show a book request');
    }

    public function testAudiobookRequestCountsOnlyInAudiobookMode(): void
    {
        $this->seedFulfilledRequest('Dune', 'Herbert', '9780000000011', 'm4b', audiobook: true);

        $key = BookRepository::normalizeTitleAuthor('Dune', 'Herbert');
        self::assertArrayHasKey($key, $this->requests->statusMapsForUser($this->user, true)['titleAuthor'], 'audiobook mode shows the audiobook request');
        self::assertArrayNotHasKey($key, $this->requests->statusMapsForUser($this->user, false)['titleAuthor'], 'book mode excludes the audiobook request');
    }

    public function testApprovedAudiobookRequestWithNoJobStillShowsInAudiobookMode(): void
    {
        // The reported bug: an APPROVED audiobook request (no completed download yet)
        // must still surface its status in the Browse audiobook overlay.
        $book = new Book(Book::SOURCE_HARDCOVER, 'ext-rr', 'Red Rising');
        $book->setAuthor('Pierce Brown');
        $this->em->persist($book);
        $request = new BookRequest($this->user, $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);
        $request->setAudiobook(true);
        $this->em->persist($request);
        $this->em->flush();

        $key = BookRepository::normalizeTitleAuthor('Red Rising', 'Pierce Brown');
        self::assertSame('approved', $this->requests->statusMapsForUser($this->user, true)['titleAuthor'][$key] ?? null);
        self::assertArrayNotHasKey($key, $this->requests->statusMapsForUser($this->user, false)['titleAuthor'], 'book mode excludes it');
    }

    private function seedFulfilledRequest(string $title, string $author, string $isbn, string $format, bool $audiobook): void
    {
        $book = new Book(Book::SOURCE_HARDCOVER, 'ext-' . $isbn, $title);
        $book->setAuthor($author)->setIsbn($isbn)->setDownloaded(false);
        $this->em->persist($book);

        $request = new BookRequest($this->user, $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);
        $request->setAudiobook($audiobook);
        $request->setDeliveryStatus(DownloadJob::STATUS_COMPLETE);
        $this->em->persist($request);

        $job = new DownloadJob('libgen', 'src-' . $isbn, $audiobook ? 'torrent' : 'http', $request);
        $job->setFormat($format)->setStatus(DownloadJob::STATUS_COMPLETE);
        $this->em->persist($job);

        $this->em->flush();
    }
}
