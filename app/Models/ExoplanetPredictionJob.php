<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $exoplanet_id
 * @property int|null $user_id
 * @property string $status
 * @property string|null $remote_job_id
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $last_checked_at
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $response_payload
 * @property array<string, mixed>|null $specialist_data
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'exoplanet_id',
    'user_id',
    'status',
    'remote_job_id',
    'queued_at',
    'started_at',
    'submitted_at',
    'finished_at',
    'last_checked_at',
    'request_payload',
    'response_payload',
    'specialist_data',
    'error_message',
])]
class ExoplanetPredictionJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUBMITTING = 'submitting';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'specialist_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Exoplanet, $this>
     */
    public function exoplanet(): BelongsTo
    {
        return $this->belongsTo(Exoplanet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_SUBMITTING,
            self::STATUS_RUNNING,
        ], true);
    }
}
