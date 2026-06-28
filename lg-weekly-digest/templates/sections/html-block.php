<?php
/**
 * Section template: HTML Block (WYSIWYG message)
 * Renders user-supplied HTML content with inline styles matching the email design.
 * Sanitized via wp_kses with email-safe tags.
 *
 * Variables: $item (array with 'html_content' key), $settings (array)
 */
defined( 'ABSPATH' ) || exit;

$html = $item['html_content'] ?? '';
if ( ! $html ) return;

// Allowed HTML tags safe for email clients
$allowed = [
    'p'      => [ 'style' => [] ],
    'div'    => [ 'style' => [] ],
    'span'   => [ 'style' => [] ],
    'strong' => [ 'style' => [] ],
    'b'      => [],
    'em'     => [ 'style' => [] ],
    'i'      => [],
    'a'      => [ 'href' => [], 'style' => [], 'target' => [] ],
    'br'     => [],
    'ul'     => [ 'style' => [] ],
    'ol'     => [ 'style' => [] ],
    'li'     => [ 'style' => [] ],
    'h1'     => [ 'style' => [] ],
    'h2'     => [ 'style' => [] ],
    'h3'     => [ 'style' => [] ],
    'h4'     => [ 'style' => [] ],
    'img'    => [ 'src' => [], 'alt' => [], 'width' => [], 'height' => [], 'style' => [] ],
    'table'  => [ 'width' => [], 'cellpadding' => [], 'cellspacing' => [], 'border' => [], 'style' => [], 'align' => [] ],
    'tr'     => [ 'style' => [] ],
    'td'     => [ 'width' => [], 'style' => [], 'valign' => [], 'align' => [], 'colspan' => [] ],
    'th'     => [ 'width' => [], 'style' => [], 'valign' => [], 'align' => [], 'colspan' => [] ],
    'hr'     => [ 'style' => [] ],
    'blockquote' => [ 'style' => [] ],
];

$content = wp_kses( $html, $allowed );

// Apply inline styles to match the email's typography
// Paragraphs
$content = preg_replace(
    '/<p(?![^>]*style)/',
    '<p style="font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#5C4E3A;line-height:1.65;margin:0 0 12px;"',
    $content
);
// Links
$content = preg_replace(
    '/<a(?![^>]*style)/',
    '<a style="color:#87986A;font-weight:600;text-decoration:none;"',
    $content
);
// Headings
$content = preg_replace(
    '/<h2(?![^>]*style)/',
    '<h2 style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:700;color:#2B2318;margin:0 0 8px;line-height:1.35;"',
    $content
);
$content = preg_replace(
    '/<h3(?![^>]*style)/',
    '<h3 style="font-family:Georgia,\'Times New Roman\',serif;font-size:17px;font-weight:700;color:#2B2318;margin:0 0 6px;line-height:1.35;"',
    $content
);
// Lists
$content = preg_replace(
    '/<ul(?![^>]*style)/',
    '<ul style="font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#5C4E3A;line-height:1.65;margin:0 0 12px;padding-left:20px;"',
    $content
);
$content = preg_replace(
    '/<ol(?![^>]*style)/',
    '<ol style="font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#5C4E3A;line-height:1.65;margin:0 0 12px;padding-left:20px;"',
    $content
);
// Blockquotes
$content = preg_replace(
    '/<blockquote(?![^>]*style)/',
    '<blockquote style="font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#5C4E3A;line-height:1.65;margin:0 0 12px;padding:10px 20px;border-left:3px solid #ECB351;background:#FAF6EE;"',
    $content
);
// Images: make responsive
$content = preg_replace(
    '/<img(?![^>]*style)/',
    '<img style="max-width:100%;height:auto;border-radius:6px;display:block;"',
    $content
);
?>
<div style="margin-bottom:12px;">
  <?php echo $content; ?>
</div>
