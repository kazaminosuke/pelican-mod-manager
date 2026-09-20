<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Console\Commands;

use Illuminate\Console\OutputStyle;
use Kazaminosuke\ModManager\Console\Commands\RunBackgroundJobCommand;
use Kazaminosuke\ModManager\Jobs\BackgroundJob;
use Kazaminosuke\ModManager\Support\PluginBackgroundRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RunBackgroundJobCommandTest extends TestCase
{
    public function test_invalid_payload_fails_without_executing(): void
    {
        $command = new RunBackgroundJobCommand();
        $input = new ArrayInput([
            'type' => BackgroundJob::SCAN,
            'payload' => 'not-valid-base64!!!',
        ]);
        $input->bind($command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput()));

        self::assertSame(1, $command->handle(PluginBackgroundRunner::disabled()));
    }
}
