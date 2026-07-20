<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $catalog_key
 * @property string|null $target
 * @property string|null $mission
 * @property string|null $candidate_id
 * @property float|null $period_days
 * @property float|null $epoch_bjd
 * @property float|null $duration_hours
 * @property bool $use_llm
 * @property float|null $threshold
 * @property array<string, mixed>|null $catalog_payload
 * @property array<string, mixed>|null $prediction_payload
 * @property string $prediction_status
 * @property Carbon|null $prediction_completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'catalog_key',
    'target',
    'mission',
    'candidate_id',
    'period_days',
    'epoch_bjd',
    'duration_hours',
    'use_llm',
    'threshold',
    'catalog_payload',
    'prediction_payload',
    'prediction_status',
    'prediction_completed_at',
])]
class Exoplanet extends Model
{
    public const STATUS_NOT_REQUESTED = 'not_requested';

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
            'period_days' => 'float',
            'epoch_bjd' => 'float',
            'duration_hours' => 'float',
            'use_llm' => 'boolean',
            'threshold' => 'float',
            'catalog_payload' => 'array',
            'prediction_payload' => 'array',
            'prediction_completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ExoplanetPredictionJob, $this>
     */
    public function predictionJobs(): HasMany
    {
        return $this->hasMany(ExoplanetPredictionJob::class);
    }

    /**
     * @return HasOne<ExoplanetPredictionJob, $this>
     */
    public function latestPrediction(): HasOne
    {
        return $this->hasOne(ExoplanetPredictionJob::class)->latestOfMany();
    }

    public function displayName(): string
    {
        return $this->candidate_id ?: ($this->target ?: 'Obiekt #'.$this->id);
    }

    public function hasPredictionData(): bool
    {
        return filled($this->prediction_payload);
    }
}
