<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\User;
use App\Repository\DownloadJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers recovery of orphaned/stalled download jobs: a worker that dies mid-download
 * leaves a job stuck in an in-flight state, which must (a) be reclaimable so it
 * stops blocking retries and (b) surface a "Recheck now" button for the admin.
 */
final class RequestsRecheckControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private DownloadJobRepository $jobs;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->jobs = $c->get(DownloadJobRepository::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedAdmin();
    }

    public function testReclaimStaleMarksOrphanedDownloadingJobAsErrorAndRetryable(): void
    {
        $job = $this->seedJob(DownloadJob::STATUS_DOWNLOADING, staleMinutes: 120);

        $reclaimed = $this->jobs->reclaimStale();

        self::assertCount(1, $reclaimed);
        $this->em->clear();
        $fresh = $this->em->find(DownloadJob::class, $job->getId());
        self::assertSame(DownloadJob::STATUS_ERROR, $fresh->getStatus());
        self::assertSame('error', $fresh->getBookRequest()?->getDeliveryStatus());
    }

    public function testFreshDownloadingJobIsNotReclaimed(): void
    {
        $this->seedJob(DownloadJob::STATUS_DOWNLOADING, staleMinutes: 1);

        self::assertCount(0, $this->jobs->reclaimStale());
    }

    public function testStalledRequestShowsRecheckButton(): void
    {
        $this->seedJob(DownloadJob::STATUS_DOWNLOADING, staleMinutes: 120);

        $this->client->loginUser($this->loadAdmin());
        $crawler = $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.request-btn-recheck');
    }

    public function testRequestRowOpensBookModal(): void
    {
        $job = $this->seedJob(DownloadJob::STATUS_DOWNLOADING, staleMinutes: 1);
        $bookId = $job->getBookRequest()?->getBook()->getId();

        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        // The cover/title block opens the book modal, carrying the book id.
        self::assertSelectorExists('.request-left[data-action*="book-modal#open"]');
        self::assertSelectorExists('.request-left[data-book-modal-id-param="' . $bookId . '"]');
        // The modal dialog is present on the page.
        self::assertSelectorExists('[data-book-modal-target="modal"]');
    }

    public function testAudiobookRequestRowShowsAudiobookBadgeAndFormatData(): void
    {
        $book = new Book('grimmory', 'ext-' . bin2hex(random_bytes(4)), 'The Audio Work');
        $book->setAuthor('Matt Dinniman');
        $request = new BookRequest($this->loadAdmin(), $book);
        $request->setStatus(BookRequest::STATUS_PENDING);
        $request->setAudiobook(true);
        $this->em->persist($book);
        $this->em->persist($request);
        $this->em->flush();

        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/requests');

        self::assertResponseIsSuccessful();
        // The row is tagged with its format for the client-side filter…
        self::assertSelectorExists('.request-row[data-format="audiobook"]');
        // …and carries a visible Audiobook badge.
        self::assertSelectorTextContains('.request-format-audiobook', 'Audiobook');
    }

    public function testRecheckCancelsTheActiveJob(): void
    {
        $job = $this->seedJob(DownloadJob::STATUS_DOWNLOADING, staleMinutes: 120);
        $this->client->loginUser($this->loadAdmin());

        $crawler = $this->client->request('GET', '/requests');
        $token = $crawler->filter('form[action$="/recheck"] input[name="_csrf_token"]')->attr('value');
        $reqId = $job->getBookRequest()?->getId();

        $this->client->request('POST', '/requests/' . $reqId . '/recheck', ['_csrf_token' => $token]);
        self::assertResponseRedirects('/requests');

        $this->em->clear();
        $fresh = $this->em->find(DownloadJob::class, $job->getId());
        self::assertSame(DownloadJob::STATUS_CANCELLED, $fresh->getStatus());
    }

    private function seedJob(string $status, int $staleMinutes): DownloadJob
    {
        $book = new Book('grimmory', 'ext-' . bin2hex(random_bytes(4)), 'A Parade of Horribles');
        $book->setAuthor('Matt Dinniman');
        $request = new BookRequest($this->loadAdmin(), $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);
        $request->setDeliveryStatus($status);

        $job = new DownloadJob('pending', '', 'http', $request);
        $job->setStatus($status);

        $this->em->persist($book);
        $this->em->persist($request);
        $this->em->persist($job);
        $this->em->flush();

        // Back-date updated_at so the staleness window can be exercised
        // deterministically (PreUpdate would otherwise stamp it "now").
        $stale = (new \DateTimeImmutable())->modify("-{$staleMinutes} minutes")->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'UPDATE download_jobs SET updated_at = :t WHERE id = :id',
            ['t' => $stale, 'id' => $job->getId()],
        );
        $this->em->clear();

        return $this->em->find(DownloadJob::class, $job->getId());
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-recheck');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => 'admin-recheck']);
        self::assertNotNull($user);

        return $user;
    }
}
