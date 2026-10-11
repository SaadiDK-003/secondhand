<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProhibitedKeyword extends Model
{
    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
