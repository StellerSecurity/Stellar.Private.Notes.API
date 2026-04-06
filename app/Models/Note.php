<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    protected $fillable = [
        'user_id','note_id','title','text','last_modified',
        'protected','auto_wipe','deleted','pinned','favorite','checksum_hmac','folder_id','folder'
    ];

    protected $casts = [
        'protected' => 'boolean',
        'auto_wipe' => 'boolean',
        'deleted'   => 'boolean',
        'pinned'    => 'boolean',
        'favorite'  => 'boolean',
    ];

    public function folderEntity(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id', 'folder_id');
    }
}
