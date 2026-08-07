<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Integration;
use App\Repository\IntegrationRepository;
use App\Search\Torrent\ProwlarrConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The settings row is read many times per request (every accessor funnels through findByKind),
 * so IntegrationRepository memoizes it. These tests pin the two halves of that contract: the
 * memo really does avoid the repeat SELECT, and it never outlives the unit of work that filled
 * it -- which is what keeps the long-lived messenger worker honest.
 *
 * Trick used throughout: mutate the row with raw SQL behind the ORM's back. A memoized read is
 * blind to it; a real re-SELECT sees it.
 */
final class IntegrationRepositoryMemoTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;

    protected function setUp(): void
    {
        self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->integrations = $c->get(IntegrationRepository::class);

        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->clear();
        $this->integrations->clearSettingsCache();
    }

    public function testRepeatedReadsAreServedFromTheMemo(): void
    {
        $this->seedProwlarr(7);
        self::assertSame(7, $this->integrations->getProwlarrConfig()->minSeeders);

        // Row is gone as far as the database is concerned; only a memo can still answer 7.
        $this->deleteRowsBehindTheOrm();
        self::assertSame(7, $this->integrations->getProwlarrConfig()->minSeeders);

        // Same goes for the entity handle itself.
        self::assertSame(
            $this->integrations->findByKind(Integration::KIND_PROWLARR),
            $this->integrations->findByKind(Integration::KIND_PROWLARR),
        );
    }

    public function testMemoizedNullDoesNotSurviveAWrite(): void
    {
        // Prime the memo with "no prowlarr row".
        self::assertSame(ProwlarrConfig::DEFAULT_MIN_SEEDERS, $this->integrations->getProwlarrConfig()->minSeeders);

        $this->integrations->saveProwlarrConfig(new ProwlarrConfig(minSeeders: 9), true, $this->em);
        $this->em->flush();

        self::assertSame(9, $this->integrations->getProwlarrConfig()->minSeeders);
    }

    public function testWriteToAnExistingRowInvalidatesTheMemo(): void
    {
        $this->seedProwlarr(2);
        self::assertSame(2, $this->integrations->getProwlarrConfig()->minSeeders);

        $this->integrations->saveProwlarrConfig(new ProwlarrConfig(minSeeders: 4), true, $this->em);
        $this->em->flush();

        self::assertSame(4, $this->integrations->getProwlarrConfig()->minSeeders);
    }

    /**
     * The worker scenario: EntityManager::clear() between messages must invalidate the memo,
     * otherwise a setting toggled in the UI would never reach the next handled message.
     */
    public function testEntityManagerClearForcesARefetch(): void
    {
        $this->seedProwlarr(2);
        self::assertSame(2, $this->integrations->getProwlarrConfig()->minSeeders);

        $this->em->clear();
        $this->rewriteMinSeedersBehindTheOrm(5);

        self::assertSame(5, $this->integrations->getProwlarrConfig()->minSeeders);
    }

    /**
     * Same guarantee for a memoized *absence*: clear() must drop it too, or a worker that booted
     * before Prowlarr was configured would keep reporting it unconfigured forever.
     */
    public function testEntityManagerClearForcesARefetchOfMemoizedNulls(): void
    {
        self::assertSame(ProwlarrConfig::DEFAULT_MIN_SEEDERS, $this->integrations->getProwlarrConfig()->minSeeders);

        $this->em->clear();
        $this->seedProwlarr(6);

        self::assertSame(6, $this->integrations->getProwlarrConfig()->minSeeders);
    }

    private function seedProwlarr(int $minSeeders): void
    {
        $row = new Integration(Integration::KIND_PROWLARR);
        $row->setAuthType(Integration::AUTH_API_KEY);
        $row->setEnabled(true);
        $row->setOptions(['config' => (new ProwlarrConfig(minSeeders: $minSeeders))->toArray()]);
        $this->em->persist($row);
        $this->em->flush();
        $this->em->clear();
        $this->integrations->clearSettingsCache();
    }

    private function rewriteMinSeedersBehindTheOrm(int $minSeeders): void
    {
        $options = ['config' => (new ProwlarrConfig(minSeeders: $minSeeders))->toArray()];
        $this->em->getConnection()->executeStatement(
            'UPDATE integrations SET options = CAST(:options AS jsonb) WHERE kind = :kind',
            ['options' => json_encode($options, JSON_THROW_ON_ERROR), 'kind' => Integration::KIND_PROWLARR],
        );
    }

    private function deleteRowsBehindTheOrm(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM integrations');
    }
}
