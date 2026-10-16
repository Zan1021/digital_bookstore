<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 9.1 (design §4, Req 4.1–4.9, 8.7, 11): the Scene Engine's data model.
 *
 *  source_pages        one row per page of the source PDF, with its art plates
 *  blocks              every text block (stable ULID), its treatment and style
 *  block_translations  a language edition's text per block
 *  resolved_layouts    where the layout service put that text (Req 8.7)
 *  page_checks         gate results per page per run (Req 11)
 *
 * Geometry is in PDF points, top-left origin, as [x0, y0, x1, y1].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');           // 1-based
            $table->decimal('width_pt', 8, 2);
            $table->decimal('height_pt', 8, 2);
            $table->json('trim_box')->nullable();             // [x0, y0, x1, y1]
            $table->boolean('has_live_text')->default(true);  // false = scanned, OCR blocks
            $table->string('plate_pdf_path')->nullable();     // vector plate, private disk
            $table->string('plate_screen_path')->nullable();  // WebP/AVIF screen plate
            $table->timestamps();
            $table->unique(['book_id', 'number']);
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();                    // stable across re-analysis
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('source_pages')->cascadeOnDelete();
            $table->ulid('parent_id')->nullable()->index();   // the table/list a cell belongs to
            $table->string('kind', 20);                       // Block::KINDS
            $table->string('role', 20);                       // Block::ROLES
            $table->string('treatment', 20)->nullable();      // Block::TREATMENTS; null until classified
            $table->decimal('treatment_confidence', 4, 3)->nullable();
            $table->boolean('treatment_confirmed')->default(false);
            $table->json('bbox');
            $table->json('allowed_area')->nullable();         // where text may grow (Req 8.2 step 5)
            $table->unsignedSmallInteger('reading_order')->default(0);
            $table->json('style')->nullable();                // font, size, line, colour, align, weight, rotation
            $table->decimal('visual_size_factor', 5, 3)->nullable(); // Req 7.8: source vs profile x-height
            $table->text('source_text');
            $table->json('structure')->nullable();            // cell: row, col, rowspan, colspan, header
            $table->timestamps();
            $table->index(['page_id', 'reading_order']);
            $table->index('book_id');
        });

        // Separate step: PostgreSQL adds the primary key after the CREATE, so a self-reference
        // declared inside it would point at a key that does not exist yet.
        Schema::table('blocks', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('blocks')->nullOnDelete();
        });

        Schema::create('block_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->text('text');
            $table->text('alt_short_text')->nullable();       // shorter alternative (Req 8.2 step 6)
            $table->string('status', 20)->default('draft');   // BlockTranslation::STATUSES
            $table->string('source_hash', 64);                // hash of the block's source text when translated
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['translation_id', 'block_id']);
        });

        Schema::create('resolved_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_translation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('font_family', 80);
            $table->decimal('font_size', 6, 2);
            $table->decimal('line_height', 5, 3);
            $table->decimal('letter_spacing', 6, 3)->default(0);
            $table->json('box');                              // final box in points
            $table->unsignedTinyInteger('ladder_step');       // 1–7, Req 8.2
            $table->json('lines')->nullable();                // laid-out lines for reader and exports
            $table->boolean('fits');
            $table->timestamps();
        });

        Schema::create('page_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('source_pages')->cascadeOnDelete();
            $table->string('run_id', 64)->nullable();
            $table->json('results');                          // [{gate, passed, reason, block_id?}]
            $table->boolean('passed');
            $table->timestamps();
            $table->index(['translation_id', 'page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_checks');
        Schema::dropIfExists('resolved_layouts');
        Schema::dropIfExists('block_translations');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('source_pages');
    }
};
