<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogParserState extends Model
{
    protected $table = 'log_parser_state';

    protected $fillable = ['server_id', 'log_path', 'byte_offset', 'current_round_id', 'current_match_id', 'pending_map', 'pending_gametype', 'pending_match_info'];
}
