<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $composite_id
 * @property int    $from_node_id
 * @property string $from_port
 * @property int    $to_node_id
 * @property string $to_port
 */
class FlowCompositeEdge extends Model
{
    protected $fillable = [
        'composite_id',
        'from_node_id',
        'from_port',
        'to_node_id',
        'to_port',
    ];
}
