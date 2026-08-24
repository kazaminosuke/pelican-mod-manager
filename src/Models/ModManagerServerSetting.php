<?php

namespace Kazaminosuke\ModManager\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-server Mod Manager settings.
 *
 * A missing row is intentionally equivalent to the pre-server-settings
 * behaviour: every project type is enabled and every nullable permission
 * falls back to the corresponding global plugin setting.
 *
 */
class ModManagerServerSetting extends Model
{
    protected $table = 'mod_manager_server_settings';

    /** @var list<string> */
    protected $fillable = [
        'server_id',
        'mod_enabled',
        'plugin_enabled',
        'datapack_enabled',
        'mod_navigation_sort',
        'plugin_navigation_sort',
        'datapack_navigation_sort',
        'allow_user_egg_profile_edit',
        'allow_user_project_install',
        'allow_user_project_update',
        'allow_user_project_delete',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'mod_enabled' => 'boolean',
            'plugin_enabled' => 'boolean',
            'datapack_enabled' => 'boolean',
            'mod_navigation_sort' => 'integer',
            'plugin_navigation_sort' => 'integer',
            'datapack_navigation_sort' => 'integer',
            'allow_user_egg_profile_edit' => 'boolean',
            'allow_user_project_install' => 'boolean',
            'allow_user_project_update' => 'boolean',
            'allow_user_project_delete' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
