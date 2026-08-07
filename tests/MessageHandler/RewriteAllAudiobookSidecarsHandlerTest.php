<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Download\Client\TorrentClientSettings;
use App\Download\Torrent\TorrentClientConfig;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Message\RewriteAllAudiobookSidecars;
use App\Message\RewriteAudiobookSidecar;
use App\Message\TriggerGrimmorySidecarImport;
use App\MessageHandler\RewriteAllAudiobookSidecarsHandler;
use App\Repository\DownloadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The library-wide sidecar rewrite: one {@see RewriteAudiobookSidecar} per completed
 * audiobook job, then a single delayed {@see TriggerGrimmorySidecarImport} — and
 * nothing at all when the operator disabled Grimmory sidecars. Uses the real
 * {@see DownloadJobRepository} (final, so not mockable) against seeded rows, with a
 * capturing bus stub so the dispatched messages and stamps can be asserted exactly.
 */
final class RewriteAllAudiobookSidecarsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<array{0: object, 1: list<object>}> [message, stamps] per dispatch */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
    }

    public function testFansOutOneRewritePerJobThenTriggersADelayedImport(): void
    {
        $first  = $this->completedAudiobookJob('dcc', 'Dungeon Crawler Carl');
        $second = $this->completedAudiobookJob('twok', 'The Way of Kings');

        $this->handler(sidecarsEnabled: true)(new RewriteAllAudiobookSidecars());

        self::assertCount(3, $this->dispatched);

        [$rewriteA, $rewriteB, $trigger] = $this->dispatched;
        self::assertInstanceOf(RewriteAudiobookSidecar::class, $rewriteA[0]);
        self::assertSame($first->getId(), $rewriteA[0]->downloadJobId);
        self::assertInstanceOf(RewriteAudiobookSidecar::class, $rewriteB[0]);
        self::assertSame($second->getId(), $rewriteB[0]->downloadJobId);

        // The import trigger is delayed 5 minutes so the rewrites land first.
        self::assertInstanceOf(TriggerGrimmorySidecarImport::class, $trigger[0]);
        self::assertCount(1, $trigger[1]);
        self::assertInstanceOf(DelayStamp::class, $trigger[1][0]);
        self::assertSame(300_000, $trigger[1][0]->getDelay());
    }

    public function testSkipsFanOutEntirelyWhenGrimmorySidecarsAreDisabled(): void
    {
        $this->completedAudiobookJob('dcc', 'Dungeon Crawler Carl');

        $this->handler(sidecarsEnabled: false)(new RewriteAllAudiobookSidecars());

        self::assertSame([], $this->dispatched, 'no rewrites and no import trigger may be queued');
    }

    private function completedAudiobookJob(string $externalId, string $title): DownloadJob
    {
        $user = new User('reader-' . $externalId);
        $user->setPassword('hash-not-checked');
        $this->em->persist($user);

        $book = new Book(Book::SOURCE_HARDCOVER, $externalId, $title);
        $this->em->persist($book);

        $request = (new BookRequest($user, $book))->setAudiobook(true);
        $this->em->persist($request);

        $job = new DownloadJob('torrent', $externalId, 'torrent', $request);
        $job->setStatus(DownloadJob::STATUS_COMPLETE)->setFilePath('/audiobooks/' . $title);
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function handler(bool $sidecarsEnabled): RewriteAllAudiobookSidecarsHandler
    {
        $integrations = $this->createStub(TorrentClientSettings::class);
        $integrations->method('getTorrentClientConfig')
            ->willReturn(new TorrentClientConfig(writeGrimmorySidecars: $sidecarsEnabled));

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $this->dispatched[] = [$message, $stamps];

            return new Envelope($message, $stamps);
        });

        return new RewriteAllAudiobookSidecarsHandler(
            self::getContainer()->get(DownloadJobRepository::class),
            $integrations,
            $bus,
            new NullLogger(),
        );
    }
}
