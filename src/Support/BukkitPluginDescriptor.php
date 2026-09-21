<?php

namespace Kazaminosuke\ModManager\Support;

use ZipArchive;

/**
 * Reads the stable identity fields from a Bukkit/Paper plugin archive.
 *
 * Paper plugins may ship `paper-plugin.yml`, `plugin.yml`, or both. Name and
 * version prefer the Paper descriptor; author and website fall back to
 * `plugin.yml` because Paper's descriptor often omits them.
 */
final class BukkitPluginDescriptor
{
    /**
     * @param  list<string>  $authors
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $version,
        public readonly array $authors,
        public readonly ?string $website,
        public readonly ?string $main,
        public readonly ?string $filename = null,
    ) {}

    public static function fromJar(string $path, ?string $filename = null): ?self
    {
        if (!class_exists(ZipArchive::class) || !is_file($path)) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $plugin = self::parseYaml((string) $zip->getFromName('plugin.yml'));
            $paper = self::parseYaml((string) $zip->getFromName('paper-plugin.yml'));
        } finally {
            $zip->close();
        }

        if ($plugin === [] && $paper === []) {
            return null;
        }

        $authors = self::authorsFrom($paper) ?: self::authorsFrom($plugin);

        return new self(
            name: self::string($paper['name'] ?? $plugin['name'] ?? null),
            version: self::string($paper['version'] ?? $plugin['version'] ?? null),
            authors: $authors,
            website: self::string($paper['website'] ?? $plugin['website'] ?? null),
            main: self::string($paper['main'] ?? $plugin['main'] ?? null),
            filename: $filename,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromArray(array $metadata): self
    {
        $authors = [];
        foreach ((array) ($metadata['authors'] ?? []) as $author) {
            if (is_string($author) && trim($author) !== '') {
                $authors[] = trim($author);
            }
        }

        $author = self::string($metadata['author'] ?? null);
        if ($author !== null && !in_array($author, $authors, true)) {
            array_unshift($authors, $author);
        }

        return new self(
            name: self::string($metadata['name'] ?? null),
            version: self::string($metadata['version'] ?? null),
            authors: array_values(array_unique($authors)),
            website: self::string($metadata['website'] ?? null),
            main: self::string($metadata['main'] ?? null),
            filename: self::string($metadata['filename'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'version' => $this->version,
            'authors' => $this->authors,
            'website' => $this->website,
            'main' => $this->main,
            'filename' => $this->filename,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function spigotResourceId(): ?string
    {
        if ($this->website === null) {
            return null;
        }

        if (preg_match('~spigotmc\.org/resources/(?:[^/]+\.)?(\d+)~i', $this->website, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public function normalizedName(): ?string
    {
        return self::normalizeIdentity($this->name);
    }

    public function normalizedVersion(): ?string
    {
        return self::normalizeVersion($this->version);
    }

    public static function normalizeIdentity(?string $value): ?string
    {
        $value = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $value) ?? '');

        return $value !== '' ? $value : null;
    }

    public static function normalizeVersion(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = ltrim($value, 'v');
        $value = preg_replace('/[^a-z0-9.+\-]+/', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseYaml(string $yaml): array
    {
        $yaml = trim($yaml);
        if ($yaml === '') {
            return [];
        }

        $data = [];
        $authors = [];
        $inAuthors = false;

        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (preg_match('/^authors:\s*(.*)$/', $line, $matches) === 1) {
                $inline = trim($matches[1]);
                if ($inline === '' || $inline === '|' || $inline === '>') {
                    $inAuthors = true;

                    continue;
                }

                $authors = [...$authors, ...self::parseInlineList($inline)];
                $inAuthors = false;

                continue;
            }

            if ($inAuthors) {
                if (preg_match('/^\s+-\s*(.+)$/', $line, $matches) === 1) {
                    $author = self::unquote(trim($matches[1]));
                    if ($author !== null) {
                        $authors[] = $author;
                    }

                    continue;
                }

                $inAuthors = false;
            }

            if (preg_match('/^(name|version|author|website|main):\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $data[$matches[1]] = self::unquote(trim($matches[2]));
        }

        if ($authors !== []) {
            $data['authors'] = array_values(array_unique($authors));
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function authorsFrom(array $data): array
    {
        $authors = [];
        foreach ((array) ($data['authors'] ?? []) as $author) {
            if (is_string($author) && trim($author) !== '') {
                $authors[] = trim($author);
            }
        }

        $author = self::string($data['author'] ?? null);
        if ($author !== null && !in_array($author, $authors, true)) {
            array_unshift($authors, $author);
        }

        return array_values(array_unique($authors));
    }

    /** @return list<string> */
    private static function parseInlineList(string $value): array
    {
        $value = trim($value);
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $value = substr($value, 1, -1);
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = self::unquote(trim($item));
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function unquote(string $value): ?string
    {
        if ($value === '' || $value === '~' || strcasecmp($value, 'null') === 0) {
            return null;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
