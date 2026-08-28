<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FreeleechItem;
use App\Integration\MyAnonamouse\MamFreeleechRefresher;
use App\Integration\MyAnonamouse\MamRelease;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Repository\FreeleechItemRepository;
use App\Search\DirectDownload\DirectDownloadProbe;
use App\Search\Source\ReleaseCandidate;
use App\Search\Torrent\ProwlarrConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic CLI counterpart to the scheduled freeleech ingest: runs the very same
 * MamFreeleechRefresher the message handler runs (the TorrentProbeCommand convention)
 * and prints what the sweep did, so a stale shelf can be pinned to the throttle, the
 * cookie, the seeder filter, or the Hardcover reverse lookup.
 *
 * Beyond the default refresh it also probes the release-source client directly,
 * one mode per run: --search runs an on-demand release search the way fulfillment
 * does and prints the mapped rows with their freeleech flags and dl hashes;
 * --user-info dumps every field the extended account summary parses (the live
 * proof of whether MAM's snatch_summary carries a wedge count or VIP expiry);
 * --fetch-torrent pulls the .torrent bytes behind a dl hash (the empirical check
 * of the /tor/download.php/{dlHash} URL shape), and with --wedge appends MAM's
 * `fl` flag to spend one REAL freeleech wedge, gated behind --confirm-spend.
 */
#[AsCommand(name: 'spinescout:mam:probe', description: 'Run one MyAnonamouse freeleech refresh — or probe the MAM API directly (search, user info, torrent fetch with or without a wedge).')]
final class MamProbeCommand extends Command
{
    public function __construct(
        private readonly MamFreeleechRefresher $refresher,
        private readonly FreeleechItemRepository $items,
        private readonly MyAnonamouseClient $client,
        private readonly DirectDownloadProbe $probe,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Refresh even if the configured interval has not elapsed.');
        $this->addOption('resolve-only', null, InputOption::VALUE_NONE, 'Skip the MAM fetch and only drain the pending backlog against the catalog and Hardcover.');
        $this->addOption('search', null, InputOption::VALUE_REQUIRED, 'Skip the refresh and run one on-demand release search for this text, printing the mapped rows.');
        $this->addOption('audiobook', null, InputOption::VALUE_NONE, 'With --search: target the audiobook content type instead of e-books.');
        $this->addOption('method', null, InputOption::VALUE_REQUIRED, 'With --search: category handling — categories, raw, or filtered.', ProwlarrConfig::METHOD_CATEGORIES);
        $this->addOption('user-info', null, InputOption::VALUE_NONE, 'Skip the refresh and print every field of the extended account summary (proves which extras MAM reports).');
        $this->addOption('user-info-raw', null, InputOption::VALUE_NONE, 'Like --user-info but print MAM\'s payload verbatim — field discovery for extras we do not parse yet.');
        $this->addOption('fetch-torrent', null, InputOption::VALUE_REQUIRED, 'Skip the refresh and fetch the .torrent file behind this dl hash, sanity-checking the bytes.');
        $this->addOption('wedge', null, InputOption::VALUE_NONE, 'Fetch the --fetch-torrent hash with MAM\'s `fl` flag, spending one REAL freeleech wedge; requires --confirm-spend.');
        $this->addOption('confirm-spend', null, InputOption::VALUE_NONE, 'Actually spend the wedge on a --wedge fetch instead of describing what it would do.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modes = array_keys(array_filter([
            '--search'        => $input->getOption('search') !== null,
            '--user-info'     => (bool) $input->getOption('user-info'),
            '--user-info-raw' => (bool) $input->getOption('user-info-raw'),
            '--fetch-torrent' => $input->getOption('fetch-torrent') !== null,
        ]));
        if (\count($modes) > 1) {
            $output->writeln(sprintf('<error>%s are mutually exclusive — pick one probe mode per run.</error>', implode(' and ', $modes)));

            return Command::FAILURE;
        }
        if ($modes !== [] && ($input->getOption('force') || $input->getOption('resolve-only'))) {
            $output->writeln(sprintf('<error>--force/--resolve-only belong to the default refresh and cannot be combined with %s.</error>', $modes[0]));

            return Command::FAILURE;
        }
        if ($input->getOption('audiobook') && $input->getOption('search') === null) {
            $output->writeln('<error>--audiobook only makes sense with --search.</error>');

            return Command::FAILURE;
        }
        if (($input->getOption('wedge') || $input->getOption('confirm-spend')) && $input->getOption('fetch-torrent') === null) {
            $output->writeln('<error>--wedge/--confirm-spend only make sense with --fetch-torrent.</error>');

            return Command::FAILURE;
        }
        if ($input->getOption('confirm-spend') && !$input->getOption('wedge')) {
            $output->writeln('<error>--confirm-spend only makes sense with --wedge.</error>');

            return Command::FAILURE;
        }

        if ($modes !== []) {
            if (!$this->client->isConfigured()) {
                $output->writeln('<error>MyAnonamouse is not configured — the integration is disabled or the session cookie is not set.</error>');

                return Command::FAILURE;
            }

            return match ($modes[0]) {
                '--search'        => $this->runSearch($input, $output),
                '--user-info'     => $this->runUserInfo($output),
                '--user-info-raw' => $this->runUserInfoRaw($output),
                default           => $this->runFetchTorrent(
                    (string) $input->getOption('fetch-torrent'),
                    (bool) $input->getOption('wedge'),
                    (bool) $input->getOption('confirm-spend'),
                    $output,
                ),
            };
        }

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

    /**
     * One on-demand release search, the exact plan/method/mapper path fulfillment
     * uses, printed row by row so a "MAM shows it but the app can't find it" can
     * be pinned to the query text, the category method, or the mapping.
     */
    private function runSearch(InputInterface $input, OutputInterface $output): int
    {
        $method = trim((string) $input->getOption('method'));
        if (!in_array($method, ProwlarrConfig::METHODS, true)) {
            $output->writeln(sprintf('<error>Unknown --method "%s" — use one of: %s.</error>', $method, implode(', ', ProwlarrConfig::METHODS)));

            return Command::FAILURE;
        }

        $plan = $this->probe->buildPlan(null, null, (string) $input->getOption('search'));
        if ($input->getOption('audiobook')) {
            $plan = $plan->withContentType(ReleaseCandidate::CONTENT_AUDIOBOOK);
        }

        $output->writeln(sprintf('<info>Query:</info>   "%s"', $plan->primaryQuery()));
        $output->writeln(sprintf('<info>Method:</info>  %s | <info>content:</info> %s', $method, $plan->contentType));

        $releases = $this->client->searchReleases($plan, $method);
        if ($releases === []) {
            $output->writeln('<comment>No releases</comment> — no match, or the search failed (check the log for a dead-cookie warning).');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Title', 'Author', 'Types', 'Size', 'S/L', 'Flags', 'DL hash']);
        foreach ($releases as $release) {
            $table->addRow([
                $release->mamTorrentId,
                self::truncate($release->title, 48),
                self::truncate($release->authors[0] ?? '—', 24),
                $release->filetypes ?? '—',
                self::humanSize($release->sizeBytes),
                sprintf('%d/%d', $release->seeders, $release->leechers),
                self::flags($release),
                $release->dlHash !== null ? substr($release->dlHash, 0, 12) . '…' : '—',
            ]);
        }
        $table->render();
        $output->writeln(sprintf('<info>Total:</info> %d release(s).', \count($releases)));

        return Command::SUCCESS;
    }

    /**
     * Dump the extended account summary field by field. This is the live proof of
     * which extras MAM's snatch_summary actually carries for this account — the
     * wedge count and VIP expiry are parsed tolerantly and print as '—' when the
     * payload does not report them.
     */
    private function runUserInfo(OutputInterface $output): int
    {
        $info = $this->client->fetchUserInfo();
        if ($info === null) {
            $output->writeln('<error>MAM did not return account JSON — the session cookie is likely expired or IP-locked elsewhere.</error>');

            return Command::FAILURE;
        }

        foreach ($info as $key => $value) {
            $output->writeln(sprintf('  %-11s %s', $key . ':', self::formatValue($value)));
        }

        return Command::SUCCESS;
    }

    /** The same request as --user-info, printed verbatim — field discovery. */
    private function runUserInfoRaw(OutputInterface $output): int
    {
        $data = $this->client->fetchUserInfoRaw();
        if ($data === null) {
            $output->writeln('<error>MAM did not return account JSON — the session cookie is likely expired or IP-locked elsewhere.</error>');

            return Command::FAILURE;
        }

        $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    /**
     * Fetch the .torrent bytes behind a search row's dl hash — the empirical check
     * that /tor/download.php/{dlHash} is the URL shape MAM answers, and that the
     * body is bencode rather than the HTML login page.
     *
     * With $wedge the fetch carries MAM's `fl` flag, which is how a freeleech
     * wedge is actually spent: the snatch itself consumes it. That costs a REAL
     * wedge, so it only happens once $confirmed is set.
     */
    private function runFetchTorrent(string $dlHash, bool $wedge, bool $confirmed, OutputInterface $output): int
    {
        if ($wedge && !$confirmed) {
            $output->writeln(sprintf('<comment>Dry run</comment> — this would GET /tor/download.php/%s with the `fl` flag appended, spending one REAL freeleech wedge on that torrent.', $dlHash));
            $output->writeln('Pass <info>--confirm-spend</info> to actually spend the wedge.');

            return Command::FAILURE;
        }

        $bytes = $this->client->downloadTorrentFile($dlHash, $wedge);
        if ($bytes === null) {
            $output->writeln($wedge
                ? '<error>Wedged download failed</error> — MAM refused the `fl` fetch: out of wedges, an already-free release, a bad hash, or an expired cookie (check the log).'
                : '<error>Download failed</error> — MAM refused the hash, served its HTML login page, or the request errored (check the log).');

            return Command::FAILURE;
        }

        if ($wedge) {
            $output->writeln('<info>Wedge spent</info> — MAM served the .torrent with the `fl` flag.');
        }

        $output->writeln(sprintf('<info>Fetched:</info> %d byte(s).', \strlen($bytes)));
        $head = preg_replace('/[^\x20-\x7e]/', '.', substr($bytes, 0, 24)) ?? '';
        if (str_starts_with($bytes, 'd8:announce')) {
            $output->writeln('<info>Looks like a bencoded torrent</info> — the body starts with "d8:announce".');

            return Command::SUCCESS;
        }
        if (str_starts_with($bytes, 'd')) {
            $output->writeln(sprintf('<comment>Bencode dictionary, but not the usual announce head</comment> — first bytes: "%s".', $head));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>Not bencode</error> — first bytes: "%s".', $head));

        return Command::FAILURE;
    }

    /** The freeleech letters for one row: FREE / VIP-FL / PERSONAL / VIP, '—' when plain. */
    private static function flags(MamRelease $release): string
    {
        $flags = [];
        if ($release->free) {
            $flags[] = 'FREE';
        }
        if ($release->flVip) {
            $flags[] = 'VIP-FL';
        }
        if ($release->personalFreeleech) {
            $flags[] = 'PERSONAL';
        }
        if ($release->vip) {
            $flags[] = 'VIP';
        }

        return $flags === [] ? '—' : implode(' ', $flags);
    }

    private static function truncate(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, $width, '…');
    }

    private static function humanSize(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }

        $value = (float) $bytes;
        foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
            if ($value < 1024 || $unit === 'TiB') {
                return $unit === 'B' ? sprintf('%d B', $bytes) : sprintf('%.1f %s', $value, $unit);
            }
            $value /= 1024;
        }

        return sprintf('%d B', $bytes);
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return (string) $value;
    }
}
