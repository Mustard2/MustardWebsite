<?php
// /mustardui/  The MustardUI page used to live here; it is now the tool page /tools/mustardui/ (app/tools.php). Links to the old address
// (the add-on's own pages, add-ons of items) go there while that tool is listed.
require __DIR__ . '/app/_init.php';
$t = m3d_tool('mustardui');
if ($t && m3d_tool_visible($t)) m3d_redirect(m3d_tool_url('mustardui'), 301);
not_found();
