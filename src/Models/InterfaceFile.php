<?php

namespace Experteam\ApiLaravelInterface\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Query\Builder;

/**
 * Experteam\ApiLaravelInterface\Models\InterfaceFile
 *
 * @property string $id
 * @property string|null $name
 * @property string|null $type
 * @property string|null $interface_request_id
 * @property string|null $transmission_output
 * @property string|null $destination_folder
 * @property int $status
 * @property string|null $file_content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|InterfaceFile customPaginate()
 * @method static Builder|InterfaceFile newModelQuery()
 * @method static Builder|InterfaceFile newQuery()
 * @method static Builder|InterfaceFile query()
 * @mixin Eloquent
 */
class InterfaceFile extends BaseModel
{
    use HasFactory;

    protected $table = 'interface_file';

    protected $fillable = [
        'name',
        'type',
        'interface_request_id',
        'transmission_output',
        'status',
        'destination_folder',
        'file_content',
    ];

    protected $casts = [
        'status' => 'integer'
    ];
}
