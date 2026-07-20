<?php

namespace App\Services;

use App\Models\Exoplanet;
use App\Models\ExoplanetPredictionJob;
use Throwable;

class ExoplanetPredictionRefresher
{
    public function __construct(private readonly ExoplanetAiClient $client) {}

    public function refresh(ExoplanetPredictionJob $predictionJob, bool $force = false): ExoplanetPredictionJob
    {
        if ($predictionJob->status !== ExoplanetPredictionJob::STATUS_RUNNING || ! $predictionJob->remote_job_id) {
            return $predictionJob;
        }

        $minimumPollSeconds = (int) config('exoplanets.status_poll_seconds', 5);

        if (! $force && $predictionJob->last_checked_at?->gt(now()->subSeconds($minimumPollSeconds))) {
            return $predictionJob;
        }

        try {
            $statusPayload = $this->client->status($predictionJob->remote_job_id);
        } catch (Throwable $exception) {
            $predictionJob->update([
                'last_checked_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            return $predictionJob->refresh();
        }

        $remoteStatus = $this->client->classifyStatus($statusPayload);
        $responsePayload = array_merge($predictionJob->response_payload ?: [], [
            'latest_status' => $statusPayload,
        ]);

        if ($remoteStatus === 'failed') {
            $predictionJob->update([
                'status' => ExoplanetPredictionJob::STATUS_FAILED,
                'response_payload' => $responsePayload,
                'finished_at' => now(),
                'last_checked_at' => now(),
                'error_message' => $statusPayload['message'] ?? 'Predykcja w systemie AI zakonczyla sie bledem.',
            ]);

            $predictionJob->exoplanet()->update([
                'prediction_status' => Exoplanet::STATUS_FAILED,
            ]);

            return $predictionJob->refresh();
        }

        if ($remoteStatus === 'completed') {
            $specialistData = null;

            try {
                $specialistData = $this->client->specialistData($predictionJob->remote_job_id);
            } catch (Throwable $exception) {
                $responsePayload['specialist_data_error'] = $exception->getMessage();
            }

            $predictionPayload = [
                'status' => $statusPayload,
                'specialist_data' => $specialistData,
            ];

            $predictionJob->update([
                'status' => ExoplanetPredictionJob::STATUS_COMPLETED,
                'response_payload' => $responsePayload,
                'specialist_data' => $specialistData,
                'finished_at' => now(),
                'last_checked_at' => now(),
                'error_message' => null,
            ]);

            $predictionJob->exoplanet()->update([
                'prediction_status' => Exoplanet::STATUS_COMPLETED,
                'prediction_payload' => $predictionPayload,
                'prediction_completed_at' => now(),
            ]);

            return $predictionJob->refresh();
        }

        $predictionJob->update([
            'response_payload' => $responsePayload,
            'last_checked_at' => now(),
        ]);

        return $predictionJob->refresh();
    }
}
