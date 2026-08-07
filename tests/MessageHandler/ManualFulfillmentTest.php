<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Message\DispatchReleaseSearch;
use App\Message\DispatchTorrentSearch;
use App\Message\RetryApprovedSearches;
use App\MessageHandler\DispatchReleaseSearchHandler;
use App\MessageHandler\DispatchTorrentSearchHandler;
use App\MessageHandler\RetryApprovedSearchesHandler;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Manual fulfillment mode: with automatic fulfillment switched off, no AUTOMATIC
 * initiator may create a download job — approval dispatches (ebook + audiobook)
 * become no-ops and the 3-hourly retry sweep skips entirely. With the toggle on,
 * or simply never set (default true), the pipeline behaves as before.
 */
final class ManualFulfillmentTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BookRequest $request;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->getConnection()->executeStatement('DELETE FROM messenger_messages');

        $user = new User('reader');
        $user->setPassword('hash-not-checked');
        $this->em->persist($user);

        $book = new Book(Book::SOURCE_HARDCOVER, 'dcc', 'Dungeon Crawler Carl');
        $book->setAuthor('Matt Dinniman')->setAudiobookAvailable(true);
        $this->em->persist($book);

        $this->request = new BookRequest($user, $book);
        $this->request->setStatus(BookRequest::STATUS_APPROVED);
        $this->em->persist($this->request);
        $this->em->flush();
    }

    public function testReleaseSearchCreatesNoJobWhenAutomaticFulfillmentIsOff(): void
    {
        $this->setAutomaticFulfillment(false);

        ($this->handler(DispatchReleaseSearchHandler::class))(new DispatchReleaseSearch($this->requestId()));

        self::assertSame(0, $this->jobCount(), 'no download job may be created in manual mode');
        self::assertSame('', $this->messengerQueueDump(), 'nothing handed to the download pipeline');
        self::assertSame(BookRequest::STATUS_APPROVED, $this->reloadedStatus(), 'the request stays approved');
    }

    public function testTorrentSearchCreatesNoJobWhenAutomaticFulfillmentIsOff(): void
    {
        $this->request->setAudiobook(true);
        $this->em->flush();
        $this->setAutomaticFulfillment(false);

        ($this->handler(DispatchTorrentSearchHandler::class))(new DispatchTorrentSearch($this->requestId()));

        self::assertSame(0, $this->jobCount(), 'no torrent job may be created in manual mode');
        self::assertSame('', $this->messengerQueueDump());
        self::assertSame(BookRequest::STATUS_APPROVED, $this->reloadedStatus());
    }

    public function testRetrySweepDispatchesNothingWhenAutomaticFulfillmentIsOff(): void
    {
        $this->setAutomaticFulfillment(false);

        ($this->handler(RetryApprovedSearchesHandler::class))(new RetryApprovedSearches());

        self::assertSame('', $this->messengerQueueDump(), 'the sweep is skipped entirely');
        self::assertSame(0, $this->jobCount());
    }

    public function testReleaseSearchStillQueuesAJobWhenEnabled(): void
    {
        $this->setAutomaticFulfillment(true);

        ($this->handler(DispatchReleaseSearchHandler::class))(new DispatchReleaseSearch($this->requestId()));

        self::assertSame(1, $this->jobCount());
        self::assertStringContainsString('ProcessDownloadJob', $this->messengerQueueDump());
    }

    public function testTorrentSearchStillQueuesAJobWhenEnabled(): void
    {
        $this->request->setAudiobook(true);
        $this->em->flush();
        $this->setAutomaticFulfillment(true);

        ($this->handler(DispatchTorrentSearchHandler::class))(new DispatchTorrentSearch($this->requestId()));

        self::assertSame(1, $this->jobCount());
        self::assertStringContainsString('ProcessTorrentJob', $this->messengerQueueDump());
    }

    public function testDefaultUnsetSettingKeepsAutomaticBehavior(): void
    {
        // No KIND_APP row at all — the toggle defaults to true.
        self::assertTrue(self::getContainer()->get(IntegrationRepository::class)->isAutomaticFulfillmentEnabled());

        ($this->handler(DispatchReleaseSearchHandler::class))(new DispatchReleaseSearch($this->requestId()));

        self::assertSame(1, $this->jobCount(), 'an unset setting must behave as enabled');
    }

    public function testRetrySweepDispatchesWhenEnabled(): void
    {
        $this->setAutomaticFulfillment(true);

        ($this->handler(RetryApprovedSearchesHandler::class))(new RetryApprovedSearches());

        self::assertStringContainsString('DispatchReleaseSearch', $this->messengerQueueDump());
    }

    private function setAutomaticFulfillment(bool $enabled): void
    {
        self::getContainer()->get(IntegrationRepository::class)->setAutomaticFulfillmentEnabled($enabled);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function handler(string $class): object
    {
        return self::getContainer()->get($class);
    }

    private function requestId(): int
    {
        return (int) $this->request->getId();
    }

    private function jobCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(j) FROM ' . DownloadJob::class . ' j')->getSingleScalarResult();
    }

    private function reloadedStatus(): string
    {
        $this->em->refresh($this->request);

        return $this->request->getStatus();
    }

    private function messengerQueueDump(): string
    {
        return implode("\n", $this->em->getConnection()->fetchFirstColumn('SELECT body FROM messenger_messages'));
    }
}
