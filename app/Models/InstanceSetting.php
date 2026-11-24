<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One setting of the instance. Read and write it through
 * `App\Support\InstanceSettings`, which caches the table.
 */
class InstanceSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
