<?php

declare(strict_types=1);

namespace App\Integration\Grimmory;

use App\Entity\Integration;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for Grimmory's NATIVE (JWT-only) API, as opposed to the read-only
 * Komga-compatible surface used by {@see GrimmoryClient}. The native root is the
 * server root — i.e. the configured Komga base URL WITHOUT the trailing `/komga`
 * path segment. Authentication requires a real Grimmory user account with
 * metadata-edit permission (NOT the OPDS credentials used for the Komga layer);
 * those credentials live in the integration's options blob under
 * options['native'] = ['username' => string, 'password' => string, 'sidecarImport' => bool].
 *
 * Its one job: trigger sidecar metadata imports. SpineScout writes sidecar JSON
 * files next to audiobooks, but Grimmory never reads them during scans — an
 * explicit `/libraries/{id}/sidecar/import-all` call is required.
 */
final class GrimmoryNativeClient
{
    private const API_PREFIX = '/api/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * The sidecar-import feature is opt-in: it needs a base URL, the
     * options.native.sidecarImport flag set to true, and non-blank native credentials.
     */
    public function isConfigured(Integration $integration): bool
    {
        $base = $integration->getBaseUrl();
        if ($base === null || trim($base) === '') {
            return false;
        }
        $native = $this->nativeOptions($integration);
        if (($native['sidecarImport'] ?? null) !== true) {
            return false;
        }
        $username = $native['username'] ?? null;
        $password = $native['password'] ?? null;

        return is_string($username) && trim($username) !== ''
            && is_string($password) && trim($password) !== '';
    }

    /**
     * Logs into the native API and triggers a sidecar import for every target
     * library (the integration's selected libraries when a real subset is
     * selected, otherwise all discovered libraries — same semantics as the
     * library sync's filter). One library failing does not abort the rest;
     * throws only when login fails, the server is unreachable, or EVERY
     * library import failed.
     *
     * @return array{libraries: int, imported: int} successfully imported library count + summed "imported" totals
     *
     * @throws GrimmoryException
     */
    public function importAllSidecars(Integration $integration): array
    {
        $root = $this->nativeRoot($integration);
        $token = $this->login($integration, $root);

        $libraryIds = $this->resolveTargetLibraryIds($integration);
        if ($libraryIds === []) {
            return ['libraries' => 0, 'imported' => 0];
        }

        $succeeded = 0;
        $imported = 0;
        $errors = [];
        foreach ($libraryIds as $libraryId) {
            try {
                $imported += $this->importLibrarySidecars($root, $token, $libraryId);
                $succeeded++;
            } catch (GrimmoryException $e) {
                $errors[] = sprintf('library %s: %s', $libraryId, $e->getMessage());
            }
        }

        if ($succeeded === 0) {
            throw new GrimmoryException(
                'Grimmory sidecar import failed for every library: ' . implode('; ', $errors)
            );
        }

        return ['libraries' => $succeeded, 'imported' => $imported];
    }

    /**
     * Native API root = server root: the configured Komga base URL with one
     * trailing `/komga` segment (Grimmory's Komga-compat mount) stripped.
     */
    private function nativeRoot(Integration $integration): string
    {
        $base = $integration->getBaseUrl();
        if ($base === null || trim($base) === '') {
            throw new GrimmoryException('Grimmory (Komga) server URL is not configured.');
        }
        $root = rtrim($base, '/');
        if (preg_match('~^(.*)/komga$~i', $root, $m) === 1) {
            $root = $m[1];
        }

        return $root;
    }

    /** @throws GrimmoryException */
    private function login(Integration $integration, string $root): string
    {
        $native = $this->nativeOptions($integration);
        $username = is_string($native['username'] ?? null) ? $native['username'] : '';
        $password = is_string($native['password'] ?? null) ? $native['password'] : '';

        try {
            $response = $this->httpClient->request('POST', $root . self::API_PREFIX . '/auth/login', [
                'timeout' => 30,
                'headers' => ['Accept' => 'application/json'],
                'json' => ['username' => $username, 'password' => $password],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400 && $status < 500) {
                throw new GrimmoryException(sprintf(
                    'Grimmory native login failed (HTTP %d): the sidecar import needs a real Grimmory user account with metadata permission — distinct from the Komga/OPDS credentials.',
                    $status,
                ));
            }
            if ($status >= 500) {
                throw new GrimmoryException(sprintf('Grimmory native login returned HTTP %d.', $status));
            }
            $data = $response->toArray(false);
        } catch (TransportException $e) {
            throw new GrimmoryException('Could not reach the Grimmory native API: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new GrimmoryException('Could not parse the Grimmory native login response: ' . $e->getMessage(), previous: $e);
        }

        $token = $data['accessToken'] ?? $data['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new GrimmoryException('Grimmory native login succeeded but returned no access token.');
        }

        return $token;
    }

    /**
     * Returns the number of sidecar files the server reports as imported.
     *
     * @throws GrimmoryException
     */
    private function importLibrarySidecars(string $root, string $token, string $libraryId): int
    {
        $path = sprintf('/libraries/%s/sidecar/import-all', rawurlencode($libraryId));

        try {
            $response = $this->httpClient->request('POST', $root . self::API_PREFIX . $path, [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new GrimmoryException(sprintf('Grimmory returned HTTP %d for %s', $status, $path));
            }
            $data = $response->toArray(false);
        } catch (TransportException $e) {
            throw new GrimmoryException('Could not reach the Grimmory native API: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new GrimmoryException('Could not parse the Grimmory sidecar import response: ' . $e->getMessage(), previous: $e);
        }

        $imported = $data['imported'] ?? null;
        if (is_int($imported)) {
            return $imported;
        }
        if (is_string($imported) && ctype_digit($imported)) {
            return (int) $imported;
        }

        return 0;
    }

    /**
     * Target = the selected libraries when they form a real subset of the
     * discovered ones, otherwise every discovered library. Mirrors the
     * semantics of {@see GrimmoryLibrarySync}'s library filter (empty
     * selection, a selection with no discovered overlap, or a selection
     * covering everything all mean "all libraries"). The ids the Komga layer
     * exposes are the native ids — Grimmory's Komga adapter passes them through.
     *
     * @return list<string>
     */
    private function resolveTargetLibraryIds(Integration $integration): array
    {
        $discoveredIds = [];
        foreach ($integration->getDiscoveredLibraries() as $library) {
            if (isset($library['id']) && (string) $library['id'] !== '') {
                $discoveredIds[] = (string) $library['id'];
            }
        }
        $discoveredIds = array_values(array_unique($discoveredIds));

        $selected = $integration->getSelectedLibraries();
        if ($selected === []) {
            return $discoveredIds;
        }
        $intersection = array_values(array_intersect($selected, $discoveredIds));
        if ($intersection === [] || count($intersection) === count($discoveredIds)) {
            return $discoveredIds;
        }

        return $intersection;
    }

    /** @return array<string, mixed> */
    private function nativeOptions(Integration $integration): array
    {
        $native = $integration->getOptions()['native'] ?? null;

        return is_array($native) ? $native : [];
    }
}
