<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * ImageOptimize — cap + compress a profile image at WRITE time.
 *
 * Images were stored RAW: a 5 MB upload sat as 5 MB, and a no-?w= serve shipped
 * the full original into a small slot. This bounds every stored original —
 * auto-orient, strip metadata, downscale to fit a max dimension, re-encode WebP
 * q82. Imagick (webp delegate) beats GD ~1.5x at the same quality (a 587 KB png
 * -> 38 KB here, vs GD's 57 KB).
 *
 * Resilient: throws on un-decodable input so callers can fall back to the raw
 * bytes rather than drop the image. Pure Imagick — no other app deps, so it
 * loads standalone in the CLI backfill as well as the FPM upload handler.
 *
 * ORIENTATION (the mobile "sideways photo" bug): phone JPEGs carry an EXIF
 * orientation flag instead of rotating pixels. We bake that rotation into the
 * pixels BEFORE stripping metadata, so the stored image is upright for every
 * client. We do NOT use Imagick::autoOrientImage() — it is an UNDEFINED METHOD
 * on the IM 6.9 / Imagick 3.7 build these boxes run (calling it throws
 * "Call to undefined method", which a swallow-all catch silently turned into a
 * no-op — that was the live bug). applyOrientation() reproduces it with the
 * rotate/flop primitives that DO exist on 6.9, so it is version-independent.
 */
final class ImageOptimize
{
    public const MAX_DIM         = 512;    // an avatar never needs more
    public const GALLERY_MAX_DIM = 1600;   // matches the serve resizer's top ?w= bucket
    public const BANNER_MAX_DIM  = 1600;   // wide hero — same top bucket as the gallery
    public const WEBP_QUALITY    = 82;

    /**
     * @return array{0:string,1:string} [webp bytes, 'webp']
     * @throws \Throwable on un-decodable input / empty output
     */
    public static function avatar(string $bytes): array
    {
        return self::toWebp($bytes, self::MAX_DIM);
    }

    /**
     * Gallery photo: same pipeline as an avatar but a larger cap (the carousel
     * hero is served at up to 1600px). Stored upright + compressed so the raw
     * original is never the multi-MB phone capture.
     *
     * @return array{0:string,1:string} [webp bytes, 'webp']
     * @throws \Throwable on un-decodable input / empty output
     */
    public static function gallery(string $bytes): array
    {
        return self::toWebp($bytes, self::GALLERY_MAX_DIM);
    }

    /**
     * Banner (wide hero strip): same cap+orient+compress as the gallery. A phone
     * shot dropped in as a banner was stored at full size and EXIF-rotated.
     *
     * @return array{0:string,1:string} [webp bytes, 'webp']
     * @throws \Throwable on un-decodable input / empty output
     */
    public static function banner(string $bytes): array
    {
        return self::toWebp($bytes, self::BANNER_MAX_DIM);
    }

    /** Fit within $maxDim (never upscales), auto-orient, strip, encode WebP q82. */
    public static function toWebp(string $bytes, int $maxDim): array
    {
        if ($bytes === '') throw new \RuntimeException('empty input');

        $im = new \Imagick();
        $im->readImageBlob($bytes);

        // animated source -> keep the first frame only
        if ($im->getNumberImages() > 1) {
            $im = $im->coalesceImages();
            $im->setFirstIterator();
        }

        // Bake EXIF orientation into the pixels BEFORE measuring/strip (so the
        // fit math below uses display dimensions and the stored image is upright).
        self::applyOrientation($im);

        $im->setImageBackgroundColor(new \ImagickPixel('white'));
        $im->stripImage();

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w > $maxDim || $h > $maxDim) {
            $im->thumbnailImage($maxDim, $maxDim, true);   // bestfit, no upscale
        }

        $im->setImageFormat('webp');
        $im->setOption('webp:method', '6');
        $im->setImageCompressionQuality(self::WEBP_QUALITY);
        $out = $im->getImageBlob();
        $im->clear();
        $im->destroy();

        if ($out === '') throw new \RuntimeException('empty optimize output');
        return [$out, 'webp'];
    }

    /**
     * Rotate/flip pixels to match the EXIF orientation flag, then reset the flag
     * to TOPLEFT. Hand-rolled because Imagick::autoOrientImage() does not exist
     * on the IM 6.9 build here. The eight EXIF orientation cases, mapped to the
     * rotate/flop primitives that 6.9 ships.
     */
    private static function applyOrientation(\Imagick $im): void
    {
        try {
            $orientation = $im->getImageOrientation();
        } catch (\Throwable $e) {
            return; // no readable orientation -> nothing to do
        }

        $bg = new \ImagickPixel('white');
        switch ($orientation) {
            case \Imagick::ORIENTATION_TOPRIGHT:    // 2
                $im->flopImage();
                break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT: // 3
                $im->rotateImage($bg, 180);
                break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:  // 4
                $im->flopImage();
                $im->rotateImage($bg, 180);
                break;
            case \Imagick::ORIENTATION_LEFTTOP:     // 5
                $im->flopImage();
                $im->rotateImage($bg, 90);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:    // 6 (the common phone-portrait case)
                $im->rotateImage($bg, 90);
                break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM: // 7
                $im->flopImage();
                $im->rotateImage($bg, 270);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:  // 8
                $im->rotateImage($bg, 270);
                break;
            default:                                 // 1 / undefined -> already upright
                return;
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }
}
