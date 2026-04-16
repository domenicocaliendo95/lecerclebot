<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int     $id
 * @property string  $key
 * @property string  $label
 * @property ?string $description
 * @property ?string $icon
 * @property string  $category
 */
class FlowComposite extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'icon',
        'category',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowCompositeNode::class, 'composite_id');
    }

    public function edges(): HasMany
    {
        return $this->hasMany(FlowCompositeEdge::class, 'composite_id');
    }
}
