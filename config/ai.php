<?php

/*
|--------------------------------------------------------------------------
| AI (advisory features: suggestions a person confirms)
|--------------------------------------------------------------------------
|
| Off until a key is set. Keys stay in the backend .env; the frontend never sees them.
|   Groq:   console.groq.com → API Keys        (text, chatbot)
|   Gemini: aistudio.google.com → Get API key  (fallback, reading documents)
| Model names change over time: check the provider console and override them here via .env.
*/

return [
    'provider' => env('AI_PROVIDER', env('GROQ_API_KEY') ? 'groq' : (env('GEMINI_API_KEY') ? 'gemini' : 'none')),

    'fallback' => env('AI_FALLBACK', 'gemini'),

    'providers' => [
        'groq' => [
            'key' => env('GROQ_API_KEY'),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1/chat/completions'),
            'models' => [
                'fast' => env('GROQ_MODEL_FAST', 'llama-3.1-8b-instant'),
                'smart' => env('GROQ_MODEL_SMART', 'llama-3.3-70b-versatile'),
            ],
        ],
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
            'models' => [
                'fast' => env('GEMINI_MODEL_FAST', 'gemini-2.5-flash'),
                'smart' => env('GEMINI_MODEL_SMART', 'gemini-2.5-flash'),
            ],
        ],
    ],

    'timeout' => (int) env('AI_TIMEOUT', 40),
];
