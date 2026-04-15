<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $from_node_id
 * @property string $from_port
 * @property int    $to_node_id
 * @property string $to_port
 */
class FlowEdge extends Model
{
    protected $fillable = [
        'from_node_id',
        'from_port',
        'to_node_id',
        'to_port',
    ];

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'from_node_id');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'to_node_id');
    }
}
