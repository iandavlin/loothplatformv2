<?php
declare(strict_types=1);
require __DIR__ . '/../../profile-app/src/HtmlSanitize.php';
use Looth\ProfileApp\HtmlSanitize;

$vectors = [
  // [label, input, must_NOT_contain (array), must_contain (array|null)]
  ['plain bold', '<p>Hello <strong>world</strong></p>', [], ['<strong>world</strong>']],
  ['script tag', '<p>hi</p><script>alert(1)</script>', ['<script', 'alert('], ['<p>hi</p>']],
  ['img onerror', '<img src=x onerror=alert(1)>', ['<img', 'onerror', 'alert('], null],
  ['event handler on allowed', '<p onclick="alert(1)">x</p>', ['onclick', 'alert('], ['<p>x</p>']],
  ['js href', '<a href="javascript:alert(1)">x</a>', ['javascript:', 'alert('], ['x']],
  ['js href w/ tab', "<a href=\"java\tscript:alert(1)\">x</a>", ['script:alert', 'alert('], ['x']],
  ['data href', '<a href="data:text/html,<script>alert(1)</script>">x</a>', ['data:', '<script'], ['x']],
  ['protocol-relative', '<a href="//evil.com">x</a>', ['//evil.com','href'], ['x']],
  ['ok http href', '<a href="https://example.com/p">x</a>', ['javascript'], ['href="https://example.com/p"', 'rel="noopener nofollow ugc"', 'target="_blank"']],
  ['mailto ok', '<a href="mailto:a@b.com">m</a>', [], ['href="mailto:a@b.com"']],
  ['relative ok', '<a href="/g/dmv-looths">x</a>', ['javascript'], ['href="/g/dmv-looths"']],
  ['style tag', '<style>body{display:none}</style><p>x</p>', ['<style','display:none'], ['<p>x</p>']],
  ['iframe', '<iframe src="https://evil"></iframe><p>x</p>', ['<iframe','evil'], ['<p>x</p>']],
  ['nested div/span unwrap', '<div class="x"><span style="color:red">keep <strong>me</strong></span></div>', ['<div','<span','class','style'], ['keep <strong>me</strong>']],
  ['svg onload', '<svg onload=alert(1)></svg><p>x</p>', ['<svg','onload','alert('], ['<p>x</p>']],
  ['allowed formatting', '<h2>T</h2><ul><li>a</li><li>b</li></ul><blockquote>q</blockquote><em>i</em><u>u</u><s>s</s>', [], ['<h2>T</h2>','<ul>','<li>a</li>','<blockquote>q</blockquote>','<em>i</em>','<u>u</u>','<s>s</s>']],
  ['entity-encoded script stays text', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', ['<script'], null],
  ['img inside link', '<a href="https://ok.com"><img src=x onerror=alert(1)>t</a>', ['<img','onerror'], ['t']],
  ['unicode body', '<p>café — señor 日本語</p>', [], ['café','señor','日本語']],
];

$fail = 0;
foreach ($vectors as [$label, $in, $bad, $good]) {
  $out = HtmlSanitize::chapterHtml($in);
  $probs = [];
  foreach ($bad as $b) if (stripos($out, $b) !== false) $probs[] = "LEAKED: $b";
  if ($good) foreach ($good as $g) if (strpos($out, $g) === false) $probs[] = "MISSING: $g";
  $ok = empty($probs);
  if (!$ok) $fail++;
  printf("[%s] %s\n    in:  %s\n    out: %s\n", $ok ? 'PASS' : 'FAIL', $label, $in, $out);
  foreach ($probs as $p) echo "    !! $p\n";
}
echo "\n" . ($fail ? "$fail FAILED" : "ALL PASS") . "\n";
exit($fail ? 1 : 0);
