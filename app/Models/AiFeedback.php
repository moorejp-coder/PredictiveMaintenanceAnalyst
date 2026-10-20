<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A visitor's rating of one AI answer: "helpful" or "wrong". It holds no
 * personal data, no free text and no IP address.
 */
class AiFeedback extends Model
{
    protected $table = 'ai_feedback';

    protected $fillable = ['step', 'rating', 'source', 'scenario', 'tick'];
}
