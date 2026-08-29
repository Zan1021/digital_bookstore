<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Book;
use App\Models\Translation;
use App\Services\PdfTranslationService;

$book = Book::find(2);
$translation = Translation::find(4);

echo "Re-rendering: {$book->title} → {$translation->language_name}\n";

$service = app(PdfTranslationService::class);

try {
    $result = $service->createTranslatedPdf($book, $translation);
    echo "SUCCESS: {$result}\n";
    $translation->update(['rendered_pdf_path' => $result, 'status' => 'rendered']);
    echo "DB updated.\n";
} catch (\Throwable $e) {
    echo "ERROR: {$e->getMessage()}\n";
    echo "File: {$e->getFile()}:{$e->getLine()}\n";
}
