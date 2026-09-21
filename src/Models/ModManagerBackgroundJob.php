<?php

namespace Kazaminosuke\ModManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One persisted Mod Manager background job waiting for `schedule:run`.
 */
class ModManagerBackgroundJob extends Model
{
    protected $table = 'mod_manager_background_jobs';

    /** @var list<string> */
    protected $fillable = [
        'type',
        'payload',
        'unique_key',
        'available_at',
        'reserved_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'reserved_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
