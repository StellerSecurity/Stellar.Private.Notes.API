<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $fillable = [
        'user_id','note_id','title','text','last_modified',
        'protected','auto_wipe','deleted','checksum_hmac'
    ];

    protected $casts = [
        'protected' => 'boolean',
        'auto_wipe' => 'boolean',
        'deleted'   => 'boolean',
    ];
}
