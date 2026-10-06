<?php
require_once (is_dir('/app/lib') ? '/app/lib' : dirname(__DIR__, 2) . '/lib') . '/gallery_page.php';
visionect_render_gallery_js_page('Art', __DIR__);
