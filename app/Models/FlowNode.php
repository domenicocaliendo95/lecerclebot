<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int     $id
 * @property string  $module_key
 * @property ?string $label
 * @property array   $config
 * @property array   $position
 * @property bool    $is_entry
 * @property ?string $entry_trigger
 */
class FlowNode extends Model
{
    protected $fillable = [
        'module_key',
        'label',
        'config',
        'position',
        'is_entry',
        'entry_trigger',
    ];

    protected $casts = [
        'config'   => 'array',
        'position' => 'array',
        'is_entry' => 'boolean',
    ];

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(FlowEdge::class, 'from_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(FlowEdge::class, 'to_node_id');
    }
}
