<?php

namespace App;

use App\Message\PurgeExpiredSessions;
use App\Message\RefreshHardcoverTrending;
use App\Message\RefreshOpenLibraryTrending;
use App\Message\SyncGrimmoryLibrary;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 minute', new SyncGrimmoryLibrary()))
            ->add(RecurringMessage::every('1 minute', new RefreshHardcoverTrending()))
            ->add(RecurringMessage::every('1 minute', new RefreshOpenLibraryTrending()))
            ->add(RecurringMessage::every('1 hour', new PurgeExpiredSessions()))
        ;
    }
}
