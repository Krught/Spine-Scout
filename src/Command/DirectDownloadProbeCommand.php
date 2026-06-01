<?php

declare(strict_types=1);

namespace App\Command;

use App\Search\DirectDownload\DirectDownloadEvaluator;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\DirectDownload\DirectDownloadSource;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic CLI counterpart to the Settings → Development probe: build (and
 * optionally fetch) the direct-download search URL for an ISBN/author/title from
 * current settings. Shares its implementation with the web page via
 * DirectDownloadProbe.
 */
#[AsCommand(name: 'spinescout:dd:probe', description: 'Build (and optionally fetch) the direct-download search URL for an ISBN from current settings.')]
final class DirectDownloadProbeCommand extends Command
{
    public function __construct(
        private readonly DirectDownloadProbe $probe,
        private readonly DirectDownloadEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('isbn', InputArgument::OPTIONAL, 'ISBN to search for.', '');
        $this->addOption('author', null, InputOption::VALUE_REQUIRED, 'Author (used when no ISBN).', '');
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title (used when no ISBN).', '');
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Limit to one source id (annas_archive, libgen, zlibrary, welib).', '');
        $this->addOption('fetch', null, InputOption::VALUE_NONE, 'Actually run each source search and parse results (outbound requests to your mirrors).');
        $this->addOption('score', null, InputOption::VALUE_NONE, 'Search every source in failover order, score candidates, and show the best-match pick.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->probe->config();

        $output->writeln('<info>Configured source priority:</info>');
        if ($config->indexerPriority === []) {
            $output->writeln('  (none — nothing saved yet)');
        }
        foreach ($config->indexerPriority as $row) {
            $src = DirectDownloadSource::tryFromId($row['id']);
            $mirrors = $config->mirrorsFor($row['id'])->toArray();
            $output->writeln(sprintf(
                '  %s %s | %d mirror(s) | first: %s%s',
                $row['enabled'] ? '[x]' : '[ ]',
                $src?->label() ?? $row['id'],
                count($mirrors),
                $mirrors[0] ?? '—',
                ($src?->isSearchSource() ?? false) ? ' (search source)' : '',
            ));
        }

        $plan = $this->probe->buildPlan(
            (string) $input->getArgument('isbn'),
            (string) $input->getOption('author'),
            (string) $input->getOption('title'),
        );

        if ($input->getOption('score')) {
            return $this->renderScored($plan, $output);
        }

        // --source limits the run to one source by enabling only it (ephemeral).
        $only = trim((string) $input->getOption('source'));
        $effective = $only !== '' ? $this->probe->effectiveConfig([$only]) : $this->probe->effectiveConfig(null);

        if (!$input->getOption('fetch')) {
            $output->writeln('');
            $output->writeln('<info>Generated search URLs:</info>');
            $searches = $this->probe->plannedSearches($plan, $effective);
            if ($searches === []) {
                $output->writeln('  (no enabled source with mirrors)');
            }
            foreach ($searches as $s) {
                $output->writeln(sprintf('  <comment>%s</comment>', $s->label));
                $output->writeln($s->available && $s->url !== null ? '    ' . $s->url : '    (' . ($s->reason ?? 'unavailable') . ')');
            }
            $output->writeln('');
            $output->writeln('<comment>Pass --fetch to run each search and parse results.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>Fetching…</info>');
        $runs = $this->probe->run($plan, $effective);
        if ($runs === []) {
            $output->writeln('  (no enabled source ran)');
        }
        foreach ($runs as $run) {
            $s = $run['search'];
            $output->writeln('');
            if (!$s->available) {
                $output->writeln(sprintf('<comment>%s</comment> — %s', $s->label, $s->reason ?? 'unavailable'));
                continue;
            }
            $output->writeln(sprintf('<comment>%s</comment> (mirror: %s) — parsed %d record(s)', $s->label, $s->mirror, count($run['records'])));
            foreach ($run['records'] as $r) {
                $output->writeln(sprintf(
                    '  %s | %s | %s | %s | %s',
                    substr((string) $r['id'], 0, 16),
                    $r['title'],
                    $r['author'] ?? '?',
                    $r['format'] ?? '?',
                    $r['size'] ?? '?',
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function renderScored(ReleaseSearchPlan $plan, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>Searching, verifying ISBNs per record, and scoring…</info>');
        $result = $this->evaluator->evaluate($plan);

        if ($result->unavailableReason !== null) {
            $output->writeln(sprintf('<error>%s</error>', $result->unavailableReason));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Picked source: %s | mirror: %s | threshold %d | %d of %d candidate(s) qualify.',
            $result->pickedSource ?? '—',
            $result->firstMirror() ?? '—',
            $result->threshold,
            $result->qualifyingCount(),
            $result->totalCount(),
        ));

        foreach ($result->scored as $s) {
            $breakdown = [];
            foreach ($s->score->sentCategories() as $cat) {
                $breakdown[] = sprintf('%s=%d/%d', $cat->key, $cat->earned, $cat->weight);
            }
            $output->writeln(sprintf(
                '  %s [%3d] %s | %s | %s',
                $s->qualifies ? '[x]' : '[ ]',
                $s->score->total,
                implode(' ', $breakdown),
                $s->candidate->format ?? '?',
                $s->candidate->title,
            ));
        }

        $output->writeln('');
        if ($result->pick !== null) {
            $output->writeln(sprintf(
                '<info>Best-match pick:</info> %s (%s, %s)',
                $result->pick->title,
                $result->pick->format ?? '?',
                substr($result->pick->sourceId, 0, 16),
            ));
        } else {
            $output->writeln('<comment>No candidate qualified for auto-pick.</comment>');
        }

        return Command::SUCCESS;
    }
}
