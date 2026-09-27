<?php

namespace Kazaminosuke\ModManager\Contracts;

/**
 * Persistent store for Mod Manager background work.
 *
 * HTTP and other Panel requests enqueue JSON payloads here. Pelican's
 * short-lived `schedule:run` process drains them without spawning a
 * subprocess or serializing Plugin classes onto Laravel's queue worker.
 */
interface BackgroundJobQueue
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(string $type, array $payload): bool;

    /**
     * Claim the next available job, if any.
     *
     * @return array{id: int|string, type: string, payload: array<string, mixed>, attempts: int}|null
     */
    public function claim(): ?array;

    public function ack(int|string $id): void;

    public function retry(int|string $id, int $delaySeconds = 30): void;

    /**
     * Return a claimed job that asked to wait (for example, for a busy
     * operation lease) without counting that claim as a failed attempt.
     */
    public function release(int|string $id, int $delaySeconds): void;
}
