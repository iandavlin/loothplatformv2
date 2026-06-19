<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * ImageOptimize — cap + compress a profile image at WRITE time.
 *
 * Avatars/banners were stored RAW: a 5 MB upload sat as 5 MB, and a no-?w=
 * serve shipped the full original into a small slot. This bounds every stored
 * original — auto-orient, strip metadata, downscale to fit MAX_DIM, re-encode
 * WebP q82. Imagick (webp delegate) beats GD ~1.5x at the same quality
 * (a 587 KB png → 38 KB here, vs GD's 57 KB).
 *
 * Resilient: throws on un-decodable input so callers can fall back to the raw
 * bytes rather than drop the avatar. Pure Imagick — no other app deps, so it
 * loads standalone in the CLI backfill as well as the FPM upload handler.
 */
final class ImageOptimize
{
    public const MAX_DIM      = 512;   // an avatar never needs more
    public const WEBP_QUALITY = 82;

    /**
     * @return array{0:string,1:string} [webp bytes, 'webp']
     * @throws \Throwable on un-decodable input / empty output
     */
    public static function avatar(string $bytes): array
    {
        return self::toWebp($bytes, self::MAX_DIM);
    }

    /** Fit within $maxDim (never upscales), strip, encode WebP q82. */
    public static function toWebp(string $bytes, int $maxDim): array
    {
        if ($bytes === '') throw new \RuntimeException('empty input');

        $im = new \Imagick();
        $im->readImageBlob($bytes);

        // animated source → keep the first frame only
        if ($im->getNumberImages() > 1) {
            $im = $im->coalesceImages();
            $im->setFirstIterator();
        }

        try { $im->autoOrientImage(); } catch (\Throwable $e) { /* no exif / old imagick */ }
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
}
