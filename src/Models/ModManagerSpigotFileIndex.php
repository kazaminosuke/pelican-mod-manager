<?php

namespace Kazaminosuke\ModManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * High-confidence local mapping of a plugin JAR hash to a Spigot resource.
 *
 * Spigot has no complete upstream hash reverse lookup. Once a scan or
 * install identifies a file with high confidence, later scans reuse this
 * mapping instead of guessing from ambiguous name matches.
 *
 * @property int $id
 * @property string $sha256
 * @property string $resource_id
 * @property string $version_id
 * @property string $version_number
 * @property string|null $plugin_name
 */
class ModManagerSpigotFileIndex extends Model
{
    protected $table = 'mod_manager_spigot_file_index';

    /** @var list<string> */
    protected $fillable = [
        'sha256',
        'resource_id',
        'version_id',
        'version_number',
        'plugin_name',
    ];
}
