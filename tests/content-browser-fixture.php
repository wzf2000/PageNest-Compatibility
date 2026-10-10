<?php
$argv[] = '--fixture';
require __DIR__ . '/content-contracts.php';
$out = dirname(__DIR__) . '/.runtime/content';
@mkdir($out . '/assets', 0777, true);
foreach (['content.css', 'content.js', 'content-spacing.js'] as $asset) {
    copy(dirname(__DIR__) . '/assets/' . $asset, $out . '/assets/' . $asset);
}
foreach ($GLOBALS['fixture_content'] as $name => $content) {
    if ($name === 'chapter') {
        $content = str_replace(
            '</div>',
            '<p id="spacing" data-pagenest-block="b-spacing" title="中文API">中文API与<strong>HTTP</strong>连接<span class="pagenest-paragraph-bubble">中文API</span></p><p id="tex">中文 $中A$ 与 \\(中B\\) \\[中C\\]</p><p id="split-tex">中文 $中<strong>A</strong>$</p><pre id="code"><code>中文API</code></pre></div>',
            $content,
        );
    }
    $html =
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetic ' .
        $name .
        '</title><style>body{margin:0;font:16px/1.7 sans-serif;color:#263d4d}main{box-sizing:border-box;max-width:900px;margin:0 auto;padding:20px}main *{box-sizing:border-box}h2{margin-top:40px}a{color:#245c80}pre{max-width:100%;overflow:auto}button{font:inherit}table{border-collapse:collapse}td{padding:10px;border:1px solid #ccc}</style><link rel="stylesheet" href="/assets/content.css"></head><body><main>' .
        $content .
        '</main><script src="/assets/content.js"></script><script src="/assets/content-spacing.js"></script></body></html>';
    file_put_contents($out . '/' . $name . '.html', $html);
}
echo "Synthetic content browser fixtures ready\n";
