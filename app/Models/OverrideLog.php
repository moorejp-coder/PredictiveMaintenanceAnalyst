<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One operator decision to keep a machine running after PRISM said
 * "Stop the machine and inspect it now." The reading and score are the ones
 * the server recomputed, not values the browser sent.
 *
 * @property string $uuid The public id used in URLs and API responses.
 */
class OverrideLog extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * The uuid column is the public id. The integer id stays internal.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'user_id',
        'operator_name',
        'reason',
        'machine',
        'scenario',
        'tick',
        'risk_score',
        'action_shown',
        'reading',
        'edited_at',
        'original_operator_name',
        'original_reason',
    ];

    protected function casts(): array
    {
        return [
            'reading' => 'array',
            'edited_at' => 'datetime',
        ];
    }
}
