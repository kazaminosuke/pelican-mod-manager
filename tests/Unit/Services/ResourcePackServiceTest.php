<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Kazaminosuke\ModManager\Services\ResourcePackService;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ResourcePackServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_install_writes_server_properties_and_separate_metadata(): void
    {
        $server = new Server();
        $server->forceFill(['id' => 4]);
        $repository = Mockery::mock(DaemonFileRepository::class);
        $response = new Response(new PsrResponse(200));
        $oldProperties = "motd=Example\n";

        $repository->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $repository->shouldReceive('getContent')->once()->with('server.properties')->andReturn($oldProperties);
        $repository->shouldReceive('putContent')->twice()->withArgs(function (string $path, string $content) use ($response): bool {
            if ($path === 'server.properties') {
                self::assertStringContainsString('resource-pack=https://example.test/pack.zip', $content);
                self::assertStringContainsString('resource-pack-sha1=0123456789abcdef0123456789abcdef01234567', $content);
            } else {
                self::assertSame(ResourcePackService::METADATA_FILENAME, $path);
                self::assertStringContainsString('"project_type": "resourcepack"', $content);
                self::assertStringContainsString('"source": "modrinth"', $content);
            }

            return true;
        })->andReturn($response);

        $metadata = (new ResourcePackService())->installOrUpdate(
            $server,
            $repository,
            [
                'source' => 'modrinth',
                'project_id' => 'pack-id',
                'slug' => 'pack-slug',
                'title' => 'Example Pack',
                'author' => 'Author',
            ],
            ['id' => 'version-id', 'version_number' => '1.0.0'],
            [
                'filename' => 'pack.zip',
                'url' => 'https://example.test/pack.zip',
                'hashes' => [
                    'sha1' => '0123456789abcdef0123456789abcdef01234567',
                    'sha512' => str_repeat('a', 128),
                ],
            ],
        );

        self::assertSame('pack-id', $metadata['project_id']);
        self::assertSame('1.0.0', $metadata['version_number']);
        self::assertSame(str_repeat('a', 128), $metadata['hashes']['sha512']);
    }

    public function test_get_installed_treats_missing_metadata_as_null(): void
    {
        $server = new Server();
        $server->forceFill(['id' => 4]);
        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $repository->shouldReceive('getContent')
            ->once()
            ->with(ResourcePackService::METADATA_FILENAME)
            ->andThrow(new \Illuminate\Contracts\Filesystem\FileNotFoundException('missing'));

        self::assertNull((new ResourcePackService())->getInstalled($server, $repository));
    }

    public function test_get_installed_wraps_wings_transport_failures(): void
    {
        $server = new Server();
        $server->forceFill(['id' => 4]);
        $repository = Mockery::mock(DaemonFileRepository::class);
        $repository->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
        $repository->shouldReceive('getContent')
            ->once()
            ->with(ResourcePackService::METADATA_FILENAME)
            ->andThrow(new \RuntimeException('connection refused'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Resource pack metadata is unavailable.');

        (new ResourcePackService())->getInstalled($server, $repository);
    }
}
