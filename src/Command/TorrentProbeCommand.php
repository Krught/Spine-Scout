<?php

declare(strict_types=1);

namespace App\Command;

use App\Integration\Prowlarr\ProwlarrClient;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\Source\ReleaseCandidate;
use App\Search\Torrent\TorrentMatchScorer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic CLI counterpart to spinescout:dd:probe for the torrent pipeline:
 * run a Prowlarr search for a title/author/ISBN exactly the way the interactive
 * search and the automatic audiobook pipeline do — same plan, same categories,
 * same scorer — and show what came back at each stage, so "Prowlarr shows a
 * result but the app finds nothing" can be pinned to the query, the category
 * scope, the mapper, or the score filter.
 */
#[AsCommand(name: 'spinescout:torrent:probe', description: 'Search Prowlarr the way the app does and show the raw and scored results.')]
final class TorrentProbeCommand extends Command
{
    public function __construct(
        private readonly ProwlarrClient $prowlarr,
        private readonly TorrentMatchScorer $scorer,
        private readonly DirectDownloadProbe $probe,
        private readonly IntegrationRepository $integrations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Title to search for.', '');
        $this->addOption('author', null, InputOption::VALUE_REQUIRED, 'Author.', '');
        $this->addOption('isbn', null, InputOption::VALUE_REQUIRED, 'ISBN.', '');
        $this->addOption('ebook', null, InputOption::VALUE_NONE, 'Search ebook categories instead of the audiobook ones.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->prowlarr->isConfigured()) {
            $output->writeln('<error>Prowlarr is not configured.</error>');

            return Command::FAILURE;
        }

        $plan = $this->probe->buildPlan(
            (string) $input->getOption('isbn'),
            (string) $input->getOption('author'),
            (string) $input->getOption('title'),
        );
        if (!$input->getOption('ebook')) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        $config = $this->integrations->getProwlarrConfig();
        $output->writeln(sprintf('<info>Query:</info>      "%s"', $plan->primaryQuery()));
        $output->writeln(sprintf('<info>Categories:</info> %s', $plan->contentType === ReleaseCandidate::CONTENT_AUDIOBOOK ? implode(', ', $config->categories) : '7000, 7020 (ebook)'));
        $output->writeln(sprintf('<info>Min seeders:</info> %d | <info>max size:</info> %s', $config->minSeeders, $config->maxSizeBytes !== null ? $config->maxSizeBytes . ' bytes' : 'none'));

        $candidates = $this->prowlarr->search($plan);
        $output->writeln(sprintf('<info>Raw candidates from Prowlarr:</info> %d', \count($candidates)));
        foreach ($candidates as $c) {
            $output->writeln(sprintf(
                '  - %s | %s | %s seeders | %s | cats: %s',
                $c->title,
                $c->indexer ?? '?',
                $c->seeders ?? '?',
                $c->format ?? 'no format',
                implode(',', (array) ($c->extra['categoryIds'] ?? [])),
            ));
        }

        $scored = $this->scorer->scored($candidates, $plan, $config->matchPolicy());
        $output->writeln(sprintf('<info>After score/filter:</info> %d', \count($scored)));
        foreach ($scored as $sr) {
            $output->writeln(sprintf(
                '  - %.3f (match %.2f seeders %.2f size %.2f format %.2f) %s',
                $sr->score,
                $sr->components['match'],
                $sr->components['seeders'],
                $sr->components['size'],
                $sr->components['format'],
                $sr->candidate->title,
            ));
        }

        return Command::SUCCESS;
    }
}
