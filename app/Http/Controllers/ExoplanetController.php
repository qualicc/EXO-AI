<?php

namespace App\Http\Controllers;

use App\Jobs\RunExoplanetPredictionJob;
use App\Models\Exoplanet;
use App\Models\ExoplanetPredictionJob;
use App\Services\ExoplanetAiClient;
use App\Services\ExoplanetCatalogImporter;
use App\Services\ExoplanetPredictionRefresher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ExoplanetController extends Controller
{
    public function index(Request $request, ExoplanetCatalogImporter $importer): View
    {
        $import = $importer->sync();
        $search = trim((string) $request->query('q', ''));

        $query = Exoplanet::query()->with('latestPrediction');

        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query
                    ->where('target', 'like', '%'.$search.'%')
                    ->orWhere('mission', 'like', '%'.$search.'%')
                    ->orWhere('candidate_id', 'like', '%'.$search.'%');
            });
        }

        $exoplanets = $query
            ->orderByRaw('target is null')
            ->orderBy('target')
            ->orderBy('candidate_id')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total' => Exoplanet::count(),
            'completed' => Exoplanet::where('prediction_status', Exoplanet::STATUS_COMPLETED)->count(),
            'queued' => Exoplanet::whereIn('prediction_status', [Exoplanet::STATUS_QUEUED, Exoplanet::STATUS_SUBMITTING])->count(),
            'running' => Exoplanet::where('prediction_status', Exoplanet::STATUS_RUNNING)->count(),
            'failed' => Exoplanet::where('prediction_status', Exoplanet::STATUS_FAILED)->count(),
        ];

        return view('exoplanets.index', [
            'exoplanets' => $exoplanets,
            'import' => $import,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    public function show(Exoplanet $exoplanet, ExoplanetPredictionRefresher $refresher): View
    {
        $exoplanet->load('latestPrediction');

        if ($exoplanet->latestPrediction?->status === ExoplanetPredictionJob::STATUS_RUNNING) {
            $refresher->refresh($exoplanet->latestPrediction);
            $exoplanet->refresh()->load('latestPrediction');
        }

        return view('exoplanets.show', [
            'exoplanet' => $exoplanet,
            'queuePosition' => $this->queuePosition($exoplanet->latestPrediction),
            'currentPrediction' => $this->currentPrediction(),
        ]);
    }

    public function storePrediction(Request $request, Exoplanet $exoplanet): RedirectResponse
    {
        if ($exoplanet->hasPredictionData()) {
            return back()->with('status', 'Predykcja dla tego obiektu jest juz zapisana.');
        }

        $activePrediction = $exoplanet->predictionJobs()
            ->whereIn('status', [
                ExoplanetPredictionJob::STATUS_QUEUED,
                ExoplanetPredictionJob::STATUS_SUBMITTING,
                ExoplanetPredictionJob::STATUS_RUNNING,
            ])
            ->latest()
            ->first();

        if ($activePrediction) {
            return back()->with('status', 'Ten obiekt jest juz w kolejce albo w trakcie predykcji.');
        }

        $predictionJob = $exoplanet->predictionJobs()->create([
            'user_id' => $request->user()?->id,
            'status' => ExoplanetPredictionJob::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        $exoplanet->update([
            'prediction_status' => Exoplanet::STATUS_QUEUED,
        ]);

        RunExoplanetPredictionJob::dispatch($predictionJob->id);

        return back()->with('status', 'Dodano obiekt do kolejki predykcji.');
    }

    public function queueStatus(Exoplanet $exoplanet, ExoplanetPredictionRefresher $refresher): JsonResponse
    {
        $exoplanet->load('latestPrediction');

        if ($exoplanet->latestPrediction?->status === ExoplanetPredictionJob::STATUS_RUNNING) {
            $refresher->refresh($exoplanet->latestPrediction);
            $exoplanet->refresh()->load('latestPrediction');
        }

        $prediction = $exoplanet->latestPrediction;

        return response()->json([
            'prediction_status' => $exoplanet->prediction_status,
            'has_prediction_data' => $exoplanet->hasPredictionData(),
            'current_prediction' => $this->formatCurrentPrediction($this->currentPrediction()),
            'pending_count' => ExoplanetPredictionJob::where('status', ExoplanetPredictionJob::STATUS_QUEUED)->count(),
            'prediction' => $prediction ? [
                'id' => $prediction->id,
                'status' => $prediction->status,
                'remote_job_id' => $prediction->remote_job_id,
                'queue_position' => $this->queuePosition($prediction),
                'waited_seconds' => $prediction->queued_at ? (int) $prediction->queued_at->diffInSeconds(now()) : null,
                'queued_at' => $prediction->queued_at?->toISOString(),
                'started_at' => $prediction->started_at?->toISOString(),
                'submitted_at' => $prediction->submitted_at?->toISOString(),
                'finished_at' => $prediction->finished_at?->toISOString(),
                'error_message' => $prediction->error_message,
            ] : null,
        ]);
    }

    public function plot(Exoplanet $exoplanet, string $plotKey, ExoplanetAiClient $client): Response
    {
        $prediction = $exoplanet->latestPrediction;
        $remoteJobId = $prediction?->remote_job_id;

        abort_unless(is_string($remoteJobId) && $remoteJobId !== '', 404);

        $response = $client->plot($remoteJobId, $plotKey);

        abort_if($response->failed(), $response->status());

        return response($response->body())
            ->header('Content-Type', $response->header('Content-Type') ?: 'image/png')
            ->header('Cache-Control', 'private, max-age=300');
    }

    private function queuePosition(?ExoplanetPredictionJob $predictionJob): ?int
    {
        if (! $predictionJob || ! in_array($predictionJob->status, [
            ExoplanetPredictionJob::STATUS_QUEUED,
            ExoplanetPredictionJob::STATUS_SUBMITTING,
        ], true)) {
            return null;
        }

        if ($predictionJob->status === ExoplanetPredictionJob::STATUS_SUBMITTING) {
            return 1;
        }

        $submitting = ExoplanetPredictionJob::where('status', ExoplanetPredictionJob::STATUS_SUBMITTING)->count();

        $queuedAhead = ExoplanetPredictionJob::where('status', ExoplanetPredictionJob::STATUS_QUEUED)
            ->where(function ($query) use ($predictionJob): void {
                $query
                    ->where('queued_at', '<', $predictionJob->queued_at)
                    ->orWhere(function ($query) use ($predictionJob): void {
                        $query
                            ->where('queued_at', $predictionJob->queued_at)
                            ->where('id', '<=', $predictionJob->id);
                    });
            })
            ->count();

        return $submitting + $queuedAhead;
    }

    private function currentPrediction(): ?ExoplanetPredictionJob
    {
        return ExoplanetPredictionJob::with('exoplanet')
            ->where('status', ExoplanetPredictionJob::STATUS_SUBMITTING)
            ->oldest('started_at')
            ->first();
    }

    /**
     * @return array{id: int, label: string, status: string}|null
     */
    private function formatCurrentPrediction(?ExoplanetPredictionJob $predictionJob): ?array
    {
        if (! $predictionJob) {
            return null;
        }

        return [
            'id' => $predictionJob->id,
            'label' => $predictionJob->exoplanet?->displayName() ?? 'Obiekt #'.$predictionJob->exoplanet_id,
            'status' => $predictionJob->status,
        ];
    }
}
