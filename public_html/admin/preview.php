<?php
// POST JSON { text, paywall } → { html }: the docs and blog editors' preview, rendered exactly like the public page (paywall: blog posts' "--- Patreon ---" bar).
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);
$in = json_decode((string) file_get_contents('php://input'), true);
$html = md_html(clean_text($in['text'] ?? '', 200000), ['docs' => true, 'paywall' => !empty($in['paywall'])], $toc);
admin_json(['ok' => true, 'html' => $html]);
