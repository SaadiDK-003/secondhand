<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $guarded = ['id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [];
    }
}
