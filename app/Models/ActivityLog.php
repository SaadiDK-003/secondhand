<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $details */
class ActivityLog extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
