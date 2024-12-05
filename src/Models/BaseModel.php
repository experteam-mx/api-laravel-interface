<?php

namespace Experteam\ApiLaravelInterface\Models;

use Carbon\Carbon;
use Experteam\ApiLaravelCrud\Models\ModelPaginate;
use MongoDB\Laravel\Eloquent\Model;

class BaseModel extends Model
{
    use ModelPaginate;
    public bool $isMongoDB = true;

    public function getCast($param, $value)
    {
        return match ($this->casts[$param] ?? '') {
            'boolean' => !($value == 'false') && $value,
            'integer', 'float' => floatval($value),
            'datetime' => Carbon::create($value),
            default => $value,
        };
    }
}
