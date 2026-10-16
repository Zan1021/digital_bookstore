<?php

namespace App\Intake;

use Symfony\Component\Process\Process;

/**
 * Runs scripts/intake_preflight.py on a stored upload (task 10.2, Req 2.3–2.5): may it be
 * processed, and what are its pages (size, trim box, live text vs scanned)? Anything that
 * goes wrong while checking counts as a refusal, never a pass (R2).
 *
 * @phpstan-type Result array{ok: bool, rejections: list<array{code: string, message: string}>,
 *   warnings: list<array{code: string, message: string}>, page_count: int, pages: list<array<string, mixed>>, crop: ?array}
 */
class IntakePreflight
{
    /** @return Result */
    public function run(string $absolutePath): array
    {
        $process = new Process(['python', base_path('scripts/intake_preflight.py'), '--input', $absolutePath,
            '--max-mb', (string) round(config('assets.max_upload_kb', 102400) / 1024, 2),
            '--max-pages', (string) (int) config('assets.max_pages', 500)]);
        $process->setTimeout(300);
        $process->run();

        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result) || ! array_key_exists('ok', $result)) {
            return self::refused('check_failed', 'The file could not be checked. Try again, or upload it once more.');
        }

        return $result + ['rejections' => [], 'warnings' => [], 'page_count' => 0, 'pages' => [], 'crop' => null];
    }

    /** @return Result */
    public static function refused(string $code, string $message): array
    {
        return ['ok' => false, 'rejections' => [['code' => $code, 'message' => $message]], 'warnings' => [],
            'page_count' => 0, 'pages' => [], 'crop' => null];
    }
}
