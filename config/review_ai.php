<?php

$path = rtrim(env('REVIEW_AI_PATH', '/opt/eventgenius-ai'), '/');

return [
    // Master switch: false skips analysis entirely (reviews still work).
    'enabled' => env('REVIEW_AI_ENABLED', true),

    // Install folder of the offline analyser (venv/, model/, analyze.py) — see ai/README.md.
    'path' => $path,
    'python' => env('REVIEW_AI_PYTHON', $path . '/venv/bin/python'),
    'script' => env('REVIEW_AI_SCRIPT', $path . '/analyze.py'),
    'model_dir' => env('REVIEW_AI_MODEL_DIR', $path . '/model'),

    // Seconds allowed for one analyze.py call, and reviews sent per call.
    'timeout' => (int) env('REVIEW_AI_TIMEOUT', 120),
    'batch_size' => (int) env('REVIEW_AI_BATCH', 50),

    // Queue for the per-review job (null = default queue).
    'queue' => env('REVIEW_AI_QUEUE'),

    // Venue aggregate: recency half-life and Bayesian prior strength (in reviews).
    'half_life_days' => 90,
    'prior_reviews' => 2,
    // Minimum counted reviews with text before the ranking "sentiment" signal is used.
    'min_reviews' => 3,
];
