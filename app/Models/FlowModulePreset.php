<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int     $id
 * @property string  $key
 * @property string  $base_module_key
 * @property string  $label
 * @property ?string $description
 * @property ?string $icon
 * @property ?string $category
 * @property array   $config_defaults
 */
class FlowModulePreset extends Model
{
    protected $fillable = [
        'key',
        'base_module_key',
        'label',
        'description',
        'icon',
        'category',
        'config_defaults',
    ];

    protected $casts = [
        'config_defaults' => 'array',
    ];
}
