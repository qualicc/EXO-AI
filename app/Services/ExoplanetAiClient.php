<?php

namespace App\Services;

use App\Models\Exoplanet;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExoplanetAiClient
{
    /**
     * @return array{request_payload: array<string, mixed>, response_payload: array<string, mixed>, remote_job_id: ?string}
     */
    public function createJob(Exoplanet $exoplanet): array
    {
        $payload = $this->payloadFor($exoplanet);

        $body = $this->post('/api/exoplanet/jobs', $payload);

        return [
            'request_payload' => $payload,
            'response_payload' => $body,
            'remote_job_id' => $this->remoteJobId($body),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $remoteJobId): array
    {
        return $this->get('/api/exoplanet/jobs/'.$remoteJobId);
    }

    /**
     * @return array<string, mixed>
     */
    public function specialistData(string $remoteJobId): array
    {
        return $this->get('/api/exoplanet/jobs/'.$remoteJobId.'/specialist-data');
    }

    public function plot(string $remoteJobId, string $plotKey): Response
    {
        return Http::timeout((int) config('exoplanets.ai.timeout', 20))
            ->withHeaders($this->headers())
            ->get($this->baseUrl().'/api/exoplanet/jobs/'.$remoteJobId.'/plots/'.$plotKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(Exoplanet $exoplanet): array
    {
        $note = collect([
            'candidate_id' => $exoplanet->candidate_id,
            'period_days' => $exoplanet->period_days,
            'epoch_bjd' => $exoplanet->epoch_bjd,
            'duration_hours' => $exoplanet->duration_hours,
        ])
            ->filter(static fn (mixed $value): bool => filled($value))
            ->map(static fn (mixed $value, string $key): string => $key.'='.$value)
            ->implode('; ');

        return array_filter([
            'target' => $exoplanet->target,
            'mission' => $exoplanet->mission,
            'mission_name' => $exoplanet->catalog_payload['mission_name'] ?? null,
            'download_all' => false,
            'threshold' => $exoplanet->threshold,
            'use_llm' => $exoplanet->use_llm,
            'request_note' => $note ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function classifyStatus(array $payload): string
    {
        $rawStatus = Arr::get($payload, 'status')
            ?? Arr::get($payload, 'state')
            ?? Arr::get($payload, 'job.status')
            ?? Arr::get($payload, 'job.state')
            ?? Arr::get($payload, 'data.status');

        $status = strtolower((string) $rawStatus);

        if (in_array($status, ['completed', 'complete', 'finished', 'success', 'succeeded', 'done'], true)) {
            return 'completed';
        }

        if (in_array($status, ['failed', 'failure', 'error', 'cancelled', 'canceled'], true)) {
            return 'failed';
        }

        return 'running';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::timeout((int) config('exoplanets.ai.timeout', 20))
            ->withHeaders($this->headers())
            ->post($this->baseUrl().$path, $payload);

        if ($response->failed()) {
            throw new RuntimeException('AI service returned HTTP '.$response->status().': '.$response->body());
        }

        return $this->jsonBody($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = Http::timeout((int) config('exoplanets.ai.timeout', 20))
            ->withHeaders($this->headers())
            ->get($this->baseUrl().$path);

        if ($response->failed()) {
            throw new RuntimeException('AI service returned HTTP '.$response->status().': '.$response->body());
        }

        return $this->jsonBody($response->json());
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.exoplanet_ai.url'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-API-Key' => (string) config('services.exoplanet_ai.key'),
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(mixed $body): array
    {
        return is_array($body) ? $body : ['raw' => $body];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function remoteJobId(array $body): ?string
    {
        foreach (['job_id', 'jobId', 'id', 'uuid', 'data.id', 'data.job_id', 'job.id', 'job.job_id', 'job.uuid'] as $key) {
            $value = Arr::get($body, $key);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
