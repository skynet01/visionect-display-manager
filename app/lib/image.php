<?php
/**
 * Shared image helpers for the 1440x2560 greyscale e-ink frame.
 *
 * Which mode each existing caller should use in Phase 2:
 *   - ainews resizeToFrame($src, $dest, 1440, 2560)
 *       -> visionect_image_to_frame($src, $dest, ['mode' => 'contain', 'background' => 'black'])
 *          (no cropping, keeps speech bubbles; ainews letterboxes in BLACK today. It used quality 92;
 *          the default 82 is the review's recommended reduction.)
 *       Return false on failure; do NOT fall back to renaming the raw provider bytes.
 *   - comics convertToJpg($file) (GoComics strips, Dilbert, Far Side panels, URL imports)
 *       -> visionect_image_to_frame($tmpDownload, $file, ['mode' => 'fit_width'])
 *          (today it only converts, it never resizes; fit_width keeps native size and only
 *          scales DOWN to 1440 wide. Pass 'upscale' => true to force exact 1440 width.)
 *   - admin api.php process_uploaded_image() (gallery uploads)
 *       -> visionect_image_to_frame($tmpFile, $outFile, ['mode' => 'contain', 'width' => $fw, 'height' => $fh])
 *          (thumbnailImage(..., true, true) today = contain with white fill.)
 *   - 'cover' (fill the frame, centre-crop the overflow) has no current caller; it suits photos
 *     where cropping is acceptable.
 *
 * Every Imagick call is wrapped in ob_start()/ob_end_clean(): Imagick can leak binary data to
 * stdout, which corrupts Docker's JSON log.
 */

if (!function_exists('visionect_write_file_atomic')) {
    require_once __DIR__ . '/security.php';
}

/** Last error message from visionect_image_to_frame(), or null after a success. */
function visionect_image_last_error(?string $set = null, bool $reset = false): ?string
{
    static $last = null;
    if ($reset) {
        $last = null;
    } elseif ($set !== null) {
        $last = $set;
    }
    return $last;
}

/** True if $data is a decodable raster image (JPEG/PNG/GIF/WebP...) with non-zero dimensions. */
function visionect_is_image_blob(string $data): bool
{
    if ($data === '') {
        return false;
    }
    $info = @getimagesizefromstring($data);
    return is_array($info) && !empty($info[0]) && !empty($info[1]);
}

/**
 * Convert an image (file path or raw blob) to a greyscale JPEG sized for the frame and save it
 * atomically to $destPath. Returns false on any failure; $destPath is never left partial
 * (an existing file is kept untouched on failure).
 *
 * $opts:
 *   width      int    target width  (default 1440)
 *   height     int    target height (default 2560; ignored by fit_width)
 *   mode       string 'contain' (default): fit inside, letterbox with background, no crop
 *                     'cover':   fill the frame, centre-crop the overflow
 *                     'fit_width': scale to width, keep aspect, height free (comic strips)
 *   background string letterbox / alpha-flatten colour (default 'white')
 *   quality    int    JPEG quality 1-100 (default 82)
 *   upscale    bool   fit_width only: also enlarge narrower images (default false)
 */
function visionect_image_to_frame(string $srcPathOrBlob, string $destPath, array $opts = []): bool
{
    visionect_image_last_error(null, true);

    $width = max(1, (int)($opts['width'] ?? 1440));
    $height = max(1, (int)($opts['height'] ?? 2560));
    $mode = (string)($opts['mode'] ?? 'contain');
    $background = (string)($opts['background'] ?? 'white');
    $quality = min(100, max(1, (int)($opts['quality'] ?? 82)));
    $upscale = !empty($opts['upscale']);

    if (!in_array($mode, ['contain', 'cover', 'fit_width'], true)) {
        visionect_image_last_error('Unknown mode: ' . $mode);
        return false;
    }
    if ($srcPathOrBlob === '') {
        visionect_image_last_error('Empty source');
        return false;
    }

    $isPath = strlen($srcPathOrBlob) < 4096
        && strpos($srcPathOrBlob, "\0") === false
        && @is_file($srcPathOrBlob);

    $blob = null;
    ob_start();
    try {
        $src = new Imagick();
        if ($isPath) {
            $src->readImage($srcPathOrBlob . '[0]');
        } else {
            $src->readImageBlob($srcPathOrBlob);
        }
        // Keep only the first frame (animated GIF, multi-page TIFF/PDF).
        $src->setFirstIterator();
        $img = $src->getImage();
        $src->clear();

        // Flatten any transparency onto the background so it doesn't turn black in JPEG.
        $img->setImageBackgroundColor(new ImagickPixel($background));
        if ($img->getImageAlphaChannel()) {
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }

        $srcW = $img->getImageWidth();
        $srcH = $img->getImageHeight();
        if ($srcW < 1 || $srcH < 1) {
            throw new RuntimeException('Image has no dimensions');
        }

        if ($mode === 'cover') {
            $img->cropThumbnailImage($width, $height);
            $img->setImagePage(0, 0, 0, 0);
            $out = $img;
        } elseif ($mode === 'fit_width') {
            if ($srcW > $width || ($upscale && $srcW < $width)) {
                $img->thumbnailImage($width, 0);
            }
            $out = $img;
        } else {
            $img->thumbnailImage($width, $height, true);
            $canvas = new Imagick();
            $canvas->newImage($width, $height, new ImagickPixel($background));
            $offsetX = (int)(($width - $img->getImageWidth()) / 2);
            $offsetY = (int)(($height - $img->getImageHeight()) / 2);
            $canvas->compositeImage($img, Imagick::COMPOSITE_OVER, $offsetX, $offsetY);
            $img->clear();
            $out = $canvas;
        }

        $out->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $out->stripImage();
        $out->setImageFormat('jpeg');
        $out->setImageCompressionQuality($quality);
        $blob = $out->getImageBlob();
        $out->clear();
    } catch (Throwable $e) {
        $blob = null;
        visionect_image_last_error('Imagick error: ' . $e->getMessage());
    } finally {
        ob_end_clean();
    }

    if (!is_string($blob) || $blob === '' || !visionect_is_image_blob($blob)) {
        if (visionect_image_last_error() === null) {
            visionect_image_last_error('Conversion produced no image');
        }
        error_log('visionect_image_to_frame: ' . visionect_image_last_error());
        return false;
    }

    if (!visionect_write_file_atomic($destPath, $blob)) {
        visionect_image_last_error('Could not write ' . $destPath);
        return false;
    }

    return true;
}
