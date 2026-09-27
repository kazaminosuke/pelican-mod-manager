<?php

namespace Kazaminosuke\ModManager\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Kazaminosuke\ModManager\Enums\ProjectType;

final class InstalledOperationState
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    private const SCHEMA_VERSION = 1;

    private const MAX_RESULT_DEPTH = 4;

    private const MAX_RESULT_ITEMS = 100;

    private const MAX_RESULT_STRING_LENGTH = 512;

    /** @var array<int, string> */
    private const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * @param array<string, mixed> $result
     */
    private function __construct(
        public readonly string $status,
        public readonly string $operation,
        public readonly int $serverId,
        public readonly ProjectType $projectType,
        public readonly int $progress,
        public readonly ?int $total,
        public readonly array $result,
        public readonly ?string $error,
        public readonly string $queuedAt,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid installed operation status [{$status}].");
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $operation)) {
            throw new InvalidArgumentException("Invalid installed operation name [{$operation}].");
        }

        if ($serverId < 1) {
            throw new InvalidArgumentException('The installed operation server ID must be positive.');
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function queued(
        string $operation,
        int $serverId,
        ProjectType $projectType,
        array $result = [],
        ?DateTimeImmutable $now = null,
    ): self {
        return new self(
            status: self::STATUS_QUEUED,
            operation: $operation,
            serverId: $serverId,
            projectType: $projectType,
            progress: 0,
            total: null,
            result: self::sanitizeResult($result),
            error: null,
            queuedAt: self::timestamp($now),
            startedAt: null,
            finishedAt: null,
        );
    }

    public function running(?int $total = null, ?DateTimeImmutable $now = null): self
    {
        return new self(
            status: self::STATUS_RUNNING,
            operation: $this->operation,
            serverId: $this->serverId,
            projectType: $this->projectType,
            progress: min($this->progress, max(0, $total ?? $this->progress)),
            total: $total === null ? $this->total : max(0, $total),
            result: $this->result,
            error: null,
            queuedAt: $this->queuedAt,
            startedAt: $this->startedAt ?? self::timestamp($now),
            finishedAt: null,
        );
    }

    public function withProgress(int $progress, ?int $total = null): self
    {
        $resolvedTotal = $total === null ? $this->total : max(0, $total);
        $resolvedProgress = max(0, $progress);

        if ($resolvedTotal !== null) {
            $resolvedProgress = min($resolvedProgress, $resolvedTotal);
        }

        return new self(
            status: self::STATUS_RUNNING,
            operation: $this->operation,
            serverId: $this->serverId,
            projectType: $this->projectType,
            progress: $resolvedProgress,
            total: $resolvedTotal,
            result: $this->result,
            error: null,
            queuedAt: $this->queuedAt,
            startedAt: $this->startedAt ?? self::timestamp(),
            finishedAt: null,
        );
    }

    /**
     * Return an in-flight operation to the queue without exposing daemon or
     * exception details in the browser-visible state cache.
     *
     * @param array<string, mixed> $result
     */
    public function requeue(array $result = []): self
    {
        return new self(
            status: self::STATUS_QUEUED,
            operation: $this->operation,
            serverId: $this->serverId,
            projectType: $this->projectType,
            progress: $this->progress,
            total: $this->total,
            result: self::sanitizeResult($result),
            error: null,
            queuedAt: $this->queuedAt,
            startedAt: null,
            finishedAt: null,
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    public function completed(array $result = [], ?DateTimeImmutable $now = null): self
    {
        return new self(
            status: self::STATUS_COMPLETED,
            operation: $this->operation,
            serverId: $this->serverId,
            projectType: $this->projectType,
            progress: $this->total ?? $this->progress,
            total: $this->total,
            result: self::sanitizeResult($result),
            error: null,
            queuedAt: $this->queuedAt,
            startedAt: $this->startedAt ?? self::timestamp($now),
            finishedAt: self::timestamp($now),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    public function failed(string $error, array $result = [], ?DateTimeImmutable $now = null): self
    {
        return new self(
            status: self::STATUS_FAILED,
            operation: $this->operation,
            serverId: $this->serverId,
            projectType: $this->projectType,
            progress: $this->progress,
            total: $this->total,
            result: self::sanitizeResult($result),
            error: self::sanitizeError($error),
            queuedAt: $this->queuedAt,
            startedAt: $this->startedAt,
            finishedAt: self::timestamp($now),
        );
    }

    /**
     * Replace the result while keeping the lifecycle status and timestamps.
     *
     * @param array<string, mixed> $result
     */
    public function withResult(array $result): self
    {
        return new self(
            status: $this->status,
            operation: $this->operation,
            serverId: $this->serverId,
            projectType: $this->projectType,
            progress: $this->progress,
            total: $this->total,
            result: self::sanitizeResult($result),
            error: $this->error,
            queuedAt: $this->queuedAt,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
        );
    }

    /**
     * A background revalidation refreshes data the page already shows. It is
     * not user-requested work, so its lifecycle stays out of the UI.
     */
    public function isBackground(): bool
    {
        return ($this->result['background'] ?? false) === true;
    }

    /** Seconds since this operation was first queued. */
    public function secondsSinceQueued(?DateTimeImmutable $now = null): int
    {
        $queuedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $this->queuedAt);

        if ($queuedAt === false) {
            return 0;
        }

        return max(0, ($now ?? new DateTimeImmutable())->getTimestamp() - $queuedAt->getTimestamp());
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /** @return array<string, mixed> */
    public function toCachePayload(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status,
            'operation' => $this->operation,
            'server_id' => $this->serverId,
            'project_type' => $this->projectType->value,
            'progress' => $this->progress,
            'total' => $this->total,
            'result' => $this->result,
            'error' => $this->error,
            'queued_at' => $this->queuedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    public static function fromCachePayload(mixed $payload): ?self
    {
        if (!is_array($payload)
            || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($payload['status'] ?? null)
            || !is_string($payload['operation'] ?? null)
            || !is_int($payload['server_id'] ?? null)
            || !is_string($payload['project_type'] ?? null)
            || !is_int($payload['progress'] ?? null)
            || !(is_int($payload['total'] ?? null) || ($payload['total'] ?? null) === null)
            || !is_array($payload['result'] ?? null)
            || !(is_string($payload['error'] ?? null) || ($payload['error'] ?? null) === null)
            || !is_string($payload['queued_at'] ?? null)
            || !(is_string($payload['started_at'] ?? null) || ($payload['started_at'] ?? null) === null)
            || !(is_string($payload['finished_at'] ?? null) || ($payload['finished_at'] ?? null) === null)) {
            return null;
        }

        $projectType = ProjectType::tryFrom($payload['project_type']);

        if (!$projectType || !in_array($payload['status'], self::STATUSES, true)) {
            return null;
        }

        try {
            return new self(
                status: $payload['status'],
                operation: $payload['operation'],
                serverId: $payload['server_id'],
                projectType: $projectType,
                progress: max(0, $payload['progress']),
                total: $payload['total'] === null ? null : max(0, $payload['total']),
                result: self::sanitizeResult($payload['result']),
                error: $payload['error'] === null ? null : self::sanitizeError($payload['error']),
                queuedAt: $payload['queued_at'],
                startedAt: $payload['started_at'],
                finishedAt: $payload['finished_at'],
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function timestamp(?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable())->format(DATE_ATOM);
    }

    private static function sanitizeError(string $error): string
    {
        $sanitized = preg_replace('/[^a-z0-9_.:-]+/i', '_', $error) ?? 'operation_failed';

        return substr(trim($sanitized, '_'), 0, 128) ?: 'operation_failed';
    }

    /**
     * @param array<mixed> $result
     * @return array<string, mixed>
     */
    private static function sanitizeResult(array $result, int $depth = 0): array
    {
        if ($depth >= self::MAX_RESULT_DEPTH) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice($result, 0, self::MAX_RESULT_ITEMS, true) as $key => $value) {
            $key = (string) $key;

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeResult($value, $depth + 1);
            } elseif (is_string($value)) {
                $sanitized[$key] = substr($value, 0, self::MAX_RESULT_STRING_LENGTH);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
