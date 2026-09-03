<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

/**
 * The result of resolving ONE MyAnonamouse torrent id to a grabbable row
 * ({@see MyAnonamouseClient::fetchTorrentById()}).
 *
 * A plain nullable MamRelease would not do: the caller renders the failure to an
 * operator staring at a link they just pasted, and "MAM has no torrent 182137",
 * "your session is dead" and "this row carries no download hash" are three
 * completely different things to say — and three different things to do about it.
 * Every failure is a value, never an exception: the rest of the MAM client
 * degrades rather than throwing into a request, and this follows that rule.
 *
 * Immutable.
 */
final readonly class MamTorrentLookup
{
    public const OK = 'ok';
    /** MAM answered, but there is no such torrent (deleted, or invisible to this account). */
    public const NOT_FOUND = 'not_found';
    /** MAM refused us: a 403, or the HTML login page served in place of the real one. */
    public const SESSION_EXPIRED = 'session_expired';
    /** The torrent exists and is visible, but nothing on it yields a `dl` hash to grab. */
    public const NO_DOWNLOAD_HASH = 'no_download_hash';
    /** The integration is off / has no session cookie — nothing was requested at all. */
    public const NOT_CONFIGURED = 'not_configured';
    /** Transport failure, a non-200 that is not 403/404, or an unreadable page. */
    public const FAILED = 'failed';

    private function __construct(
        public string $status,
        public ?MamRelease $release,
        /** Operator-facing, already complete — the caller renders it verbatim. */
        public string $message,
    ) {
    }

    public static function found(MamRelease $release): self
    {
        return new self(self::OK, $release, '');
    }

    public static function error(string $status, string $message): self
    {
        return new self($status, null, $message);
    }

    public function ok(): bool
    {
        return $this->status === self::OK && $this->release !== null;
    }

    /**
     * The HTTP status the JSON response should carry. Everything here is a clean,
     * expected outcome the panel renders inline, so nothing is ever a 5xx: a
     * missing torrent is a 404 and every other refusal is a 409 ("configured, but
     * cannot do this right now") — the same code the runner already returns for
     * "MyAnonamouse search is not configured".
     */
    public function httpStatus(): int
    {
        return $this->status === self::NOT_FOUND ? 404 : 409;
    }
}
