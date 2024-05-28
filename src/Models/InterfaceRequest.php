<?php

namespace Experteam\ApiLaravelInterface\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Jenssegers\Mongodb\Query\Builder;

/**
 * Experteam\ApiLaravelInterface\Models\InterfaceRequest
 *
 * @property string $id
 * @property string|null $transaction_id
 * @property int $status
 * @property Carbon|null $from
 * @property Carbon|null $to
 * @property bool|null $to_sftp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|InterfaceRequest customPaginate()
 * @method static Builder|InterfaceRequest newModelQuery()
 * @method static Builder|InterfaceRequest newQuery()
 * @method static Builder|InterfaceRequest query()
 * @mixin Eloquent
 */
class InterfaceRequest extends BaseModel
{
    use HasFactory;

    protected $collection = 'interface_request';

    protected $fillable = [
        'transaction_id',
        'status',
        'from',
        'to',
        'to_sftp'
    ];

    protected $casts = [
        'status' => 'integer'
    ];
}
