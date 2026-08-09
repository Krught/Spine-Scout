<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

/**
 * Operator config for the MyAnonamouse freeleech integration. The session cookie
 * lives on the Integration row of kind `myanonamouse` (credentials['mam_id']) and
 * the refresh cadence on that row's syncIntervalMinutes column; this value object
 * holds the display/behaviour knobs stored in the row's options['config'] blob.
 *
 * Immutable.
 */
final class MyAnonamouseConfig
{
    public const DEFAULT_BASE_URL = 'https://www.myanonamouse.net';

    /** MAM main category for audiobooks. */
    public const MAIN_CAT_AUDIOBOOK = 13;

    /** MAM main category for e-books. */
    public const MAIN_CAT_BOOK = 14;

    /**
     * VIP freeleech is ~300k torrents — effectively the whole tracker — so pulling it is
     * opt-in and always capped to the newest slice. These bound {@see $vipFetchLimit}.
     */
    public const DEFAULT_VIP_FETCH_LIMIT = 500;
    public const MIN_VIP_FETCH_LIMIT = 100;
    public const MAX_VIP_FETCH_LIMIT = 2000;

    /** Not promoted: the constructor clamps it, so the invariant holds for every instance. */
    public readonly int $vipFetchLimit;

    /**
     * @param bool        $enabled                 Master switch for the integration. NOT part of the options['config']
     *                                             blob (toArray() omits it) — it mirrors the myanonamouse Integration
     *                                             row's `enabled` column, injected by
     *                                             IntegrationRepository::getMyAnonamouseConfig().
     * @param string      $baseUrl                 MAM origin; also mirrored on the row's baseUrl column, which wins on read
     * @param bool        $showOnHomepage          Render the freeleech Discover carousel row
     * @param bool        $showBrowseShelf         Expose /browse?shelf=freeleech
     * @param bool        $bookFormatEnabled       Pull e-books (main category 14)
     * @param bool        $audiobookFormatEnabled  Pull audiobooks (main category 13)
     * @param int         $minSeeders              Drop freeleech items below this seed count
     * @param bool        $fetchVipFreeleech       Also sweep MAM's VIP freeleech pool ('fl-VIP'). Off by default:
     *                                             the pool is the whole tracker, so it only ever arrives capped.
     * @param int         $vipFetchLimit           Newest-N cap on the VIP pull, per main category. Clamped to
     *                                             MIN_VIP_FETCH_LIMIT..MAX_VIP_FETCH_LIMIT on construction.
     * @param bool        $dynamicSeedboxUpdate    Re-register the server's public IP with the MAM session on refresh
     * @param string|null $proxyUrl                Optional http|socks5 proxy applied to MAM traffic only; null = direct
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly bool $showOnHomepage = true,
        public readonly bool $showBrowseShelf = true,
        public readonly bool $bookFormatEnabled = true,
        public readonly bool $audiobookFormatEnabled = true,
        public readonly int $minSeeders = 0,
        public readonly bool $fetchVipFreeleech = false,
        int $vipFetchLimit = self::DEFAULT_VIP_FETCH_LIMIT,
        public readonly bool $dynamicSeedboxUpdate = false,
        public readonly ?string $proxyUrl = null,
    ) {
        $this->vipFetchLimit = self::clampVipFetchLimit($vipFetchLimit);
    }

    /** The only place the VIP cap's bounds are enforced; every entry point routes through it. */
    public static function clampVipFetchLimit(int $limit): int
    {
        return max(self::MIN_VIP_FETCH_LIMIT, min(self::MAX_VIP_FETCH_LIMIT, $limit));
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed>|null $config  JSON-decoded options['config'] blob
     * @param bool                      $enabled The myanonamouse row's `enabled` column (see the constructor doc)
     */
    public static function fromArray(?array $config, bool $enabled = false): self
    {
        if ($config === null) {
            return new self(enabled: $enabled);
        }

        $baseUrl = rtrim(trim((string) ($config['baseUrl'] ?? '')), '/');
        $proxyUrl = trim((string) ($config['proxyUrl'] ?? ''));

        $minSeeders = isset($config['minSeeders']) && is_numeric($config['minSeeders'])
            ? max(0, (int) $config['minSeeders'])
            : 0;

        $vipFetchLimit = isset($config['vipFetchLimit']) && is_numeric($config['vipFetchLimit'])
            ? (int) $config['vipFetchLimit']
            : self::DEFAULT_VIP_FETCH_LIMIT;

        return new self(
            $enabled,
            $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL,
            (bool) ($config['showOnHomepage'] ?? true),
            (bool) ($config['showBrowseShelf'] ?? true),
            (bool) ($config['bookFormatEnabled'] ?? true),
            (bool) ($config['audiobookFormatEnabled'] ?? true),
            $minSeeders,
            (bool) ($config['fetchVipFreeleech'] ?? false),
            $vipFetchLimit,
            (bool) ($config['dynamicSeedboxUpdate'] ?? false),
            $proxyUrl !== '' ? $proxyUrl : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'baseUrl'                => $this->baseUrl,
            'showOnHomepage'         => $this->showOnHomepage,
            'showBrowseShelf'        => $this->showBrowseShelf,
            'bookFormatEnabled'      => $this->bookFormatEnabled,
            'audiobookFormatEnabled' => $this->audiobookFormatEnabled,
            'minSeeders'             => $this->minSeeders,
            'fetchVipFreeleech'      => $this->fetchVipFreeleech,
            'vipFetchLimit'          => $this->vipFetchLimit,
            'dynamicSeedboxUpdate'   => $this->dynamicSeedboxUpdate,
            'proxyUrl'               => $this->proxyUrl,
        ];
    }

    /**
     * MAM main categories to pull, honouring the per-format toggles.
     *
     * @return list<int>
     */
    public function enabledMainCategories(): array
    {
        $cats = [];
        if ($this->audiobookFormatEnabled) {
            $cats[] = self::MAIN_CAT_AUDIOBOOK;
        }
        if ($this->bookFormatEnabled) {
            $cats[] = self::MAIN_CAT_BOOK;
        }
        return $cats;
    }
}
