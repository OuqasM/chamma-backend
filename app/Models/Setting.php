<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One editable store setting.
 *
 * @property int $id
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
