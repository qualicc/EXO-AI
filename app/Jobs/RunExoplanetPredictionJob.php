<?php

namespace App\Jobs;

use App\Models\Exoplanet;
use App\Models\ExoplanetPredictionJob;
use App\Services\ExoplanetAiClient;
use App\Services\ExoplanetPredictionRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunExoplanetPredictionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public readonly int $predictionJobId)
    {
        $this->onQueue((string) config('exoplanets.queue', 'exoplanet-predictions'));
    }

    public function handle(ExoplanetAiClient $client, ExoplanetPredictionRefresher $refresher): void
    {
        Cache::lock('exoplanet-ai-submit', (int) config('exoplanets.submit_lock_seconds', 600))
            ->block((int) config('exoplanets.submit_lock_seconds', 600), function () use ($client, $refresher): void {
                $predictionJob = ExoplanetPredictionJob::with('exoplanet')->findOrFail($this->predictionJobId);

                if ($predictionJob->status !== ExoplanetPredictionJob::STATUS_QUEUED) {
                    return;
                }

                $predictionJob->update([
                    'status' => ExoplanetPredictionJob::STATUS_SUBMITTING,
                    'started_at' => now(),
                    'error_message' => null,
                ]);

                $predictionJob->exoplanet()->update([
                    'prediction_status' => Exoplanet::STATUS_SUBMITTING,
                ]);

                try {
                    $created = $client->createJob($predictionJob->exoplanet);

                    $predictionJob->update([
                        'status' => ExoplanetPredictionJob::STATUS_RUNNING,
                        'remote_job_id' => $created['remote_job_id'],
                        'request_payload' => $created['request_payload'],
                        'response_payload' => ['created' => $created['response_payload']],
                        'submitted_at' => now(),
                    ]);

                    $predictionJob->exoplanet()->update([
                        'prediction_status' => Exoplanet::STATUS_RUNNING,
                    ]);

                    if ($created['remote_job_id']) {
                        $refresher->refresh($predictionJob->refresh(), true);
                    }
                } catch (Throwable $exception) {
                    $predictionJob->update([
                        'status' => ExoplanetPredictionJob::STATUS_FAILED,
                        'finished_at' => now(),
                        'error_message' => $exception->getMessage(),
                    ]);

                    $predictionJob->exoplanet()->update([
                        'prediction_status' => Exoplanet::STATUS_FAILED,
                    ]);
                }
            });
    }
}
