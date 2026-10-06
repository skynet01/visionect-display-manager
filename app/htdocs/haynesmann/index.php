<?php
require_once (is_dir('/app/lib') ? '/app/lib' : dirname(__DIR__, 2) . '/lib') . '/gallery_page.php';
visionect_render_gallery_page('haynesmann', 'Hannes Beer', __DIR__);
