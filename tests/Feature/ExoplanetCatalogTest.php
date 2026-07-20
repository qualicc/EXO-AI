<?php

namespace Tests\Feature;

use App\Jobs\RunExoplanetPredictionJob;
use App\Models\Exoplanet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExoplanetCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_imports_catalog_and_skips_duplicate_rows(): void
    {
        $path = storage_path('framework/testing/exoplanets.csv');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", [
            'target,mission,candidate_id,period_days,epoch_bjd,duration_hours,use_llm,threshold',
            'KIC 11442793,Kepler,K0351.08,14.44912434,2454835.200,2.704,true,0.37',
            'KIC 11442793,Kepler,K0351.08,14.44912434,2454835.200,2.704,true,0.37',
        ]));

        config(['exoplanets.catalog_path' => $path]);

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('KIC 11442793')
            ->assertSee('K0351.08')
            ->assertSee('Pominięte duplikaty: 1');

        $this->assertDatabaseCount('exoplanets', 1);
    }

    public function test_guests_cannot_request_prediction(): void
    {
        $exoplanet = Exoplanet::create([
            'catalog_key' => 'catalog-key',
            'target' => 'KIC 11442793',
            'mission' => 'Kepler',
            'candidate_id' => 'K0351.08',
        ]);

        $response = $this->post(route('exoplanets.predictions.store', $exoplanet));

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('exoplanet_prediction_jobs', 0);
    }

    public function test_authenticated_users_can_queue_prediction(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $exoplanet = Exoplanet::create([
            'catalog_key' => 'catalog-key',
            'target' => 'KIC 11442793',
            'mission' => 'Kepler',
            'candidate_id' => 'K0351.08',
        ]);

        $response = $this->actingAs($user)->post(route('exoplanets.predictions.store', $exoplanet));

        $response->assertRedirect();
        $this->assertDatabaseHas('exoplanet_prediction_jobs', [
            'exoplanet_id' => $exoplanet->id,
            'user_id' => $user->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('exoplanets', [
            'id' => $exoplanet->id,
            'prediction_status' => 'queued',
        ]);

        Queue::assertPushed(RunExoplanetPredictionJob::class);
    }

    public function test_completed_prediction_is_rendered_as_grouped_sections_without_raw_response(): void
    {
        $exoplanet = Exoplanet::create([
            'catalog_key' => 'catalog-key',
            'target' => 'KIC 10002261',
            'mission' => 'Kepler',
            'candidate_id' => 'K10002261.01',
            'catalog_payload' => [
                'target' => 'KIC 10002261',
                'mission' => 'Kepler',
            ],
            'prediction_status' => 'completed',
            'prediction_payload' => [
                'status' => [
                    'job_id' => 'remote-1',
                    'status' => 'finished',
                    'result' => [
                        'prediction' => [
                            'probability_planet_like' => 0.9917575716972351,
                            'probability_false_positive' => 0.008242428302764893,
                            'threshold' => 0.37,
                            'class_label' => 'planet_like',
                            'confidence_bucket' => 'very_high',
                            'model_adapter' => 'pytorch_checkpoint',
                            'mock' => false,
                        ],
                        'llm_report' => [
                            'title' => 'Wstepny vetting KIC 10002261',
                            'summary' => 'Model wskazuje sygnal planet-like, ale wymaga walidacji.',
                            'evidence_for_planet_like' => ['Wysokie prawdopodobienstwo klasy planet-like.'],
                            'evidence_for_false_positive' => ['Aktywna flaga low_snr.'],
                            'quality_warnings' => ['Niski SNR.'],
                            'recommendation' => 'Zweryfikowac recznie.',
                            'specialist_note' => 'Sprawdzic folded local.',
                        ],
                        'metrics' => [
                            'period_days' => 8.378975795159032,
                            'snr' => 1.489082712499873,
                        ],
                        'quality_flags' => [
                            'low_snr' => true,
                            'possible_secondary_eclipse' => false,
                        ],
                        'plots' => [
                            'raw_png' => 'http://127.0.0.1:8000/api/exoplanet/jobs/remote-1/plots/raw_png',
                        ],
                    ],
                ],
                'specialist_data' => [
                    'bls_diagnostics' => [
                        'best_period_days' => 8.378975795159032,
                    ],
                    'lightcurve_stats' => [
                        'points_downloaded' => 469,
                    ],
                ],
            ],
        ]);

        $exoplanet->predictionJobs()->create([
            'status' => 'completed',
            'remote_job_id' => 'remote-1',
            'queued_at' => now(),
            'finished_at' => now(),
        ]);

        $response = $this->get(route('exoplanets.show', $exoplanet));

        $response
            ->assertOk()
            ->assertSee('Wynik modelu')
            ->assertSee('Wstepny vetting KIC 10002261')
            ->assertSee('Metryki tranzytu')
            ->assertSee('Flagi jakosci')
            ->assertSee('Wykresy')
            ->assertSee('data-plot-preview-trigger', false)
            ->assertSee('data-plot-preview-close', false)
            ->assertSee(route('exoplanets.plots.show', [$exoplanet, 'raw_png']), false)
            ->assertDontSee('probability_planet_like')
            ->assertDontSee('latest_status');
    }

    public function test_plot_proxy_fetches_remote_plot_with_server_side_api_key(): void
    {
        config([
            'services.exoplanet_ai.url' => 'http://ai.test',
            'services.exoplanet_ai.key' => 'secret-key',
        ]);

        Http::fake([
            'http://ai.test/api/exoplanet/jobs/remote-1/plots/raw_png' => Http::response('PNGDATA', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $exoplanet = Exoplanet::create([
            'catalog_key' => 'catalog-key',
            'target' => 'KIC 10002261',
            'mission' => 'Kepler',
            'candidate_id' => 'K10002261.01',
        ]);

        $exoplanet->predictionJobs()->create([
            'status' => 'completed',
            'remote_job_id' => 'remote-1',
            'queued_at' => now(),
            'finished_at' => now(),
        ]);

        $response = $this->get(route('exoplanets.plots.show', [$exoplanet, 'raw_png']));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertSee('PNGDATA');

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-API-Key', 'secret-key'));
    }
}
