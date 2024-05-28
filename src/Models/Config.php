<?php

namespace Experteam\ApiLaravelInterface\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Jenssegers\Mongodb\Query\Builder;

/**
 * Experteam\ApiLaravelInterface\Models\Config
 *
 * @property string $id
 * @property string $code
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @method static Builder|Config customPaginate()
 * @method static Builder|Config newModelQuery()
 * @method static Builder|Config newQuery()
 * @method static Builder|Config query()
 * @mixin Eloquent
 */
class Config extends BaseModel
{
    use HasFactory;

    protected $collection = 'config';

    protected $fillable = [
        'code',
        'value'
    ];
}
