<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tournament extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id', 'slug', 'name', 'description', 'cover_path',
        'format', 'status', 'category', 'max_participants', 'fee',
        'registration_opens_at', 'registration_closes_at',
        'start_date', 'end_date', 'created_by',
    ];

    protected $casts = [
        'registration_opens_at'  => 'datetime',
        'registration_closes_at' => 'datetime',
        'start_date' => 'date',
        'end_date'   => 'date',
        'fee'        => 'decimal:2',
    ];

    public function participants() { return $this->hasMany(TournamentParticipant::class); }
    public function matches()      { return $this->hasMany(TournamentMatch::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    public function getRouteKeyName(): string { return 'slug'; }
}
