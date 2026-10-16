<?php

namespace App\Ai;

use Illuminate\Support\Facades\Log;

/** Prices a Usage in rand from config/ai.php rates (task 7.2). */
class Pricing
{
    public static function costZar(Usage $usage): float
    {
        $rates = config("ai.rates.{$usage->provider}", []);
        $rate = $rates[$usage->model] ?? self::familyRate($rates, $usage->model);
        if ($rate === null) {
            Log::warning('AI pricing: no rate for model, using default', ['provider' => $usage->provider, 'model' => $usage->model]);
            $rate = $rates['default'] ?? [];
        }

        $u = $usage->units;
        $input = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0);
        $output = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);

        $usd = $input / 1_000_000 * ($rate['input_per_m'] ?? 0)
            + $output / 1_000_000 * ($rate['output_per_m'] ?? 0)
            + ($u['characters'] ?? 0) / 1000 * ($rate['per_1k_chars'] ?? 0);

        // An image edit the API did not report tokens for is charged per image.
        if ($input + $output === 0 && isset($u['images'])) {
            $usd += $u['images'] * ($rate['per_image'] ?? 0);
        }

        return round($usd * (float) config('ai.usd_to_zar'), 4);
    }

    /** Dated model names (gpt-4o-2024-08-06) use their family's rate (gpt-4o). */
    private static function familyRate(array $rates, string $model): ?array
    {
        $family = null;
        foreach (array_keys($rates) as $name) {
            if ($name !== 'default' && str_starts_with($model, $name.'-') && strlen($name) > strlen((string) $family)) {
                $family = $name;
            }
        }

        return $family === null ? null : $rates[$family];
    }
}
