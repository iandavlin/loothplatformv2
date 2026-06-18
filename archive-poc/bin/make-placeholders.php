<?php
// Generates 600x400 PNG placeholders, one per kind, into web/placeholders/.
// One-shot. Plain PHP GD.

$out = __DIR__ . '/../web/placeholders';
if (!is_dir($out)) mkdir($out, 0775, true);

// Tasteful muted palette: bg + accent + label per kind.
$kinds = [
    'article'    => ['#2c3e50', '#ecf0f1', 'ARTICLE'],
    'video'      => ['#34495e', '#e67e22', 'VIDEO'],
    'loothprint' => ['#3a5a40', '#dad7cd', 'LOOTHPRINT'],
    'event'      => ['#5e548e', '#e0aaff', 'EVENT'],
    'discussion' => ['#264653', '#e9c46a', 'DISCUSSION'],
    'profile'    => ['#6d597a', '#f4a261', 'PROFILE'],
    'benefit'    => ['#1d3557', '#a8dadc', 'BENEFIT'],
    'misc'       => ['#495057', '#adb5bd', 'MISC'],
];

function hex(\GdImage $im, string $h): int {
    $h = ltrim($h, '#');
    return imagecolorallocate($im, hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2)));
}

foreach ($kinds as $k => [$bg, $accent, $label]) {
    $w = 600; $h = 400;
    $im = imagecreatetruecolor($w, $h);
    $c_bg = hex($im, $bg);
    $c_acc = hex($im, $accent);
    imagefilledrectangle($im, 0, 0, $w, $h, $c_bg);
    // soft diagonal stripe
    for ($i = -$h; $i < $w; $i += 40) {
        imagefilledpolygon($im, [$i,0, $i+20,0, $i+20+$h,$h, $i+$h,$h], $c_bg);
    }
    // accent bar
    imagefilledrectangle($im, 0, $h-12, $w, $h, $c_acc);
    // label (built-in font 5 is largest)
    $font = 5;
    $tw = imagefontwidth($font) * strlen($label);
    $th = imagefontheight($font);
    imagestring($im, $font, ($w - $tw) / 2, ($h - $th) / 2, $label, $c_acc);

    $path = "$out/$k.png";
    imagepng($im, $path);
    imagedestroy($im);
    echo "wrote $path\n";
}
