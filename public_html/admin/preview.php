<?php
// POST JSON { text } → { html }: the docs editor's preview, rendered exactly like the public page.
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);
$in = json_decode((string) file_get_contents('php://input'), true);
$html = md_html(clean_text($in['text'] ?? '', 200000), ['docs' => true], $toc);
admin_json(['ok' => true, 'html' => $html]);
