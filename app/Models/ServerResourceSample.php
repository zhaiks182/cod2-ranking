<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerResourceSample extends Model
{
    protected $fillable = [
        'server_id', 'cpu_percent', 'cpu_usage_ns_raw', 'memory_bytes', 'swap_bytes', 'sampled_at',
    ];

    protected $casts = [
        'sampled_at' => 'datetime',
        'cpu_percent' => 'float',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
