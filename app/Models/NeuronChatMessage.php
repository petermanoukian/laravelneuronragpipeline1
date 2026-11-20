<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NeuronChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'content'     => 'array',
            'meta'        => 'array',
            'archived_at' => 'datetime',
        ];
    }
}