<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Console\Commands;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Console\OutputStyle;
use Kazaminosuke\ModManager\Console\Commands\ProcessBackgroundJobsCommand;
use Kazaminosuke\ModManager\Contracts\BackgroundJobQueue;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ProcessBackgroundJobsCommandTest extends TestCase
{
    public function test_empty_queue_succeeds_without_work(): void
    {
        $command = $this->command();
        $queue = new RecordingBackgroundJobQueue();

        self::assertSame(0, $command->handle($queue, PluginBackgroundRunner::fake()));
        self::assertSame([], $queue->claimed);
        self::assertSame([], $queue->acked);
    }

    public function test_unknown_job_is_retried_and_keeps_the_unique_lock(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake($cache);
        $uniqueKey = $runner->uniqueCacheKey('warm-1');
        $cache->add($uniqueKey, 1, 60);
        $queue = new RecordingBackgroundJobQueue([
            [
                'id' => 7,
                'type' => 'not_a_job',
                'payload' => ['_unique_key' => $uniqueKey],
                'attempts' => 1,
            ],
        ]);
        $command = $this->command();

        self::assertSame(0, $command->handle($queue, $runner));
        self::assertSame([7], $queue->retried);
        self::assertSame([['id' => 7, 'delay' => 30, 'expectedAttempts' => 1]], $queue->retriedDetails);
        self::assertSame([], $queue->acked);
        self::assertTrue($cache->has($uniqueKey));
    }

    public function test_poison_job_is_acked_and_releases_the_unique_lock(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake($cache);
        $uniqueKey = $runner->uniqueCacheKey('warm-2');
        $cache->add($uniqueKey, 1, 60);
        $queue = new RecordingBackgroundJobQueue([
            [
                'id' => 8,
                'type' => 'not_a_job',
                'payload' => ['_unique_key' => $uniqueKey],
                'attempts' => ProcessBackgroundJobsCommand::MAX_ATTEMPTS,
            ],
        ]);
        $command = $this->command();

        self::assertSame(0, $command->handle($queue, $runner));
        self::assertSame([8], $queue->acked);
        self::assertSame([['id' => 8, 'expectedAttempts' => ProcessBackgroundJobsCommand::MAX_ATTEMPTS]], $queue->ackedDetails);
        self::assertSame([], $queue->retried);
        self::assertFalse($cache->has($uniqueKey));
    }

    public function test_superseded_ack_keeps_the_unique_lock(): void
    {
        $cache = new LaravelCacheRepository(new ArrayStore());
        $runner = PluginBackgroundRunner::fake($cache);
        $uniqueKey = $runner->uniqueCacheKey('superseded');
        $cache->add($uniqueKey, 1, 60);
        $queue = new RecordingBackgroundJobQueue([
            [
                'id' => 9,
                'type' => 'not_a_job',
                'payload' => ['_unique_key' => $uniqueKey],
                'attempts' => ProcessBackgroundJobsCommand::MAX_ATTEMPTS,
            ],
        ], ackResult: false);

        self::assertSame(0, $this->command()->handle($queue, $runner));
        self::assertSame([9], $queue->acked);
        self::assertTrue($cache->has($uniqueKey));
    }

    private function command(): ProcessBackgroundJobsCommand
    {
        $command = new ProcessBackgroundJobsCommand();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput()));

        return $command;
    }
}

/**
 * @phpstan-type Job array{id: int|string, type: string, payload: array<string, mixed>, attempts: int}
 */
final class RecordingBackgroundJobQueue implements BackgroundJobQueue
{
    /** @var list<int|string> */
    public array $claimed = [];

    /** @var list<int|string> */
    public array $acked = [];

    /** @var list<int|string> */
    public array $retried = [];

    /** @var list<array{id: int|string, expectedAttempts: ?int}> */
    public array $ackedDetails = [];

    /** @var list<array{id: int|string, delay: int, expectedAttempts: ?int}> */
    public array $retriedDetails = [];

    /** @var list<array{id: int|string, delay: int, expectedAttempts: ?int}> */
    public array $released = [];

    /** @var list<array{id: int|string, expectedAttempts: int}> */
    public array $heartbeats = [];

    /** @param list<Job> $jobs */
    public function __construct(private array $jobs = [], private readonly bool $ackResult = true) {}

    public function push(string $type, array $payload): bool
    {
        unset($type, $payload);

        return true;
    }

    public function claim(): ?array
    {
        $job = array_shift($this->jobs);

        if ($job === null) {
            return null;
        }

        $this->claimed[] = $job['id'];

        return $job;
    }

    public function heartbeat(int|string $id, int $expectedAttempts): bool
    {
        $this->heartbeats[] = ['id' => $id, 'expectedAttempts' => $expectedAttempts];

        return true;
    }

    public function ack(int|string $id, ?int $expectedAttempts = null): bool
    {
        $this->acked[] = $id;
        $this->ackedDetails[] = ['id' => $id, 'expectedAttempts' => $expectedAttempts];

        return $this->ackResult;
    }

    public function retry(int|string $id, int $delaySeconds = 30, ?int $expectedAttempts = null): void
    {
        $this->retried[] = $id;
        $this->retriedDetails[] = ['id' => $id, 'delay' => $delaySeconds, 'expectedAttempts' => $expectedAttempts];
    }

    public function release(int|string $id, int $delaySeconds, ?int $expectedAttempts = null): void
    {
        $this->released[] = ['id' => $id, 'delay' => $delaySeconds, 'expectedAttempts' => $expectedAttempts];
    }
}
