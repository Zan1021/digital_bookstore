<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pipeline stage run (task 5.3, Req 3.3): start, end, duration, attempt, status,
 * error. Written by App\Support\StageTimer.
 */
class JobRun extends Model
{
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    protected $table = 'jobs_runs';

    // Keep milliseconds on started_at/finished_at (timestamp(3) columns) so parallel page
    // stages can be ordered; Laravel's default format drops them.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'book_id', 'stage', 'status', 'attempt', 'started_at', 'finished_at',
        'duration_ms', 'error', 'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
