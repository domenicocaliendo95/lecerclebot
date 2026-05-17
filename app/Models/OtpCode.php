<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class OtpCode extends Model
{
    protected $fillable = [
        'phone', 'code_hash', 'purpose', 'channel',
        'expires_at', 'attempts', 'consumed_at', 'ip',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
        'attempts'    => 'integer',
    ];

    public const MAX_ATTEMPTS = 5;
    public const TTL_MINUTES = 5;

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return ! is_null($this->consumed_at);
    }

    public function isLocked(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function verify(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }

    public function markConsumed(): void
    {
        $this->consumed_at = now();
        $this->save();
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }
}
