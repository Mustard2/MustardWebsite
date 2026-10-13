<?php
// Admin → Gallery: the pictures of /gallery/ (added from the Images library or uploaded; caption, characters, date,
// visibility, full size link). Rules: app/gallery.php.
// The page is built by admin.js (initGallery) from the JSON below; changes go to save.php (kind "gallery").
require __DIR__ . '/_admin.php';
admin_require($M3D);

$payload = admin_gallery_payload();
$payload['still'] = m3d_get_text('still');

admin_start('Gallery', 'gallery');
?>
    <div class="admin-head">
      <div>
        <h1>Gallery</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="/gallery/" target="_blank">View Page ↗</a>
        <button type="button" class="btn btn-primary" data-g-add>+ Add Pictures</button>
      </div>
    </div>

    <div data-gadmin></div>

    <script type="application/json" id="gallery-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
