<?php

namespace App\Services;

use App\Models\Exoplanet;
use SplFileObject;

class ExoplanetCatalogImporter
{
    /**
     * @return array{path: string, missing: bool, seen: int, imported: int, updated: int, skipped_duplicates: int, skipped_invalid: int}
     */
    public function sync(?string $path = null): array
    {
        $path = $path ?: (string) config('exoplanets.catalog_path');

        $result = [
            'path' => $path,
            'missing' => ! is_file($path),
            'seen' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped_duplicates' => 0,
            'skipped_invalid' => 0,
        ];

        if ($result['missing']) {
            return $result;
        }

        $seenKeys = [];

        foreach ($this->readRows($path) as $row) {
            $result['seen']++;

            $normalized = $this->normalizeRow($row);

            if (! $normalized['target'] && ! $normalized['candidate_id']) {
                $result['skipped_invalid']++;

                continue;
            }

            $catalogKey = $this->catalogKey($normalized);

            if (isset($seenKeys[$catalogKey])) {
                $result['skipped_duplicates']++;

                continue;
            }

            $seenKeys[$catalogKey] = true;

            $exoplanet = Exoplanet::updateOrCreate(
                ['catalog_key' => $catalogKey],
                [
                    'target' => $normalized['target'],
                    'mission' => $normalized['mission'],
                    'candidate_id' => $normalized['candidate_id'],
                    'period_days' => $normalized['period_days'],
                    'epoch_bjd' => $normalized['epoch_bjd'],
                    'duration_hours' => $normalized['duration_hours'],
                    'use_llm' => $normalized['use_llm'],
                    'threshold' => $normalized['threshold'],
                    'catalog_payload' => $normalized['payload'],
                ],
            );

            $exoplanet->wasRecentlyCreated ? $result['imported']++ : $result['updated']++;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(string $path): array
    {
        $content = trim((string) file_get_contents($path));

        if ($content === '') {
            return [];
        }

        $jsonRows = $this->readJsonRows($content);

        if ($jsonRows !== null) {
            return $jsonRows;
        }

        return $this->readCsvRows($path);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readJsonRows(string $content): ?array
    {
        $firstChar = substr(ltrim($content), 0, 1);

        if (! in_array($firstChar, ['{', '['], true)) {
            return null;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            if (array_is_list($decoded)) {
                return array_values(array_filter($decoded, is_array(...)));
            }

            return [$decoded];
        }

        $rows = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCsvRows(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($this->detectDelimiter($path));

        $headers = [];
        $rows = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            $line = array_map(
                static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
                $line,
            );

            if ($headers === []) {
                $headers = array_map(fn (mixed $header): string => $this->normalizeKey((string) $header), $line);

                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $line[$index] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ',';
        }

        $firstLine = (string) fgets($handle);
        fclose($handle);

        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];

        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{target: ?string, mission: ?string, candidate_id: ?string, period_days: ?float, epoch_bjd: ?float, duration_hours: ?float, use_llm: bool, threshold: ?float, payload: array<string, mixed>}
     */
    private function normalizeRow(array $row): array
    {
        $row = $this->normalizeKeys($row);

        $target = $this->stringValue($this->first($row, ['target', 'name', 'object', 'object_id', 'hostname', 'pl_name']));
        $mission = $this->stringValue($this->first($row, ['mission', 'mission_name', 'survey']));
        $candidateId = $this->stringValue($this->first($row, ['candidate_id', 'candidate', 'koi_name', 'toi', 'kepoi_name']));
        $periodDays = $this->floatValue($this->first($row, ['period_days', 'period', 'orbital_period', 'pl_orbper']));
        $epochBjd = $this->floatValue($this->first($row, ['epoch_bjd', 'epoch', 'transit_epoch', 't0', 'pl_tranmid']));
        $durationHours = $this->floatValue($this->first($row, ['duration_hours', 'duration', 'transit_duration', 'pl_trandur']));
        $useLlm = $this->boolValue($this->first($row, ['use_llm', 'llm']), true);
        $threshold = $this->floatValue($this->first($row, ['threshold', 'score_threshold']));

        return [
            'target' => $target,
            'mission' => $mission,
            'candidate_id' => $candidateId,
            'period_days' => $periodDays,
            'epoch_bjd' => $epochBjd,
            'duration_hours' => $durationHours,
            'use_llm' => $useLlm,
            'threshold' => $threshold,
            'payload' => array_merge($row, [
                'target' => $target,
                'mission' => $mission,
                'candidate_id' => $candidateId,
                'period_days' => $periodDays,
                'epoch_bjd' => $epochBjd,
                'duration_hours' => $durationHours,
                'use_llm' => $useLlm,
                'threshold' => $threshold,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeKey((string) $key)] = $value;
        }

        return $normalized;
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace("\xEF\xBB\xBF", '', $key);
        $key = strtolower(trim($key));
        $key = preg_replace('/[\s\-\.\/]+/', '_', $key) ?: $key;

        return trim($key, '_');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function first(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'y', 'tak'], true);
    }

    /**
     * @param  array{target: ?string, mission: ?string, candidate_id: ?string, period_days: ?float, epoch_bjd: ?float}  $row
     */
    private function catalogKey(array $row): string
    {
        if ($row['candidate_id']) {
            $basis = sprintf('mission:%s|candidate:%s', $row['mission'] ?: 'unknown', $row['candidate_id']);
        } else {
            $basis = sprintf(
                'target:%s|mission:%s|period:%s|epoch:%s',
                $row['target'] ?: 'unknown',
                $row['mission'] ?: 'unknown',
                $row['period_days'] ?? 'unknown',
                $row['epoch_bjd'] ?? 'unknown',
            );
        }

        return sha1(strtolower($basis));
    }
}
