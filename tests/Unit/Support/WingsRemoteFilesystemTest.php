<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Kazaminosuke\ModManager\Support\WingsRemoteFilesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WingsRemoteFilesystemTest extends TestCase
{
    public function test_the_first_install_can_check_an_uncreated_mods_directory(): void
    {
        $server = new Server();
        $repository = $this->repository($server, 'mods', 404, 'The requested directory was not found on the server.');

        self::assertNull((new WingsRemoteFilesystem())->findListedFile($repository, $server, 'mods', 'first-mod.jar'));
    }

    /** @return array<string, array{string, int, string}> */
    public static function listingFailures(): array
    {
        return [
            'server missing' => ['mods', 404, 'The requested server was not found.'],
            'forbidden' => ['mods', 403, 'Forbidden.'],
            'server root missing' => ['', 404, 'The requested directory was not found on the server.'],
        ];
    }

    #[DataProvider('listingFailures')]
    public function test_other_listing_failures_prevent_the_install(string $directory, int $status, string $message): void
    {
        $server = new Server();
        $repository = $this->repository($server, $directory === '' ? '/' : $directory, $status, $message);

        $this->expectException(RequestException::class);
        (new WingsRemoteFilesystem())->findListedFile($repository, $server, $directory, 'first-mod.jar');
    }

    private function repository(Server $server, string $path, int $status, string $message): DaemonFileRepository
    {
        $response = new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode(['error' => $message], JSON_THROW_ON_ERROR)));
        $repository = $this->createMock(DaemonFileRepository::class);
        $repository->expects(self::once())->method('setServer')->with($server)->willReturnSelf();
        $repository->expects(self::once())->method('getDirectory')->with($path)->willThrowException(new RequestException($response));

        return $repository;
    }
}
