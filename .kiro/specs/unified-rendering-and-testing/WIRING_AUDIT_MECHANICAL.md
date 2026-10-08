# Engine Wiring Audit (mechanical)

Entry points (invoked by PHP): ['page_manifest', 'pdf_translate_v8']
Totals: LIVE-IMPORT=20, LIVE-SUBPROC=16, TEST-ONLY=10, DEAD=17

| Tier | Module | Note |
|------|--------|------|
| LIVE-IMPORT | artwork_repair | transitively imported by a live module |
| LIVE-IMPORT | borderless_table | transitively imported by a live module |
| LIVE-IMPORT | caption_detection | transitively imported by a live module |
| LIVE-IMPORT | crop_transform | transitively imported by a live module |
| LIVE-IMPORT | document_model | transitively imported by a live module |
| LIVE-IMPORT | font_policy | transitively imported by a live module |
| LIVE-IMPORT | font_registry | transitively imported by a live module |
| LIVE-IMPORT | glyph_preflight | transitively imported by a live module |
| LIVE-IMPORT | image_inpainting | transitively imported by a live module |
| LIVE-IMPORT | international_text | transitively imported by a live module |
| LIVE-IMPORT | ocr_integration | transitively imported by a live module |
| LIVE-IMPORT | pdf_validation | transitively imported by a live module |
| LIVE-IMPORT | readability_policy | transitively imported by a live module |
| LIVE-IMPORT | render_gate | transitively imported by a live module |
| LIVE-IMPORT | rotated_text | transitively imported by a live module |
| LIVE-IMPORT | text_fit_solver | transitively imported by a live module |
| LIVE-IMPORT | text_shaping | transitively imported by a live module |
| LIVE-IMPORT | translation_compare | transitively imported by a live module |
| LIVE-IMPORT | translation_request | transitively imported by a live module |
| LIVE-IMPORT | universal_containers | transitively imported by a live module |
| LIVE-SUBPROC | accessibility | gated:accessibility.enabled via PdfTranslationService.php |
| LIVE-SUBPROC | cover_retypeset | gated:cover_retypeset.enabled via PdfTranslationService.php |
| LIVE-SUBPROC | cropmark_detection | via PdfService.php |
| LIVE-SUBPROC | font_integrity | via PdfTranslationService.php |
| LIVE-SUBPROC | font_resolver | via BookOnboarding.php,FontManager.php,PdfService.php |
| LIVE-SUBPROC | illustration_classify | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_genmask | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_genvalidate | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_measure | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_text | via IllustrationTextService.php |
| LIVE-SUBPROC | page_manifest | via PdfService.php,PdfTranslationService.php |
| LIVE-SUBPROC | pdf_translate_v8 | via PdfTranslationService.php |
| LIVE-SUBPROC | text_verification | gated:text_layer.enabled via PdfTranslationService.php |
| LIVE-SUBPROC | v8_advanced | via FontManager.php |
| LIVE-SUBPROC | visual_coverage | via BookTestingService.php |
| LIVE-SUBPROC | visual_coverage_cli | via BookTestingService.php |
| TEST-ONLY | content_cache |  |
| TEST-ONLY | content_stream_surgery |  |
| TEST-ONLY | corpus_fixtures |  |
| TEST-ONLY | incremental_render |  |
| TEST-ONLY | inventory_layout |  |
| TEST-ONLY | make_gate_fixtures | [test_fixtures/make_gate_fixtures.py] |
| TEST-ONLY | page_inventory |  |
| TEST-ONLY | scene_graph |  |
| TEST-ONLY | script_detection |  |
| TEST-ONLY | security |  |
| DEAD | analyze_page2 |  |
| DEAD | audit_trail |  |
| DEAD | capture_evidence |  |
| DEAD | get_translations |  |
| DEAD | optical_calibration |  |
| DEAD | quality_gates |  |
| DEAD | raster_fallback |  |
| DEAD | render_book |  |
| DEAD | render_comparison |  |
| DEAD | render_page15_check |  |
| DEAD | render_page2_check |  |
| DEAD | show_qa_report |  |
| DEAD | translation_variants |  |
| DEAD | typography_fingerprint |  |
| DEAD | variable_fonts |  |
| DEAD | view_pdf |  |
| DEAD | wiring_audit |  |
