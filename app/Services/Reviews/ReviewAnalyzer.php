<?php

namespace App\Services\Reviews;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Thin wrapper around the offline Python analyser (ai/analyze.py, installed under REVIEW_AI_PATH).
 * One process per batch: the model is loaded once per call, no server process is kept running.
 */
class ReviewAnalyzer
{
    private const STATUS_CACHE = 'review-ai:status';

    public function __construct(private ?array $config = null)
    {
        $this->config = $config ?? (array) config('review_ai', []);
    }

    /** True when the analyser is enabled and its interpreter + script exist. */
    public function available(): bool
    {
        return $this->missing() === null;
    }

    /** Why the analyser cannot run (null when it can). */
    public function missing(): ?string
    {
        if (!filter_var($this->config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return 'disabled (REVIEW_AI_ENABLED=false)';
        }
        $python = (string) ($this->config['python'] ?? '');
        $script = (string) ($this->config['script'] ?? '');
        if ($python === '' || !is_file($python) || !is_executable($python)) {
            return "python not found at {$python}";
        }
        if ($script === '' || !is_file($script) || !is_readable($script)) {
            return "analyze.py not found at {$script}";
        }
        return null;
    }

    /**
     * Analyse reviews. Input rows: ['id', 'title', 'comment', 'rating'].
     * Returns [id => result] or null when the analyser is unavailable or failed (a warning is logged).
     */
    public function analyze(array $rows): ?array
    {
        if ($rows === []) {
            return [];
        }
        if (($why = $this->missing()) !== null) {
            Log::warning("Review analysis skipped: {$why}");
            return null;
        }

        $payload = array_map(fn($r) => [
            'id' => (int) $r['id'],
            'title' => (string) ($r['title'] ?? ''),
            'comment' => (string) ($r['comment'] ?? ''),
            'rating' => isset($r['rating']) ? (int) $r['rating'] : null,
        ], array_values($rows));

        try {
            $process = $this->process([]);
            $process->setInput(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            $process->run();
            if (!$process->isSuccessful()) {
                Log::warning('Review analysis failed', [
                    'exit' => $process->getExitCode(),
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
                ]);
                return null;
            }
            $stderr = trim($process->getErrorOutput());
            if ($stderr !== '' && str_contains($stderr, 'model unavailable')) {
                Log::warning('Review analysis ran without the model (lexicon only): ' . mb_substr($stderr, 0, 500));
            }
            $decoded = json_decode($process->getOutput(), true);
            if (!is_array($decoded)) {
                Log::warning('Review analysis returned invalid JSON', ['output' => mb_substr($process->getOutput(), 0, 500)]);
                return null;
            }
        } catch (Throwable $e) {
            Log::warning('Review analysis error: ' . $e->getMessage());
            return null;
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['id'])) {
                $out[(int) $item['id']] = $item;
            }
        }
        return $out;
    }

    /** Health check for the admin dashboard (cached 10 minutes). */
    public function status(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::STATUS_CACHE);
        }
        try {
            return Cache::remember(self::STATUS_CACHE, 600, fn() => $this->probe());
        } catch (Throwable $e) {
            return $this->probe();
        }
    }

    private function probe(): array
    {
        $base = [
            'enabled' => filter_var($this->config['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'path' => $this->config['path'] ?? null,
            'available' => false,
            'model_loaded' => false,
            'model_version' => null,
            'error' => null,
            'checked_at' => now()->toIso8601String(),
        ];
        if (($why = $this->missing()) !== null) {
            return ['error' => $why] + $base;
        }
        try {
            $process = $this->process(['--status']);
            $process->setTimeout(60);
            $process->run();
            $info = json_decode($process->getOutput(), true);
            if (!is_array($info)) {
                return ['available' => true, 'error' => mb_substr($process->getErrorOutput(), 0, 300) ?: 'no status output'] + $base;
            }
            return [
                'available' => true,
                'model_loaded' => (bool) ($info['ok'] ?? false),
                'model_version' => $info['model_version'] ?? null,
                'error' => $info['error'] ?? null,
            ] + $base;
        } catch (Throwable $e) {
            return ['available' => true, 'error' => $e->getMessage()] + $base;
        }
    }

    private function process(array $args): Process
    {
        $cmd = array_merge([(string) $this->config['python'], '-I', '-B', (string) $this->config['script']], $args);
        $process = new Process($cmd, dirname((string) $this->config['script']), [
            'REVIEW_AI_MODEL_DIR' => (string) ($this->config['model_dir'] ?? ''),
            'OMP_NUM_THREADS' => '1',
            'ORT_DISABLE_TELEMETRY' => '1',
            'HF_HUB_OFFLINE' => '1',
            'TOKENIZERS_PARALLELISM' => 'false',
        ]);
        $process->setTimeout((float) ($this->config['timeout'] ?? 120));
        return $process;
    }
}
