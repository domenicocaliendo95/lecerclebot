<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int     $id
 * @property int     $composite_id
 * @property string  $module_key
 * @property ?string $label
 * @property array   $config
 * @property array   $position
 * @property bool    $is_entry
 */
class FlowCompositeNode extends Model
{
    protected $fillable = [
        'composite_id',
        'module_key',
        'label',
        'config',
        'position',
        'is_entry',
    ];

    protected $casts = [
        'config'   => 'array',
        'position' => 'array',
        'is_entry' => 'boolean',
    ];
}
