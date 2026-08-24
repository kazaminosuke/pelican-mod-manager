<?php

namespace Kazaminosuke\ModManager\Http\Controllers;

use Illuminate\Http\Request;
use Kazaminosuke\ModManager\Support\ModManagerAssets;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

final class ModManagerAssetController
{
    public function __invoke(Request $request, string $version, string $asset): Response
    {
        try {
            $definition = ModManagerAssets::get($asset);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if (!hash_equals($definition['version'], $version)) {
            abort(404);
        }

        $content = file_get_contents($definition['path']);
        if (!is_string($content) || !hash_equals($version, hash('sha256', $content))) {
            abort(404);
        }

        $acceptEncoding = AcceptHeader::fromString($request->headers->get('Accept-Encoding'));
        $gzip = $acceptEncoding->get('gzip');
        $isGzip = $gzip !== null && $gzip->getQuality() > 0;

        if ($isGzip) {
            $encoded = gzencode($content, 9);
            if (is_string($encoded)) {
                $content = $encoded;
            } else {
                $isGzip = false;
            }
        }

        $response = new Response($content, Response::HTTP_OK, [
            'Content-Type' => $definition['content_type'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept-Encoding',
        ]);

        if ($isGzip) {
            $response->headers->set('Content-Encoding', 'gzip');
        }

        $response->setEtag($version.($isGzip ? '-gzip' : '-identity'));
        $response->isNotModified($request);

        return $response;
    }
}
