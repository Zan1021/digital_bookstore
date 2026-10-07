# Engine Wiring Audit (mechanical)

Entry points (invoked by PHP): ['page_manifest', 'pdf_translate_v8']
Totals: LIVE-IMPORT=15, LIVE-SUBPROC=11, TEST-ONLY=13, DEAD=23

| Tier | Module | Note |
|------|--------|------|
| LIVE-IMPORT | borderless_table |  |
| LIVE-IMPORT | document_model |  |
| LIVE-IMPORT | font_policy |  |
| LIVE-IMPORT | international_text |  |
| LIVE-IMPORT | page_manifest | engine entrypoint |
| LIVE-IMPORT | pdf_translate_v8 | engine entrypoint |
| LIVE-IMPORT | pdf_validation |  |
| LIVE-IMPORT | readability_policy |  |
| LIVE-IMPORT | render_gate |  |
| LIVE-IMPORT | rotated_text |  |
| LIVE-IMPORT | text_fit_solver |  |
| LIVE-IMPORT | text_shaping |  |
| LIVE-IMPORT | translation_compare |  |
| LIVE-IMPORT | translation_request |  |
| LIVE-IMPORT | universal_containers |  |
| LIVE-SUBPROC | cover_retypeset | gated:cover_retypeset.enabled via PdfTranslationService.php |
| LIVE-SUBPROC | cropmark_detection | via PdfService.php |
| LIVE-SUBPROC | font_resolver | via BookOnboarding.php,FontManager.php,PdfService.php |
| LIVE-SUBPROC | illustration_classify | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_genmask | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_genvalidate | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_measure | via IllustrationTextService.php |
| LIVE-SUBPROC | illustration_text | via IllustrationTextService.php |
| LIVE-SUBPROC | v8_advanced | via FontManager.php |
| LIVE-SUBPROC | visual_coverage | via BookTestingService.php |
| LIVE-SUBPROC | visual_coverage_cli | via BookTestingService.php |
| TEST-ONLY | artwork_repair |  |
| TEST-ONLY | content_cache |  |
| TEST-ONLY | content_stream_surgery |  |
| TEST-ONLY | corpus_fixtures |  |
| TEST-ONLY | crop_transform |  |
| TEST-ONLY | incremental_render |  |
| TEST-ONLY | inventory_layout |  |
| TEST-ONLY | make_gate_fixtures | [test_fixtures/make_gate_fixtures.py] |
| TEST-ONLY | ocr_integration |  |
| TEST-ONLY | page_inventory |  |
| TEST-ONLY | scene_graph |  |
| TEST-ONLY | script_detection |  |
| TEST-ONLY | security |  |
| DEAD | accessibility |  |
| DEAD | analyze_page2 |  |
| DEAD | audit_trail |  |
| DEAD | caption_detection |  |
| DEAD | capture_evidence |  |
| DEAD | font_registry |  |
| DEAD | get_translations |  |
| DEAD | glyph_preflight |  |
| DEAD | image_inpainting |  |
| DEAD | optical_calibration |  |
| DEAD | quality_gates |  |
| DEAD | raster_fallback |  |
| DEAD | render_book |  |
| DEAD | render_comparison |  |
| DEAD | render_page15_check |  |
| DEAD | render_page2_check |  |
| DEAD | show_qa_report |  |
| DEAD | text_verification |  |
| DEAD | translation_variants |  |
| DEAD | typography_fingerprint |  |
| DEAD | variable_fonts |  |
| DEAD | view_pdf |  |
| DEAD | wiring_audit |  |
