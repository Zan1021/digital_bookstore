<?php

namespace App\Ai;

/** Which model each AI operation uses, from config/ai.php (task 7.3). */
class AiModels
{
    public static function has(string $operation): bool
    {
        return filled(config("ai.operations.{$operation}.model"));
    }

    /** The operation's model; an operation missing from the config is an error, not a guess. */
    public static function for(string $operation): string
    {
        $model = config("ai.operations.{$operation}.model");
        if (! filled($model)) {
            throw new \InvalidArgumentException("No AI model configured for operation '{$operation}' (config/ai.php).");
        }

        return (string) $model;
    }

    /** Main model first, then the fallback if one is set. */
    public static function chain(string $operation): array
    {
        return array_values(array_unique(array_filter([
            self::for($operation),
            config("ai.operations.{$operation}.fallback"),
        ])));
    }

    /**
     * The model a provider call uses: the explicit 'model' option, else the configured
     * operation's model, else the interface default.
     */
    public static function resolve(array $options, string $interface): string
    {
        if (filled($options['model'] ?? null)) {
            return (string) $options['model'];
        }
        $operation = (string) ($options['operation'] ?? '');

        return $operation !== '' && self::has($operation) ? self::for($operation) : self::default($interface);
    }

    /** Model for a call that names no configured operation: text, vision, image_edit or tts. */
    public static function default(string $interface): string
    {
        return (string) config("ai.defaults.{$interface}");
    }
}
