<?php

/*
 * AI provider settings (tasks 7.2–7.3, design §18).
 *
 * Rates are list prices in US dollars, converted to rand with usd_to_zar for the ledger.
 * They are estimates for cost reporting and limits; check them against the provider's
 * current price page and invoices. A model missing here is costed at 'default' and logged.
 */
return [

    /*
     * Model per operation (task 7.3). The operation name is also the ledger's label.
     * 'fallback' is tried when the main model is overloaded (translation only today).
     * Each model can be overridden in .env.
     */
    'operations' => [
        'translate' => [
            'model' => env('AI_TRANSLATE_MODEL', 'gpt-4o'),
            'fallback' => env('AI_TRANSLATE_FALLBACK_MODEL', 'gpt-4o-mini'),
        ],
        'metadata' => ['model' => env('AI_METADATA_MODEL', 'gpt-4o')],
        'visual_qa' => ['model' => env('AI_VISUAL_QA_MODEL', 'gpt-4o')],
        'locate_art_text' => ['model' => env('AI_LOCATE_ART_TEXT_MODEL', env('ILLUSTRATION_TEXT_MODEL', 'gpt-4o'))],
        'art_repair' => ['model' => env('AI_ART_REPAIR_MODEL', env('ILLUSTRATION_TEXT_IMAGE_MODEL', 'gpt-image-1'))],
        'narrate' => ['model' => env('AI_NARRATE_MODEL', 'eleven_multilingual_v2')],
    ],

    // Model used when a call names no configured operation, per interface.
    'defaults' => [
        'text' => 'gpt-4o',
        'vision' => 'gpt-4o',
        'image_edit' => 'gpt-image-1',
        'tts' => 'eleven_multilingual_v2',
    ],

    'usd_to_zar' => (float) env('AI_USD_TO_ZAR', 18.5),

    'rates' => [
        // per 1M tokens
        'openai' => [
            'gpt-4o' => ['input_per_m' => 2.50, 'output_per_m' => 10.00],
            'gpt-4o-mini' => ['input_per_m' => 0.15, 'output_per_m' => 0.60],
            // image edits: tokens when the API reports them, else a flat price per image
            'gpt-image-1' => ['input_per_m' => 10.00, 'output_per_m' => 40.00, 'per_image' => 0.17],
            'default' => ['input_per_m' => 10.00, 'output_per_m' => 40.00, 'per_image' => 0.20],
        ],
        // per 1k characters
        'elevenlabs' => [
            'eleven_multilingual_v2' => ['per_1k_chars' => 0.30],
            'default' => ['per_1k_chars' => 0.30],
        ],
    ],

];
