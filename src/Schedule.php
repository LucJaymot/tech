<?php

namespace App;

use App\Obligations\Message\RappelsQuotidiens;
use App\Obligations\Message\RecapitulatifHebdomadaire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Run by a worker: php bin/console messenger:consume scheduler_default async.
 */
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
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run
            ->add(
                RecurringMessage::every('1 day', new RappelsQuotidiens(), from: new \DateTimeImmutable('today 07:00')),
                RecurringMessage::every('1 week', new RecapitulatifHebdomadaire(), from: new \DateTimeImmutable('monday this week 08:00')),
            );
    }
}
