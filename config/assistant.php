<?php

// Ask EventGenius (chat-style venue search). See docs/search-assistant.md.
return [
    // Messages per visitor (IP) per minute and per day, and for the whole site per minute.
    'per_minute' => (int) env('ASSISTANT_PER_MINUTE', 20),
    'per_day' => (int) env('ASSISTANT_PER_DAY', 400),
    'global_per_minute' => (int) env('ASSISTANT_GLOBAL_PER_MINUTE', 600),
];
