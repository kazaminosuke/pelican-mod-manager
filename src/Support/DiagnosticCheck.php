<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * One non-destructive diagnostic check.
 */
final class DiagnosticCheck
{
    /**
     * @param  'pass'|'warning'|'fail'  $status
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $details = [],
    ) {}

    /**
     * @return array{id: string, status: string, summary: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'summary' => $this->summary,
            'details' => $this->details,
        ];
    }
}
