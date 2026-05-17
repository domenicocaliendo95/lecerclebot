<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id', 'slug', 'title', 'description', 'cover_path', 'kind',
        'location', 'starts_at', 'ends_at',
        'registration_required', 'registration_opens_at', 'registration_closes_at',
        'max_participants', 'allow_plus_ones', 'max_plus_ones',
        'fee', 'visibility', 'status', 'created_by',
    ];

    protected $casts = [
        'starts_at'              => 'datetime',
        'ends_at'                => 'datetime',
        'registration_opens_at'  => 'datetime',
        'registration_closes_at' => 'datetime',
        'registration_required'  => 'boolean',
        'allow_plus_ones'        => 'boolean',
        'fee'                    => 'decimal:2',
    ];

    public function participants() { return $this->hasMany(EventParticipant::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    public function getRouteKeyName(): string { return 'slug'; }
}
