<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A visitor's rating of one AI answer: "helpful" or "wrong". It holds no
 * personal data, no free text and no IP address.
 */
class AiFeedback extends Model
{
    use HasUuids;

    /** The uuid column is the public id. The integer id stays internal. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $table = 'ai_feedback';

    protected $fillable = ['step', 'rating', 'source', 'scenario', 'tick'];
}
