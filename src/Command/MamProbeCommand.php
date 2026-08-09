<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FreeleechItem;
use App\Integration\MyAnonamouse\MamFreeleechRefresher;
use App\Repository\FreeleechItemRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic CLI counterpart to the scheduled freeleech ingest: runs the very same
 * MamFreeleechRefresher the message handler runs (the TorrentProbeCommand convention)
 * and prints what the sweep did, so a stale shelf can be pinned to the throttle, the
 * cookie, the seeder filter, or the Hardcover reverse lookup.
 */
#[AsCommand(name: 'spinescout:mam:probe', description: 'Run one MyAnonamouse freeleech refresh and report what it did.')]
final class MamProbeCommand extends Command
{
    public function __construct(
        private readonly MamFreeleechRefresher $refresher,
        private readonly FreeleechItemRepository $items,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Refresh even if the configured interval has not elapsed.');
        $this->addOption('resolve-only', null, InputOption::VALUE_NONE, 'Skip the MAM fetch and only drain the pending backlog against the catalog and Hardcover.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('resolve-only')) {
            $resolution = $this->refresher->resolvePending();
            if ($resolution['skipped']) {
                $output->writeln('<comment>Skipped</comment> — the integration is disabled, nothing is pending, or the Hardcover rate-limit backoff is still running.');
            } else {
                $output->writeln('<info>Resolution complete.</info>');
            }
            $output->writeln(sprintf('  local:        %d', $resolution['localResolved']));
            $output->writeln(sprintf('  deferred:     %d', $resolution['deferred']));
            $summary = $resolution;
        } else {
            $summary = $this->refresher->refresh((bool) $input->getOption('force'));
            if ($summary['skipped']) {
                $output->writeln('<comment>Skipped</comment> — the integration is disabled, unconfigured, or not due yet (pass --force).');
            } else {
                $output->writeln('<info>Refresh complete.</info>');
            }
            $output->writeln(sprintf('  fetched:      %d', $summary['fetched']));
            $output->writeln(sprintf('  new:          %d', $summary['new']));
            $output->writeln(sprintf('  deleted:      %d', $summary['deleted']));
        }

        $output->writeln(sprintf('  resolved:     %d', $summary['resolved']));
        $output->writeln(sprintf('  unmatched:    %d', $summary['unmatched']));
        $output->writeln(sprintf('  pending left: %d', $summary['pendingLeft']));
        $output->writeln(sprintf('  error:        %s', $summary['error'] ?? '—'));

        $counts = $this->items->countByResolution();
        $total = array_sum($counts);
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Freeleech table:</info> %d row(s) — %d resolved, %d unmatched, %d pending.',
            $total,
            $counts[FreeleechItem::RESOLUTION_RESOLVED],
            $counts[FreeleechItem::RESOLUTION_UNMATCHED],
            $counts[FreeleechItem::RESOLUTION_PENDING],
        ));

        return $summary['error'] === null ? Command::SUCCESS : Command::FAILURE;
    }
}
