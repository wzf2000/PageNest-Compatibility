<?php
/** Extract literal theme gettext calls without loading WordPress or theme code. */
$root = dirname(__DIR__);
$messages = [];
$add = static function ($id, $plural, $ref) use (&$messages) {
    $key = $id;
    if (isset($messages[$key]) && $messages[$key]['plural'] !== $plural) {
        throw new RuntimeException('Inconsistent plural for ' . $id);
    }
    $messages[$key] ??= ['id' => $id, 'plural' => $plural, 'references' => []];
    $messages[$key]['references'][] = $ref;
};
$literal = static function ($token) {
    if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        throw new RuntimeException('Gettext messages and domains must be literal strings.');
    }
    $raw = substr($token[1], 1, -1);
    return $token[1][0] === "'"
        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $raw)
        : stripcslashes($raw);
};

$files = glob($root . '/*.php');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules'));
foreach ($iterator as $entry) {
    if ($entry->isFile() && $entry->getExtension() === 'php') {
        $files[] = $entry->getPathname();
    }
}
foreach ($files as $file) {
    if (
        str_contains($file, '/modules/media/') &&
        !str_ends_with($file, '/authoring.php') &&
        !str_ends_with($file, '/quality.php') &&
        !str_ends_with($file, '/settings.php')
    ) {
        continue;
    }
    $tokens = array_values(
        array_filter(
            token_get_all(file_get_contents($file)),
            static fn($t) => !is_array($t) ||
                !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ),
    );
    foreach ($tokens as $i => $token) {
        if (
            !is_array($token) ||
            $token[0] !== T_STRING ||
            !in_array(
                $token[1],
                ['__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_n'],
                true,
            ) ||
            ($tokens[$i + 1] ?? '') !== '('
        ) {
            continue;
        }
        $args = [[]];
        $depth = 0;
        for ($j = $i + 2; $j < count($tokens); $j++) {
            $part = $tokens[$j];
            if ($part === ')' && $depth === 0) {
                break;
            }
            if (in_array($part, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($part, [')', ']', '}'], true)) {
                $depth--;
            }
            if ($part === ',' && $depth === 0) {
                $args[] = [];
            } else {
                $args[count($args) - 1][] = $part;
            }
        }
        $is_plural = $token[1] === '_n';
        $domain = $args[$is_plural ? 3 : 1] ?? [];
        if (
            count($domain) !== 1 ||
            $literal($domain[0]) !== 'pagenest-companion' ||
            count($args[0]) !== 1 ||
            ($is_plural && count($args[1]) !== 1)
        ) {
            throw new RuntimeException(
                'Invalid gettext call: ' . substr($file, strlen($root) + 1) . ':' . $token[2],
            );
        }
        if (!is_array($args[0][0]) || $args[0][0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            throw new RuntimeException('Nonliteral message in ' . $file . ':' . $token[2]);
        }
        $add(
            $literal($args[0][0]),
            $is_plural ? $literal($args[1][0]) : null,
            substr($file, strlen($root) + 1) . ':' . $token[2],
        );
    }
    if (preg_match('/Template Name:\s*(.+)/', file_get_contents($file), $match)) {
        $add(trim($match[1]), null, substr($file, strlen($root) + 1));
    }
}
ksort($messages);
foreach ($messages as &$message) {
    $message['references'] = array_values(array_unique($message['references']));
    sort($message['references']);
}
echo json_encode(
    array_values($messages),
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
);
