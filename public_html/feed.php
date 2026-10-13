<?php
// The RSS feed was removed (Discord announcements replaced it). This file stays so that sites updated from an older version,
// where the updater left the old feed.php in place, answer /feed.php with the 404 page too.
require __DIR__ . '/app/_init.php';
not_found();
