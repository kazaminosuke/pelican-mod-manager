<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Support;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use ZipArchive;

/**
 * High-fidelity HTTP-transport-layer Pelican Wings emulator (pelican-dev/wings@27f67c8)
 * backed by an isolated temporary disk sandbox.
 *
 * Intercepts requests targeting the configured Wings host via Laravel's Http::fake(),
 * returning null for all other hosts so upstream provider fakes compose cleanly.
 */
final class StrictWingsFake
{
    public const DEFAULT_SCHEME = 'https';

    public const DEFAULT_HOST = 'wings.e2e.test';

    public const DEFAULT_PORT = 8080;

    public const DEFAULT_TOKEN_ID = 'e2eTokenId123456';

    public const DEFAULT_TOKEN = 'e2e-daemon-secret-token-0123456789abcdef';

    public const MAX_CONCURRENT_PULLS = 3;

    public const CURL_ERR_6_DNS = 'cURL error 6: Could not resolve host: wings.e2e.test';

    public const CURL_ERR_7_REFUSED = 'cURL error 7: Failed to connect to wings.e2e.test port 8080: Connection refused';

    public const CURL_ERR_35_SSL = 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to wings.e2e.test:8080';

    public const CURL_ERR_28_RESOLVE = 'cURL error 28: Resolving timed out after 5000 milliseconds';

    public const CURL_ERR_28_CONNECT = 'cURL error 28: Connection timed out after 5001 milliseconds';

    public const CURL_ERR_28_OPERATION = 'cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received';

    private string $baseDir;

    private bool $disableRemoteDownload = false;

    private bool $forceAsyncPull = false;

    private bool $rangeResponseEnabled = false;

    /** @var array<string, int> */
    private array $activePulls = [];

    /** @var array<string, string> */
    private array $serverStates = [];

    /** @var array<string, string|Closure> */
    private array $pullFixtures = [];

    private ?Closure $pullFallback = null;

    private ?Closure $onReinstall = null;

    /** @var list<array{type: string, endpoint: string, message?: string, status?: int, body?: array<string, mixed>|string, remaining: int, path_contains: ?string}> */
    private array $faults = [];

    /** @var array<string, list<Closure>> */
    private array $beforeHooks = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var list<array{endpoint: string, method: string, url: string, server_uuid: string, headers: array<string, list<string>>, query: array<string, mixed>, status: int}> */
    private array $recorded = [];

    /** @var array<string, array<string, int>> */
    private array $extractedModes = [];

    public function __construct(
        private readonly string $scheme = self::DEFAULT_SCHEME,
        private readonly string $host = self::DEFAULT_HOST,
        private readonly int $port = self::DEFAULT_PORT,
        private readonly string $daemonTokenId = self::DEFAULT_TOKEN_ID,
        private readonly string $daemonToken = self::DEFAULT_TOKEN,
    ) {
        $dir = sys_get_temp_dir().'/pmm-e2e-wings-'.bin2hex(random_bytes(8));
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create StrictWingsFake sandbox directory [{$dir}].");
        }
        $this->baseDir = $dir;
        $this->resetMetrics();
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function daemonTokenId(): string
    {
        return $this->daemonTokenId;
    }

    public function daemonToken(): string
    {
        return $this->daemonToken;
    }

    public function userAgentHeader(): string
    {
        return "Pelican Wings/v1.0.0 (id:{$this->daemonTokenId})";
    }

    public function setDisableRemoteDownload(bool $disabled): self
    {
        $this->disableRemoteDownload = $disabled;

        return $this;
    }

    public function setForceAsyncPull(bool $async): self
    {
        $this->forceAsyncPull = $async;

        return $this;
    }

    public function setRangeResponseEnabled(bool $enabled): self
    {
        $this->rangeResponseEnabled = $enabled;

        return $this;
    }

    public function setActivePullCount(string $serverUuid, int $count): self
    {
        $this->activePulls[$serverUuid] = max(0, $count);

        return $this;
    }

    public function setServerState(string $serverUuid, string $state): self
    {
        $this->serverStates[$serverUuid] = $state;

        return $this;
    }

    public function serverState(string $serverUuid): string
    {
        return $this->serverStates[$serverUuid] ?? 'offline';
    }

    public function onReinstall(?Closure $callback): self
    {
        $this->onReinstall = $callback;

        return $this;
    }

    public function registerPullFixture(string $url, string|Closure $bytesOrResolver): self
    {
        $this->pullFixtures[$url] = $bytesOrResolver;

        return $this;
    }

    public function unregisterPullFixture(string $url): self
    {
        unset($this->pullFixtures[$url]);

        return $this;
    }

    public function setPullFallback(?Closure $fallback): self
    {
        $this->pullFallback = $fallback;

        return $this;
    }

    public function injectConnectionError(
        string $endpoint,
        string $curlMessage,
        int $times = 1,
        ?string $pathContains = null,
    ): self {
        $this->faults[] = [
            'type' => 'connect',
            'endpoint' => $endpoint,
            'message' => $curlMessage,
            'remaining' => max(1, $times),
            'path_contains' => $pathContains,
        ];

        return $this;
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    public function injectHttpError(
        string $endpoint,
        int $status,
        array|string $body = ['error' => 'Injected error'],
        int $times = 1,
        ?string $pathContains = null,
    ): self {
        $this->faults[] = [
            'type' => 'http',
            'endpoint' => $endpoint,
            'status' => $status,
            'body' => $body,
            'remaining' => max(1, $times),
            'path_contains' => $pathContains,
        ];

        return $this;
    }

    public function beforeEndpoint(string $endpoint, Closure $hook): self
    {
        $this->beforeHooks[$endpoint][] = $hook;

        return $this;
    }

    public function clearFaults(): self
    {
        $this->faults = [];
        $this->beforeHooks = [];

        return $this;
    }

    public function resetMetrics(): void
    {
        $this->counters = [
            'listDirectory' => 0,
            'readStream' => 0,
            'readPrefix' => 0,
            'putStream' => 0,
            'pull' => 0,
            'decompress' => 0,
            'rename' => 0,
            'delete' => 0,
            'createDirectory' => 0,
            'copy' => 0,
            'chmod' => 0,
            'serverDetails' => 0,
            'power' => 0,
            'sync' => 0,
            'reinstall' => 0,
            'total' => 0,
            'renameItems' => 0,
            'deleteItems' => 0,
            'faultsInjected' => 0,
            'bytesPanelToWings' => 0,
            'bytesWingsToPanel' => 0,
            'totalHttpBytesPanelToWings' => 0,
            'totalHttpBytesWingsToPanel' => 0,
        ];
        $this->recorded = [];
    }

    public function count(string $metric): int
    {
        return $this->counters[$metric] ?? 0;
    }

    /** @return array<string, int> */
    public function metrics(): array
    {
        return $this->counters;
    }

    /**
     * @return list<array{endpoint: string, method: string, url: string, server_uuid: string, headers: array<string, list<string>>, query: array<string, mixed>, status: int}>
     */
    public function recordedRequests(?string $endpoint = null): array
    {
        if ($endpoint === null) {
            return $this->recorded;
        }

        return array_values(array_filter(
            $this->recorded,
            static fn (array $item): bool => $item['endpoint'] === $endpoint,
        ));
    }

    /** @return array<string, int> */
    public function extractedModes(string $serverUuid): array
    {
        return $this->extractedModes[$serverUuid] ?? [];
    }

    public function assertEndpointCalled(string $endpoint, int $expected, string $message = ''): void
    {
        Assert::assertSame(
            $expected,
            $this->count($endpoint),
            $message !== '' ? $message : "Expected StrictWingsFake endpoint [{$endpoint}] to be called {$expected} time(s).",
        );
    }

    public function assertEndpointCalledAtMost(string $endpoint, int $max, string $message = ''): void
    {
        Assert::assertLessThanOrEqual(
            $max,
            $this->count($endpoint),
            $message !== '' ? $message : "Expected StrictWingsFake endpoint [{$endpoint}] to be called at most {$max} time(s).",
        );
    }

    public function assertTotalCallsAtMost(int $max, string $message = ''): void
    {
        Assert::assertLessThanOrEqual(
            $max,
            $this->count('total'),
            $message !== '' ? $message : "Expected total Wings HTTP calls <= {$max}, got {$this->count('total')}.",
        );
    }

    public function assertBytesWingsToPanelAtMost(int $max, string $message = ''): void
    {
        Assert::assertLessThanOrEqual(
            $max,
            $this->count('bytesWingsToPanel'),
            $message !== '' ? $message : "Expected Wings->Panel file bytes <= {$max}, got {$this->count('bytesWingsToPanel')}.",
        );
    }

    public function assertBytesPanelToWingsAtMost(int $max, string $message = ''): void
    {
        Assert::assertLessThanOrEqual(
            $max,
            $this->count('bytesPanelToWings'),
            $message !== '' ? $message : "Expected Panel->Wings file bytes <= {$max}, got {$this->count('bytesPanelToWings')}.",
        );
    }

    public function assertNoTemporaryModManagerFiles(string $serverUuid): void
    {
        $leftovers = [];
        foreach ($this->listAllPaths($serverUuid) as $relativePath) {
            foreach (explode('/', $relativePath) as $segment) {
                if (str_starts_with($segment, '.mod-manager-stage-')
                    || str_starts_with($segment, '.mod-manager-next-')
                    || str_starts_with($segment, '.mod-manager-prev-')
                    || str_starts_with($segment, '.mod-manager-pull-')
                    || str_starts_with($segment, '.mod-manager-bundle-')) {
                    $leftovers[] = $relativePath;
                    break;
                }
            }
        }

        Assert::assertSame(
            [],
            $leftovers,
            'Expected zero leftover temporary mod-manager staging/bundle/backup files on Wings, found: '.implode(', ', $leftovers),
        );
    }

    public function serverRootPath(string $serverUuid): string
    {
        $root = $this->baseDir.'/'.$serverUuid;
        if (!is_dir($root)) {
            mkdir($root, 0755, true);
        }

        return $root;
    }

    public function seedFile(string $serverUuid, string $relativePath, string $contents, ?int $mtime = null, int $mode = 0644): void
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            throw new RuntimeException("Invalid seed file path [{$relativePath}].");
        }

        $abs = $this->serverRootPath($serverUuid).'/'.$norm;
        $parent = dirname($abs);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        file_put_contents($abs, $contents);
        @chmod($abs, $mode);
        if ($mtime !== null) {
            @touch($abs, $mtime);
        }
    }

    public function setFileMtime(string $serverUuid, string $relativePath, int $mtime): void
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            throw new RuntimeException("Invalid file path [{$relativePath}].");
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;
        if (!is_file($abs)) {
            throw new RuntimeException("File [{$relativePath}] does not exist on server [{$serverUuid}].");
        }
        @touch($abs, $mtime);
    }

    public function seedDirectory(string $serverUuid, string $relativePath): void
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            $this->serverRootPath($serverUuid);

            return;
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;
        if (!is_dir($abs)) {
            mkdir($abs, 0755, true);
        }
    }

    public function seedSymlink(string $serverUuid, string $relativePath, string $target): void
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            throw new RuntimeException("Invalid symlink path [{$relativePath}].");
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;
        $parent = dirname($abs);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        if (@lstat($abs) !== false) {
            @unlink($abs);
        }
        symlink($target, $abs);
    }

    public function hasFile(string $serverUuid, string $relativePath): bool
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            return false;
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;

        return !is_link($abs) && is_file($abs);
    }

    public function hasPath(string $serverUuid, string $relativePath): bool
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            return is_dir($this->serverRootPath($serverUuid));
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;

        return @lstat($abs) !== false;
    }

    public function readFile(string $serverUuid, string $relativePath): string
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            throw new RuntimeException("Invalid file path [{$relativePath}].");
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;
        if (!is_file($abs)) {
            throw new RuntimeException("Server file [{$relativePath}] not found on [{$serverUuid}].");
        }
        $contents = file_get_contents($abs);
        if ($contents === false) {
            throw new RuntimeException("Unable to read server file [{$relativePath}].");
        }

        return $contents;
    }

    public function fileMode(string $serverUuid, string $relativePath): ?int
    {
        $norm = $this->normalizeRelativePath($relativePath);
        if ($norm === null || $norm === '') {
            return null;
        }
        $abs = $this->serverRootPath($serverUuid).'/'.$norm;
        $stat = @lstat($abs);
        if ($stat === false) {
            return null;
        }

        return $stat['mode'] & 0777;
    }

    /** @return array<string, string> Map of relative path => contents for all regular files */
    public function listAllFiles(string $serverUuid): array
    {
        $root = $this->serverRootPath($serverUuid);
        $files = [];
        $this->collectFiles($root, '', $files);
        ksort($files);

        return $files;
    }

    /** @return list<string> All relative paths (files, dirs, symlinks) */
    public function listAllPaths(string $serverUuid): array
    {
        $root = $this->serverRootPath($serverUuid);
        $paths = [];
        $this->collectPaths($root, '', $paths);
        sort($paths);

        return $paths;
    }

    public function cleanup(): void
    {
        if (is_dir($this->baseDir)) {
            $this->removeTree($this->baseDir);
        }
    }

    /**
     * Returns the closure to mount first into Laravel's Http::fake().
     */
    public function httpHandler(): Closure
    {
        return function (Request $request): ?PromiseInterface {
            $psr = $request->toPsrRequest();
            $uri = $psr->getUri();

            if (strtolower($uri->getHost()) !== strtolower($this->host)) {
                return null;
            }

            if ($uri->getPort() !== null && $uri->getPort() !== $this->port) {
                return null;
            }

            return $this->handleRequest($request, $psr);
        };
    }

    private function handleRequest(Request $request, RequestInterface $psr): PromiseInterface
    {
        $method = strtoupper($psr->getMethod());
        $path = $psr->getUri()->getPath();
        parse_str($psr->getUri()->getQuery(), $query);

        $rawBody = (string) $psr->getBody();
        if ($psr->getBody()->isSeekable()) {
            $psr->getBody()->rewind();
        }
        $this->counters['totalHttpBytesPanelToWings'] += strlen($rawBody);

        if (!preg_match('#^/api/servers/([^/]+)(?:/(.*))?$#', $path, $matches)) {
            return $this->jsonResponse(404, ['error' => 'Not found.']);
        }

        $serverUuid = rawurldecode($matches[1]);
        $subPath = $matches[2] ?? '';

        $endpoint = $this->classifyEndpoint($method, $subPath, $psr);
        $this->counters['total']++;
        if (isset($this->counters[$endpoint])) {
            $this->counters[$endpoint]++;
        }

        // Validate Authorization Bearer token
        $authHeader = $psr->getHeaderLine('Authorization');
        if ($authHeader !== 'Bearer '.$this->daemonToken) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $serverUuid, $psr, $query, 403, json_encode(['error' => 'The authorization credentials provided for this request are invalid.'], JSON_THROW_ON_ERROR), true);
        }

        $queryRaw = $psr->getUri()->getQuery();
        $contextText = $queryRaw.' '.rawurldecode($queryRaw).' '.$rawBody;

        // Fire beforeEndpoint hooks (may inject faults or mutate disk before processing)
        foreach ([...($this->beforeHooks[$endpoint] ?? []), ...($this->beforeHooks['*'] ?? [])] as $hook) {
            $hookResult = $hook($request, $serverUuid, $endpoint, $this);
            if ($hookResult instanceof PromiseInterface) {
                return $hookResult;
            }
        }

        // Deterministic fault injection
        $fault = $this->consumeFault($endpoint, $contextText);
        if ($fault !== null) {
            $this->counters['faultsInjected']++;
            if ($fault['type'] === 'connect') {
                throw new ConnectException($fault['message'], $psr);
            }
            $faultBody = is_array($fault['body'])
                ? json_encode($fault['body'], JSON_THROW_ON_ERROR)
                : (string) $fault['body'];

            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $serverUuid, $psr, $query, (int) $fault['status'], $faultBody, is_array($fault['body']));
        }

        return match (true) {
            $method === 'GET' && $subPath === '' => $this->handleServerDetails($endpoint, $method, $serverUuid, $psr, $query),
            $method === 'POST' && $subPath === 'power' => $this->handlePower($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'sync' => $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $serverUuid, $psr, $query, 204, '', false),
            $method === 'POST' && $subPath === 'reinstall' => $this->handleReinstall($endpoint, $method, $serverUuid, $psr, $query),
            $method === 'GET' && $subPath === 'files/list-directory' => $this->handleListDirectory($endpoint, $method, $serverUuid, $psr, $query),
            $method === 'GET' && $subPath === 'files/contents' => $this->handleGetContents($endpoint, $method, $serverUuid, $psr, $query),
            $method === 'POST' && $subPath === 'files/write' => $this->handleWriteFile($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'PUT' && $subPath === 'files/rename' => $this->handleRename($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'files/delete' => $this->handleDelete($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'files/create-directory' => $this->handleCreateDirectory($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'files/decompress' => $this->handleDecompress($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'files/pull' => $this->handlePull($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'files/copy' => $this->handleCopy($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            $method === 'POST' && $subPath === 'files/chmod' => $this->handleChmod($endpoint, $method, $serverUuid, $psr, $query, $rawBody),
            default => $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $serverUuid, $psr, $query, 404, json_encode(['error' => 'Endpoint not found.'], JSON_THROW_ON_ERROR), true),
        };
    }

    private function classifyEndpoint(string $method, string $subPath, RequestInterface $psr): string
    {
        return match (true) {
            $method === 'GET' && $subPath === '' => 'serverDetails',
            $method === 'POST' && $subPath === 'power' => 'power',
            $method === 'POST' && $subPath === 'sync' => 'sync',
            $method === 'POST' && $subPath === 'reinstall' => 'reinstall',
            $method === 'GET' && $subPath === 'files/list-directory' => 'listDirectory',
            $method === 'GET' && $subPath === 'files/contents' => $psr->hasHeader('Range') ? 'readPrefix' : 'readStream',
            $method === 'POST' && $subPath === 'files/write' => 'putStream',
            $method === 'PUT' && $subPath === 'files/rename' => 'rename',
            $method === 'POST' && $subPath === 'files/delete' => 'delete',
            $method === 'POST' && $subPath === 'files/create-directory' => 'createDirectory',
            $method === 'POST' && $subPath === 'files/decompress' => 'decompress',
            $method === 'POST' && $subPath === 'files/pull' => 'pull',
            $method === 'POST' && $subPath === 'files/copy' => 'copy',
            $method === 'POST' && $subPath === 'files/chmod' => 'chmod',
            default => 'unknown',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function consumeFault(string $endpoint, string $contextText): ?array
    {
        foreach ($this->faults as $index => $fault) {
            if ($fault['remaining'] <= 0) {
                continue;
            }
            if ($fault['endpoint'] !== '*' && $fault['endpoint'] !== $endpoint) {
                continue;
            }
            if ($fault['path_contains'] !== null && !str_contains($contextText, $fault['path_contains'])) {
                continue;
            }

            $this->faults[$index]['remaining']--;

            return $fault;
        }

        return null;
    }

    /** @param array<string, mixed> $query */
    private function handleServerDetails(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query): PromiseInterface
    {
        $payload = [
            'state' => $this->serverState($uuid),
            'is_suspended' => false,
            'utilization' => [
                'memory_bytes' => 0,
                'memory_limit_bytes' => 0,
                'cpu_absolute' => 0.0,
                'disk_bytes' => 0,
                'network' => ['rx_bytes' => 0, 'tx_bytes' => 0],
                'uptime' => 0,
            ],
        ];

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 200, json_encode($payload, JSON_THROW_ON_ERROR), true);
    }

    /** @param array<string, mixed> $query */
    private function handlePower(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $action = is_array($decoded) ? (string) ($decoded['action'] ?? '') : '';
        if ($action === 'start' || $action === 'restart') {
            $this->serverStates[$uuid] = 'running';
        } elseif ($action === 'stop' || $action === 'kill') {
            $this->serverStates[$uuid] = 'offline';
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleReinstall(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query): PromiseInterface
    {
        if ($this->onReinstall !== null) {
            ($this->onReinstall)($uuid, $this);
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 202, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleListDirectory(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query): PromiseInterface
    {
        $dirParam = (string) ($query['directory'] ?? '/');
        $resolved = $this->resolveContainedPath($uuid, $dirParam, true);
        if ($resolved === null) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid directory path.'], JSON_THROW_ON_ERROR), true);
        }

        if (!file_exists($resolved) && !is_link($resolved)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 404, json_encode(['error' => 'The requested directory was not found on the server.'], JSON_THROW_ON_ERROR), true);
        }

        if (!is_dir($resolved)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'The requested resource is not a directory.'], JSON_THROW_ON_ERROR), true);
        }

        $entries = scandir($resolved);
        if ($entries === false) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 500, json_encode(['error' => 'Unable to read directory.'], JSON_THROW_ON_ERROR), true);
        }

        $stats = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $stats[] = $this->buildStat($resolved.'/'.$entry, $entry);
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 200, json_encode($stats, JSON_THROW_ON_ERROR), true);
    }

    /** @param array<string, mixed> $query */
    private function handleGetContents(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query): PromiseInterface
    {
        $fileParam = (string) ($query['file'] ?? '');
        $resolved = $this->resolveContainedPath($uuid, $fileParam, false);
        if ($resolved === null) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot open files of this type.'], JSON_THROW_ON_ERROR), true);
        }

        if (@lstat($resolved) === false) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 404, json_encode(['error' => 'The requested resources was not found on the system.'], JSON_THROW_ON_ERROR), true);
        }

        if (is_link($resolved)) {
            $realTarget = realpath($resolved);
            $realRoot = realpath($this->serverRootPath($uuid));
            if ($realTarget === false || $realRoot === false || !str_starts_with($realTarget, $realRoot.'/')) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot open files of this type.'], JSON_THROW_ON_ERROR), true);
            }
        }

        if (is_dir($resolved)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot perform that action: file is a directory.'], JSON_THROW_ON_ERROR), true);
        }

        $bytes = file_get_contents($resolved);
        if ($bytes === false) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 500, json_encode(['error' => 'Unable to read file.'], JSON_THROW_ON_ERROR), true);
        }

        $total = strlen($bytes);
        $extraHeaders = ['Content-Type' => 'application/octet-stream'];
        $status = 200;
        $body = $bytes;

        if ($this->rangeResponseEnabled && $psr->hasHeader('Range')) {
            $rangeHeader = trim($psr->getHeaderLine('Range'));
            $slice = $this->parseRangeHeader($rangeHeader, $total);
            if ($slice !== null) {
                [$start, $end] = $slice;
                $body = substr($bytes, $start, $end - $start + 1);
                $status = 206;
                $extraHeaders['Accept-Ranges'] = 'bytes';
                $extraHeaders['Content-Range'] = "bytes {$start}-{$end}/{$total}";
            }
        }

        $extraHeaders['Content-Length'] = (string) strlen($body);
        $this->counters['bytesWingsToPanel'] += strlen($body);

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, $status, $body, false, $extraHeaders);
    }

    /** @param array<string, mixed> $query */
    private function handleWriteFile(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        // Real Pelican Wings rejects chunked transfer encoding and missing/invalid Content-Length.
        if ($psr->hasHeader('Transfer-Encoding')) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Missing Content-Length'], JSON_THROW_ON_ERROR), true);
        }

        if ($psr->hasHeader('Content-Length')) {
            $clValues = $psr->getHeader('Content-Length');
            $cl = count($clValues) === 1 ? trim($clValues[0]) : '';
            if ($cl === '' || !ctype_digit($cl)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Missing Content-Length'], JSON_THROW_ON_ERROR), true);
            }
            if ((int) $cl !== strlen($rawBody)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Content-Length does not match body length.'], JSON_THROW_ON_ERROR), true);
            }
        } else {
            // Guzzle PrepareBodyMiddleware omits Content-Length on known 0-byte bodies.
            // Unknown-size streams (getSize() === null) MUST be rejected with 400.
            $streamSize = $psr->getBody()->getSize();
            if ($streamSize !== 0 || $rawBody !== '') {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Missing Content-Length'], JSON_THROW_ON_ERROR), true);
            }
        }

        $fileParam = (string) ($query['file'] ?? '');
        $resolved = $this->resolveContainedPath($uuid, $fileParam, false);
        $realRoot = realpath($this->serverRootPath($uuid));
        if ($resolved === null || $realRoot === false) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid file path.'], JSON_THROW_ON_ERROR), true);
        }

        if (is_dir($resolved)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot write file, name conflicts with an existing directory by the same name.'], JSON_THROW_ON_ERROR), true);
        }

        if ($this->hasNonDirectoryAncestor($realRoot, $resolved)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Parent path is not a directory.'], JSON_THROW_ON_ERROR), true);
        }

        $parent = dirname($resolved);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        file_put_contents($resolved, $rawBody);
        @chmod($resolved, 0644);
        $this->counters['bytesPanelToWings'] += strlen($rawBody);

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleRename(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $root = is_array($decoded) ? (string) ($decoded['root'] ?? '/') : '/';
        $files = is_array($decoded) && is_array($decoded['files'] ?? null) ? $decoded['files'] : [];

        if ($files === []) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 422, json_encode(['error' => 'The files field is required.'], JSON_THROW_ON_ERROR), true);
        }

        $this->counters['renameItems'] += count($files);

        foreach ($files as $item) {
            if (!is_array($item)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid rename item.'], JSON_THROW_ON_ERROR), true);
            }
            $fromRaw = (string) ($item['from'] ?? '');
            $toRaw = (string) ($item['to'] ?? '');
            if ($fromRaw === '' || $toRaw === '' || $fromRaw === '.' || $toRaw === '.') {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot rename root directory.'], JSON_THROW_ON_ERROR), true);
            }

            $fromCombined = $this->joinRootAndRelative($root, $fromRaw);
            $toCombined = $this->joinRootAndRelative($root, $toRaw);
            $fromAbs = $this->resolveContainedPath($uuid, $fromCombined, false, checkFinalSymlink: false);
            $toAbs = $this->resolveContainedPath($uuid, $toCombined, false, checkFinalSymlink: false);

            if ($fromAbs === null || $toAbs === null) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid rename path.'], JSON_THROW_ON_ERROR), true);
            }

            if ($fromAbs === $toAbs) {
                continue;
            }

            // Real Wings uses os.Lstat on destination and fails with 400 os.ErrExist
            // if destination exists (regular file, directory, OR dangling symlink).
            if (@lstat($toAbs) !== false) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot move or rename file, destination already exists.'], JSON_THROW_ON_ERROR), true);
            }

            // Real Wings silently ignores missing source (os.ErrNotExist).
            if (@lstat($fromAbs) === false) {
                continue;
            }

            $toParent = dirname($toAbs);
            if (!is_dir($toParent)) {
                mkdir($toParent, 0755, true);
            }

            if (!@rename($fromAbs, $toAbs)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 500, json_encode(['error' => 'Failed to rename file.'], JSON_THROW_ON_ERROR), true);
            }
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleDelete(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $root = is_array($decoded) ? (string) ($decoded['root'] ?? '/') : '/';
        $files = is_array($decoded) && is_array($decoded['files'] ?? null) ? $decoded['files'] : [];

        if ($files === []) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 422, json_encode(['error' => 'The files field is required.'], JSON_THROW_ON_ERROR), true);
        }

        $this->counters['deleteItems'] += count($files);

        foreach ($files as $filename) {
            if (!is_string($filename) || $filename === '' || $filename === '.' || $filename === '/') {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot delete server root.'], JSON_THROW_ON_ERROR), true);
            }
            $combined = $this->joinRootAndRelative($root, $filename);
            $abs = $this->resolveContainedPath($uuid, $combined, false, checkFinalSymlink: false);
            if ($abs === null) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid delete path.'], JSON_THROW_ON_ERROR), true);
            }
            if (@lstat($abs) === false) {
                continue;
            }
            if (is_link($abs) || is_file($abs)) {
                @unlink($abs);
            } elseif (is_dir($abs)) {
                $this->removeTree($abs);
            }
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleCreateDirectory(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $name = is_array($decoded) ? (string) ($decoded['name'] ?? '') : '';
        $path = is_array($decoded) ? (string) ($decoded['path'] ?? '/') : '/';

        if ($name === '') {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 422, json_encode(['error' => 'Directory name is required.'], JSON_THROW_ON_ERROR), true);
        }

        $combined = $this->joinRootAndRelative($path, $name);
        $abs = $this->resolveContainedPath($uuid, $combined, false);
        if ($abs === null) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid directory path.'], JSON_THROW_ON_ERROR), true);
        }

        if (is_file($abs) || is_link($abs)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot create directory, a file exists at that path.'], JSON_THROW_ON_ERROR), true);
        }

        $realRoot = realpath($this->serverRootPath($uuid));
        if ($realRoot === false || $this->hasNonDirectoryAncestor($realRoot, $abs)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Parent path is not a directory.'], JSON_THROW_ON_ERROR), true);
        }

        if (!is_dir($abs)) {
            mkdir($abs, 0755, true);
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleDecompress(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $root = is_array($decoded) ? (string) ($decoded['root'] ?? '/') : '/';
        $file = is_array($decoded) ? (string) ($decoded['file'] ?? '') : '';

        if ($file === '') {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 422, json_encode(['error' => 'Archive file is required.'], JSON_THROW_ON_ERROR), true);
        }

        $rootAbs = $this->resolveContainedPath($uuid, $root, true);
        $archiveRel = $this->joinRootAndRelative($root, $file);
        $archiveAbs = $this->resolveContainedPath($uuid, $archiveRel, false);
        if ($rootAbs === null || $archiveAbs === null) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid decompress path.'], JSON_THROW_ON_ERROR), true);
        }

        if (!is_file($archiveAbs)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 404, json_encode(['error' => 'Archive file not found.'], JSON_THROW_ON_ERROR), true);
        }

        $zip = new ZipArchive();
        if ($zip->open($archiveAbs, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive is corrupt or unsupported.'], JSON_THROW_ON_ERROR), true);
        }

        try {
            if ($this->zipHasRawNulEntryName($archiveAbs)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive contains invalid entry name.'], JSON_THROW_ON_ERROR), true);
            }

            $serverRoot = realpath($this->serverRootPath($uuid));
            if ($serverRoot === false) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 500, json_encode(['error' => 'Server root unavailable.'], JSON_THROW_ON_ERROR), true);
            }

            $rootPrefix = trim($this->normalizeRelativePath($root) ?? '', '/');
            $validated = [];

            // Phase 1: Strictly pre-validate every archive entry before modifying disk.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawName = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
                if (!is_string($rawName) || $rawName === '' || strlen($rawName) > 4096 || str_contains($rawName, "\0")) {
                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive contains invalid entry name.'], JSON_THROW_ON_ERROR), true);
                }

                // Reject absolute paths, drive letters, and ANY ".." traversal segment (including trailing "/.." or "\..").
                $rawSegments = explode('/', str_replace('\\', '/', $rawName));
                if (str_starts_with($rawName, '/') || str_starts_with($rawName, '\\')
                    || preg_match('/^[A-Za-z]:/', $rawName) === 1
                    || in_array('..', $rawSegments, true)) {
                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive entry attempts path traversal.'], JSON_THROW_ON_ERROR), true);
                }

                $isDir = str_ends_with($rawName, '/') || str_ends_with($rawName, '\\');
                $entryClean = str_replace('\\', '/', rtrim($rawName, '/\\'));
                $normEntry = $this->normalizeRelativePath($entryClean);
                if ($normEntry === null || ($normEntry === '' && !$isDir)) {
                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive entry escapes target directory.'], JSON_THROW_ON_ERROR), true);
                }

                $externalMode = 0644;
                $isSymlink = false;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $unixBits = ($attr >> 16) & 0177777;
                    if (($unixBits & 0170000) === 0120000) {
                        $isSymlink = true;
                    }
                    $permBits = $unixBits & 0777;
                    if ($permBits !== 0) {
                        $externalMode = ($permBits & 0111) !== 0 ? 0755 : 0644;
                    }
                }

                if ($isSymlink) {
                    $linkTarget = (string) $zip->getFromIndex($i);
                    if ($linkTarget === '' || str_starts_with($linkTarget, '/') || str_contains($linkTarget, '..')) {
                        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive symlink escapes server root.'], JSON_THROW_ON_ERROR), true);
                    }

                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive symbolic links are not permitted.'], JSON_THROW_ON_ERROR), true);
                }

                $fullRel = $rootPrefix === '' ? $normEntry : ($normEntry === '' ? $rootPrefix : $rootPrefix.'/'.$normEntry);
                $entryAbs = $this->resolveContainedPath($uuid, $fullRel, $isDir);
                if ($entryAbs === null
                    || $this->hasNonDirectoryAncestor($serverRoot, $entryAbs)
                    || (!$isDir && is_dir($entryAbs))
                    || ($isDir && @lstat($entryAbs) !== false && !is_dir($entryAbs))) {
                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive entry path conflicts with sandbox or existing filesystem entry.'], JSON_THROW_ON_ERROR), true);
                }

                if (!$isDir && !$this->verifyZipStreamReadable($zip, $i)) {
                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Archive entry payload is corrupt or failed integrity check.'], JSON_THROW_ON_ERROR), true);
                }

                $validated[] = [
                    'index' => $i,
                    'is_dir' => $isDir,
                    'rel' => $fullRel,
                    'abs' => $entryAbs,
                    'mode' => $isDir ? 0755 : $externalMode,
                ];
            }

            // Phase 2: Extract validated entries into target root; preserve archive on disk.
            if (!is_dir($rootAbs)) {
                mkdir($rootAbs, 0755, true);
            }

            foreach ($validated as $item) {
                if ($item['is_dir']) {
                    if ($item['rel'] === '') {
                        continue;
                    }
                    if (!is_dir($item['abs'])) {
                        mkdir($item['abs'], 0755, true);
                    }
                    @chmod($item['abs'], 0755);

                    continue;
                }

                $parent = dirname($item['abs']);
                if (!is_dir($parent)) {
                    mkdir($parent, 0755, true);
                }

                $stream = $zip->getStreamIndex($item['index']);
                if ($stream === false) {
                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 500, json_encode(['error' => 'Unable to extract archive entry.'], JSON_THROW_ON_ERROR), true);
                }

                $out = fopen($item['abs'], 'wb');
                if ($out === false) {
                    fclose($stream);

                    return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 500, json_encode(['error' => 'Unable to write extracted entry.'], JSON_THROW_ON_ERROR), true);
                }
                try {
                    stream_copy_to_stream($stream, $out);
                } finally {
                    fclose($stream);
                    fclose($out);
                }

                @chmod($item['abs'], $item['mode']);
                $this->extractedModes[$uuid][$item['rel']] = $item['mode'];
            }
        } finally {
            $zip->close();
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handlePull(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        if ($this->disableRemoteDownload) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'This functionality is not currently enabled on this instance.'], JSON_THROW_ON_ERROR), true);
        }

        $active = $this->activePulls[$uuid] ?? 0;
        if ($active >= self::MAX_CONCURRENT_PULLS) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'This server has reached its limit of 3 simultaneous remote file downloads at once. Please wait for one to complete before trying again.'], JSON_THROW_ON_ERROR), true);
        }

        $decoded = json_decode($rawBody, true);
        $url = is_array($decoded) ? (string) ($decoded['url'] ?? '') : '';
        $root = is_array($decoded) ? (string) ($decoded['root'] ?? '/') : '/';
        $fileName = is_array($decoded) && is_string($decoded['file_name'] ?? null) ? $decoded['file_name'] : '';
        $foreground = is_array($decoded) && ($decoded['foreground'] ?? false) === true;

        if (!$this->isAllowedPullUrl($url)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Remote download URL is not permitted.'], JSON_THROW_ON_ERROR), true);
        }

        if (!$foreground || $this->forceAsyncPull) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 202, json_encode(['identifier' => 'pull-'.bin2hex(random_bytes(6))], JSON_THROW_ON_ERROR), true);
        }

        $this->activePulls[$uuid] = $active + 1;
        try {
            $bytes = $this->resolvePullBytes($url);
            if ($bytes === null) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Failed to download remote file.'], JSON_THROW_ON_ERROR), true);
            }

            if ($fileName === '') {
                $urlPath = (string) parse_url($url, PHP_URL_PATH);
                $fileName = basename($urlPath);
            }
            if ($fileName === '' || $fileName === '.' || $fileName === '..') {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Unable to determine pull destination filename.'], JSON_THROW_ON_ERROR), true);
            }

            $combined = $this->joinRootAndRelative($root, $fileName);
            $abs = $this->resolveContainedPath($uuid, $combined, false);
            $realRoot = realpath($this->serverRootPath($uuid));
            if ($abs === null || $realRoot === false) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Invalid pull destination path.'], JSON_THROW_ON_ERROR), true);
            }

            if (is_dir($abs)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Cannot write file, name conflicts with an existing directory by the same name.'], JSON_THROW_ON_ERROR), true);
            }

            if ($this->hasNonDirectoryAncestor($realRoot, $abs)) {
                return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 400, json_encode(['error' => 'Parent path is not a directory.'], JSON_THROW_ON_ERROR), true);
            }

            $parent = dirname($abs);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            file_put_contents($abs, $bytes);
            @chmod($abs, 0644);

            $stat = $this->buildStat($abs, basename($abs));

            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 200, json_encode($stat, JSON_THROW_ON_ERROR), true);
        } finally {
            $this->activePulls[$uuid] = max(0, ($this->activePulls[$uuid] ?? 1) - 1);
        }
    }

    /** @param array<string, mixed> $query */
    private function handleCopy(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $location = is_array($decoded) ? (string) ($decoded['location'] ?? '') : '';
        $abs = $this->resolveContainedPath($uuid, $location, false);
        if ($abs === null || !is_file($abs)) {
            return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 404, json_encode(['error' => 'File not found.'], JSON_THROW_ON_ERROR), true);
        }

        copy($abs, $abs.' copy');

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    /** @param array<string, mixed> $query */
    private function handleChmod(string $endpoint, string $method, string $uuid, RequestInterface $psr, array $query, string $rawBody): PromiseInterface
    {
        $decoded = json_decode($rawBody, true);
        $root = is_array($decoded) ? (string) ($decoded['root'] ?? '/') : '/';
        $files = is_array($decoded) && is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        foreach ($files as $item) {
            if (!is_array($item)) {
                continue;
            }
            $file = (string) ($item['file'] ?? '');
            $mode = octdec((string) ($item['mode'] ?? '644'));
            $abs = $this->resolveContainedPath($uuid, $this->joinRootAndRelative($root, $file), false);
            if ($abs !== null && file_exists($abs)) {
                @chmod($abs, $mode);
            }
        }

        return $this->recordAndReturn($endpoint, $method, (string) $psr->getUri(), $uuid, $psr, $query, 204, '', false);
    }

    private function resolvePullBytes(string $url): ?string
    {
        if (array_key_exists($url, $this->pullFixtures)) {
            $entry = $this->pullFixtures[$url];

            return $entry instanceof Closure ? $entry($url) : $entry;
        }

        if ($this->pullFallback !== null) {
            return ($this->pullFallback)($url);
        }

        return null;
    }

    private function isAllowedPullUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $rawHost = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        // Strip IPv6 zone identifier (e.g., fe80::1%eth0 or fe80::1%25eth0).
        $hostWithoutZone = (string) preg_replace('/(?:%25|%).*$/', '', $rawHost);
        $host = rtrim($hostWithoutZone, '.');

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // Canonical IPv4 or IPv6 literal — enforce exact Wings internal/network/address.go rules.
        $packed = @inet_pton($host);
        if ($packed !== false) {
            return !$this->isInternalPackedIp($packed);
        }

        // Non-canonical numeric IPv4 literals (127.1, 127.0.1, 2130706433, 0x7f000001, 0177.0.0.1).
        // PHP 8.4 ip2long() returns false on these, so parse explicitly; reject all non-canonical forms
        // to match Go netip.ParseAddr / net.DefaultResolver strictness in Wings.
        if ($this->parseNonCanonicalIpv4($host) !== null) {
            return false;
        }

        return true;
    }

    private function parseNonCanonicalIpv4(string $host): ?string
    {
        if ($host === '') {
            return null;
        }

        $parts = explode('.', $host);
        $count = count($parts);
        if ($count < 1 || $count > 4) {
            return null;
        }

        $nums = [];
        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }
            if (preg_match('/^0x([0-9a-f]+)$/i', $part, $m) === 1) {
                if (strlen($m[1]) > 8) {
                    return '0.0.0.0';
                }
                $val = hexdec($m[1]);
                if (!is_int($val)) {
                    return '0.0.0.0';
                }
                $nums[] = $val;
            } elseif (preg_match('/^0([0-7]+)$/', $part, $m) === 1) {
                $val = octdec($m[1]);
                if (!is_int($val) || $val > 0xFFFFFFFF) {
                    return '0.0.0.0';
                }
                $nums[] = $val;
            } elseif (preg_match('/^0[0-9]+$/', $part) === 1) {
                return '0.0.0.0';
            } elseif (preg_match('/^(?:0|[1-9][0-9]*)$/', $part) === 1) {
                if (strlen($part) > 10 || (int) $part > 0xFFFFFFFF) {
                    return '0.0.0.0';
                }
                $nums[] = (int) $part;
            } else {
                return null;
            }
        }

        $ipInt = match ($count) {
            1 => $nums[0],
            2 => ($nums[0] <= 0xFF && $nums[1] <= 0xFFFFFF) ? (($nums[0] << 24) | $nums[1]) : null,
            3 => ($nums[0] <= 0xFF && $nums[1] <= 0xFF && $nums[2] <= 0xFFFF) ? (($nums[0] << 24) | ($nums[1] << 16) | $nums[2]) : null,
            4 => ($nums[0] <= 0xFF && $nums[1] <= 0xFF && $nums[2] <= 0xFF && $nums[3] <= 0xFF) ? (($nums[0] << 24) | ($nums[1] << 16) | ($nums[2] << 8) | $nums[3]) : null,
        };

        if ($ipInt === null || $ipInt > 0xFFFFFFFF) {
            return '0.0.0.0';
        }

        return sprintf('%d.%d.%d.%d', ($ipInt >> 24) & 0xFF, ($ipInt >> 16) & 0xFF, ($ipInt >> 8) & 0xFF, $ipInt & 0xFF);
    }

    private function isInternalPackedIp(string $packed): bool
    {
        if (strlen($packed) === 16) {
            // Unmap IPv4-mapped IPv6 (::ffff:0:0/96)
            if (str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
                return $this->isInternalPackedIp(substr($packed, 12, 4));
            }
            // Unwrap well-known NAT64 prefix (64:ff9b::/96)
            if (str_starts_with($packed, "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00")) {
                return $this->isInternalPackedIp(substr($packed, 12, 4));
            }

            foreach ([
                '::/128',
                '::1/128',
                '::/96',
                '64:ff9b:1::/48',
                '100::/64',
                '2001::/23',
                '2001:db8::/32',
                '2002::/16',
                '3fff::/20',
                '5f00::/16',
                'fc00::/7',
                'fe80::/10',
                'fec0::/10',
                'ff00::/8',
            ] as $cidr) {
                if ($this->packedIpInCidr($packed, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if (strlen($packed) === 4) {
            foreach ([
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.0.2.0/24',
                '192.88.99.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '198.51.100.0/24',
                '203.0.113.0/24',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ] as $cidr) {
                if ($this->packedIpInCidr($packed, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function packedIpInCidr(string $packedIp, string $cidr): bool
    {
        [$subnet, $prefixRaw] = explode('/', $cidr, 2);
        $packedSubnet = @inet_pton($subnet);
        if ($packedSubnet === false || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $fullBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;

        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedSubnet, 0, $fullBytes)) {
            return false;
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($packedIp[$fullBytes]) & $mask) !== (ord($packedSubnet[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $extraHeaders
     */
    private function recordAndReturn(
        string $endpoint,
        string $method,
        string $url,
        string $serverUuid,
        RequestInterface $psr,
        array $query,
        int $status,
        string $body,
        bool $isJson,
        array $extraHeaders = [],
    ): PromiseInterface {
        $this->recorded[] = [
            'endpoint' => $endpoint,
            'method' => $method,
            'url' => $url,
            'server_uuid' => $serverUuid,
            'headers' => $psr->getHeaders(),
            'query' => $query,
            'status' => $status,
        ];

        $this->counters['totalHttpBytesWingsToPanel'] += strlen($body);

        $headers = array_merge(
            [
                'User-Agent' => $this->userAgentHeader(),
            ],
            $isJson ? ['Content-Type' => 'application/json'] : [],
            $extraHeaders,
        );

        return Http::response($body, $status, $headers);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(int $status, array $payload): PromiseInterface
    {
        return Http::response(json_encode($payload, JSON_THROW_ON_ERROR), $status, [
            'User-Agent' => $this->userAgentHeader(),
            'Content-Type' => 'application/json',
        ]);
    }

    /** @return array<string, mixed> */
    private function buildStat(string $absPath, string $name): array
    {
        $lstat = @lstat($absPath);
        $isLink = is_link($absPath);
        $isDir = !$isLink && is_dir($absPath);
        $isFile = !$isLink && is_file($absPath);
        $size = $isFile && is_array($lstat) ? (int) $lstat['size'] : 0;
        $mtime = is_array($lstat) ? (int) $lstat['mtime'] : time();
        $ctime = is_array($lstat) ? (int) $lstat['ctime'] : $mtime;
        $modeBits = is_array($lstat) ? sprintf('%04o', $lstat['mode'] & 0777) : ($isDir ? '0755' : '0644');

        return [
            'name' => $name,
            'created' => gmdate('c', $ctime),
            'modified' => gmdate('c', $mtime),
            'mode' => ($isDir ? 'd' : ($isLink ? 'L' : '-')).'rwxr-xr-x',
            'mode_bits' => $modeBits,
            'size' => $size,
            'directory' => $isDir,
            'file' => $isFile,
            'symlink' => $isLink,
            'mime' => $isDir ? 'inode/directory' : 'application/octet-stream',
        ];
    }

    private function joinRootAndRelative(string $root, string $relative): string
    {
        $rootClean = trim(str_replace('\\', '/', $root), '/');
        $relClean = ltrim(str_replace('\\', '/', $relative), '/');

        if ($rootClean === '' || $rootClean === '.') {
            return $relClean;
        }
        if ($relClean === '' || $relClean === '.') {
            return $rootClean;
        }

        return $rootClean.'/'.$relClean;
    }

    private function resolveContainedPath(string $uuid, string $path, bool $allowRoot, bool $checkFinalSymlink = true): ?string
    {
        $norm = $this->normalizeRelativePath($path);
        if ($norm === null) {
            return null;
        }

        $root = $this->serverRootPath($uuid);
        if ($norm === '') {
            return $allowRoot ? $root : null;
        }

        $candidate = $root.'/'.$norm;
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        // Verify no existing ancestor (and, by default, the final target itself)
        // is a symlink escaping the server root or dangling.
        $segments = explode('/', $norm);
        $current = $realRoot;
        $count = count($segments);
        foreach ($segments as $index => $segment) {
            $next = $current.'/'.$segment;
            $isLast = $index === $count - 1;
            if ((!$isLast || $checkFinalSymlink) && is_link($next)) {
                $resolvedLink = realpath($next);
                if ($resolvedLink === false || ($resolvedLink !== $realRoot && !str_starts_with($resolvedLink, $realRoot.'/'))) {
                    return null;
                }
            }
            $current = $next;
        }

        return $candidate;
    }

    private function hasNonDirectoryAncestor(string $realRoot, string $absPath): bool
    {
        $parent = dirname($absPath);
        while ($parent !== $realRoot && str_starts_with($parent, $realRoot.'/')) {
            if (@lstat($parent) !== false && !is_dir($parent)) {
                return true;
            }
            $nextParent = dirname($parent);
            if ($nextParent === $parent) {
                break;
            }
            $parent = $nextParent;
        }

        return false;
    }

    /**
     * Scans raw ZIP Central Directory and Local File Header filenames on disk
     * to detect binary NUL (\x00) bytes that libzip rewrites to 0x20 even under FL_ENC_RAW.
     */
    private function zipHasRawNulEntryName(string $archiveAbs): bool
    {
        $size = @filesize($archiveAbs);
        if (!is_int($size) || $size < 22) {
            return true;
        }

        $fp = @fopen($archiveAbs, 'rb');
        if ($fp === false) {
            return true;
        }

        try {
            $tailLen = min($size, 65535 + 22);
            if (fseek($fp, $size - $tailLen) !== 0) {
                return true;
            }
            $tail = fread($fp, $tailLen);
            if (!is_string($tail) || strlen($tail) !== $tailLen) {
                return true;
            }

            // Locate valid End of Central Directory (EOCD) record from end of file.
            $eocdPos = null;
            $searchOffset = $tailLen - 22;
            while ($searchOffset >= 0) {
                $candidate = strrpos(substr($tail, 0, $searchOffset + 4), "PK\x05\x06");
                if ($candidate === false) {
                    break;
                }
                if ($candidate + 22 <= $tailLen) {
                    $unpackedComment = unpack('vlen', substr($tail, $candidate + 20, 2));
                    if (is_array($unpackedComment) && ($candidate + 22 + (int) $unpackedComment['len']) === $tailLen) {
                        $eocdPos = $candidate;
                        break;
                    }
                }
                $searchOffset = $candidate - 1;
            }

            if ($eocdPos === null) {
                return true;
            }

            $eocd = unpack('ventries/VcdSize/VcdOffset', substr($tail, $eocdPos + 10, 10));
            if (!is_array($eocd)) {
                return true;
            }

            $cdSize = (int) $eocd['cdSize'];
            $cdOffset = (int) $eocd['cdOffset'];

            // Support ZIP64 EOCD when 32-bit fields are saturated.
            if ($cdSize === 0xFFFFFFFF || $cdOffset === 0xFFFFFFFF || (int) $eocd['entries'] === 0xFFFF) {
                $eocdFileOffset = ($size - $tailLen) + $eocdPos;
                if ($eocdFileOffset >= 20 && fseek($fp, $eocdFileOffset - 20) === 0) {
                    $locator = fread($fp, 20);
                    if (is_string($locator) && strlen($locator) === 20 && str_starts_with($locator, "PK\x06\x07")) {
                        $loc = unpack('Pzip64Off', substr($locator, 8, 8));
                        if (is_array($loc) && fseek($fp, (int) $loc['zip64Off']) === 0) {
                            $z64 = fread($fp, 56);
                            if (is_string($z64) && strlen($z64) === 56 && str_starts_with($z64, "PK\x06\x06")) {
                                $z64Unpack = unpack('PcdSize/PcdOffset', substr($z64, 40, 16));
                                if (is_array($z64Unpack)) {
                                    $cdSize = (int) $z64Unpack['cdSize'];
                                    $cdOffset = (int) $z64Unpack['cdOffset'];
                                }
                            }
                        }
                    }
                }
            }

            if ($cdSize === 0) {
                return false;
            }
            if ($cdOffset < 0 || $cdSize < 0 || ($cdOffset + $cdSize) > $size) {
                return true;
            }

            if (fseek($fp, $cdOffset) !== 0) {
                return true;
            }
            $cdBytes = fread($fp, $cdSize);
            if (!is_string($cdBytes) || strlen($cdBytes) !== $cdSize) {
                return true;
            }

            $pos = 0;
            while ($pos + 46 <= $cdSize) {
                if (substr($cdBytes, $pos, 4) !== "PK\x01\x02") {
                    return true;
                }
                $hdr = unpack('vnameLen/vextraLen/vcommentLen', substr($cdBytes, $pos + 28, 6));
                $locHdr = unpack('VlocalOff', substr($cdBytes, $pos + 42, 4));
                if (!is_array($hdr) || !is_array($locHdr)) {
                    return true;
                }

                $nameLen = (int) $hdr['nameLen'];
                $extraLen = (int) $hdr['extraLen'];
                $commentLen = (int) $hdr['commentLen'];
                if ($pos + 46 + $nameLen + $extraLen + $commentLen > $cdSize) {
                    return true;
                }

                $rawCdName = substr($cdBytes, $pos + 46, $nameLen);
                if (str_contains($rawCdName, "\0")) {
                    return true;
                }

                // Also inspect corresponding Local File Header filename for NUL bytes.
                $localOff = (int) $locHdr['localOff'];
                if ($localOff < 0xFFFFFFFF && $localOff + 30 <= $size && fseek($fp, $localOff) === 0) {
                    $lh = fread($fp, 30);
                    if (is_string($lh) && strlen($lh) === 30 && str_starts_with($lh, "PK\x03\x04")) {
                        $lhLen = unpack('vnameLen', substr($lh, 26, 2));
                        if (is_array($lhLen) && (int) $lhLen['nameLen'] > 0) {
                            $localName = fread($fp, (int) $lhLen['nameLen']);
                            if (!is_string($localName) || str_contains($localName, "\0")) {
                                return true;
                            }
                        }
                    }
                }

                $pos += 46 + $nameLen + $extraLen + $commentLen;
            }

            return false;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Streams entry $index through a null sink with warning capture, exact byte
     * count verification, and streaming CRC32 verification before Phase 2 writes.
     */
    private function verifyZipStreamReadable(ZipArchive $zip, int $index): bool
    {
        $stat = $zip->statIndex($index);
        if (!is_array($stat)) {
            return false;
        }

        $expectedSize = (int) $stat['size'];
        $expectedCrc = sprintf('%08x', ((int) $stat['crc']) & 0xFFFFFFFF);
        $hadWarning = false;

        set_error_handler(static function () use (&$hadWarning): bool {
            $hadWarning = true;

            return true;
        });

        try {
            $stream = $zip->getStreamIndex($index);
            if ($stream === false) {
                return false;
            }

            $hashCtx = hash_init('crc32b');
            $totalRead = 0;

            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        return false;
                    }
                    $totalRead += strlen($chunk);
                    hash_update($hashCtx, $chunk);
                }
            } finally {
                fclose($stream);
            }

            if ($hadWarning || $totalRead !== $expectedSize) {
                return false;
            }

            return hash_final($hashCtx) === $expectedCrc;
        } finally {
            restore_error_handler();
        }
    }

    private function normalizeRelativePath(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        $trimmed = trim($path, '/');
        if ($trimmed === '' || $trimmed === '.') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** @return array{0: int, 1: int}|null */
    private function parseRangeHeader(string $header, int $total): ?array
    {
        if ($total <= 0) {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1) {
            return null;
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($startRaw === '') {
            $suffix = (int) $endRaw;
            if ($suffix <= 0) {
                return null;
            }
            $start = max(0, $total - $suffix);
            $end = $total - 1;

            return [$start, $end];
        }

        $start = (int) $startRaw;
        $end = $endRaw === '' ? $total - 1 : min((int) $endRaw, $total - 1);
        if ($start > $end || $start >= $total) {
            return null;
        }

        return [$start, $end];
    }

    /** @param array<string, string> $out */
    private function collectFiles(string $dir, string $prefix, array &$out): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $abs = $dir.'/'.$entry;
            $rel = $prefix === '' ? $entry : $prefix.'/'.$entry;
            if (is_link($abs)) {
                continue;
            }
            if (is_dir($abs)) {
                $this->collectFiles($abs, $rel, $out);
            } elseif (is_file($abs)) {
                $contents = file_get_contents($abs);
                $out[$rel] = $contents === false ? '' : $contents;
            }
        }
    }

    /** @param list<string> $out */
    private function collectPaths(string $dir, string $prefix, array &$out): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $abs = $dir.'/'.$entry;
            $rel = $prefix === '' ? $entry : $prefix.'/'.$entry;
            $out[] = $rel;
            if (!is_link($abs) && is_dir($abs)) {
                $this->collectPaths($abs, $rel, $out);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
