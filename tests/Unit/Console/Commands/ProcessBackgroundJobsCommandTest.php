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
        self::assertSame([], $queue->retried);
        self::assertFalse($cache->has($uniqueKey));
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

    /** @param list<Job> $jobs */
    public function __construct(private array $jobs = []) {}

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

    public function ack(int|string $id): void
    {
        $this->acked[] = $id;
    }

    public function retry(int|string $id, int $delaySeconds = 30): void
    {
        unset($delaySeconds);
        $this->retried[] = $id;
    }
}
