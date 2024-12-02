<?php

namespace Experteam\ApiLaravelInterface\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use MongoDB\Laravel\Query\Builder;

/**
 * Experteam\ApiLaravelInterface\Models\InterfaceRequest
 *
 * @property string $id
 * @property string|null $transaction_id
 * @property int $status
 * @property string|null $message
 * @property mixed|null $detail
 * @property Carbon|null $from
 * @property Carbon|null $to
 * @property bool|null $to_sftp
 * @property string|null $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, InterfaceFile> $interfaceFiles
 * @method static Builder|InterfaceRequest customPaginate()
 * @method static Builder|InterfaceRequest newModelQuery()
 * @method static Builder|InterfaceRequest newQuery()
 * @method static Builder|InterfaceRequest query()
 * @mixin Eloquent
 */
class InterfaceRequest extends BaseModel
{
    use HasFactory;

    protected $table = 'interface_request';

    protected $fillable = [
        'transaction_id',
        'status',
        'from',
        'to',
        'to_sftp',
        'message',
        'detail',
        'type'
    ];

    protected $casts = [
        'status' => 'integer'
    ];

    public function interfaceFiles()
    {
        return $this->hasMany(InterfaceFile::class);
    }
}
