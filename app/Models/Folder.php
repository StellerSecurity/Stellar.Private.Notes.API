<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    protected $fillable = [
        'user_id', 'folder_id', 'name', 'last_modified', 'deleted',
    ];

    protected $casts = [
        'deleted' => 'boolean',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'folder_id', 'folder_id');
    }
}
