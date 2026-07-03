<?php

declare(strict_types=1);

namespace App\Command;

use App\Download\Torrent\TorrentJobReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual CLI counterpart to the Settings → Audiobooks "Run reconcile now" button
 * and the scheduled auto-run (see ReconcileTorrentJobsHandler / Schedule). Shares
 * its implementation with both via TorrentJobReconciler.
 */
#[AsCommand(name: 'spinescout:torrents:reconcile', description: 'Re-link errored torrent jobs back to a torrent still present in the download client.')]
final class ReconcileTorrentJobsCommand extends Command
{
    public function __construct(
        private readonly TorrentJobReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Actually reset matching jobs (default is a dry run that only reports what would change).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $result = $this->reconciler->reconcile($apply);

        if (!$result->hasClient) {
            $output->writeln('<error>No configured torrent download client — nothing to reconcile against.</error>');

            return Command::FAILURE;
        }
        if ($result->checked === 0) {
            $output->writeln('No errored torrent jobs with a client hash to check.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Checking %d errored torrent job(s) against the download client…', $result->checked));
        foreach ($result->lines as $line) {
            $output->writeln('  ' . $line);
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '%s %d job(s), skipped %d, gone %d.%s',
            $apply ? 'Reconciled' : 'Would reconcile',
            $result->reconciled,
            $result->skipped,
            $result->gone,
            $apply ? ' The poller will pick them up on its next tick.' : '',
        ));

        return Command::SUCCESS;
    }
}
