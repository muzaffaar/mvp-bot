<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffAlias extends Model
{
    protected $fillable = [
        'staff_id',
        'alias',
        'normalized_alias',
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
