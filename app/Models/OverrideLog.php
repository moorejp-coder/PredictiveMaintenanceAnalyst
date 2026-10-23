<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One operator decision to keep a machine running after PRISM said
 * "Stop the machine and inspect it now." The reading and score are the ones
 * the server recomputed, not values the browser sent.
 */
class OverrideLog extends Model
{
    protected $fillable = [
        'operator_name',
        'reason',
        'machine',
        'scenario',
        'tick',
        'risk_score',
        'action_shown',
        'reading',
    ];

    protected function casts(): array
    {
        return [
            'reading' => 'array',
        ];
    }
}
