<?php

declare(strict_types=1);

namespace App\Integration\MyAnonamouse;

/**
 * Narrow seam over the operator's saved MyAnonamouse settings: the display/behaviour
 * config, the rotating session cookie, and the cached account snapshot. Implemented by
 * IntegrationRepository (the single implementation, so Symfony auto-aliases this
 * interface to it).
 *
 * Exists so the MAM client and the freeleech refresh handler depend on just what they
 * read and write, and stay unit-testable without doubling the final repository.
 */
interface MyAnonamouseSettingsProvider
{
    public function getMyAnonamouseConfig(): MyAnonamouseConfig;

    /**
     * The stored `mam_id` session cookie value, trimmed; null when absent or blank
     * (i.e. the integration is not usable until the operator pastes one).
     */
    public function getMamSessionCookie(): ?string;

    /**
     * MAM rotates `mam_id` via Set-Cookie on dynamic sessions; the rotated value must be
     * persisted immediately or the session dies. Flushes and drops the settings memo.
     * Never log the value.
     */
    public function persistRotatedMamSessionCookie(string $newValue): void;

    /**
     * Last snapshot from jsonLoad.php — keys such as isVip, username, class, ratio.
     * Empty array when nothing has been fetched yet.
     *
     * @return array<string, mixed>
     */
    public function getMamAccountState(): array;

    /**
     * Replaces the stored account snapshot. Flushes and drops the settings memo.
     *
     * @param array<string, mixed> $state
     */
    public function saveMamAccountState(array $state): void;
}
