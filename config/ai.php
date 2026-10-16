<?php

/*
 * AI provider settings (tasks 7.2–7.3, design §18).
 *
 * Rates are list prices in US dollars, converted to rand with usd_to_zar for the ledger.
 * They are estimates for cost reporting and limits; check them against the provider's
 * current price page and invoices. A model missing here is costed at 'default' and logged.
 */
return [

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
