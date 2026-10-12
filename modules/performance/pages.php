<?php
namespace PageNest\Performance;
defined('ABSPATH') || exit();
final class Pages
{
    public static function origin(): string
    {
        $u = parse_url(home_url('/'));
        if (
            !$u ||
            ($u['scheme'] ?? '') !== 'https' ||
            empty($u['host']) ||
            isset($u['user']) ||
            isset($u['pass']) ||
            isset($u['query']) ||
            isset($u['fragment']) ||
            (isset($u['port']) && $u['port'] !== 443)
        ) {
            throw new \RuntimeException('invalid_origin');
        }
        return 'https://' . strtolower($u['host']);
    }
    public static function url(string $url): string
    {
        $u = parse_url($url);
        if (
            !$u ||
            ($u['scheme'] ?? '') !== 'https' ||
            !isset($u['host']) ||
            'https://' . strtolower($u['host']) !== self::origin() ||
            isset($u['user']) ||
            isset($u['pass']) ||
            isset($u['query']) ||
            isset($u['fragment']) ||
            (isset($u['port']) && $u['port'] !== 443)
        ) {
            throw new \RuntimeException('invalid_url');
        }
        $path = rawurldecode($u['path'] ?? '/');
        if (
            preg_match(
                '~[\\\\\x00-\x20]|(?:^|/)\.\.?(/|$)|(?:^|/)(?:wp-admin|wp-login\.php|wp-signup\.php|wp-activate\.php|wp-cron\.php|wp-comments-post\.php|wp-json|xmlrpc\.php|oauth|preview|callback|logout|login|register)(?:/|$)~i',
                $path,
            ) ||
            str_contains($path, '%') ||
            str_contains($path, '?') ||
            str_contains($path, '#')
        ) {
            throw new \RuntimeException('invalid_url');
        }
        return $url;
    }
    public static function resolve(string $key, string $reason = '固定选择'): array
    {
        $id = 0;
        $title = $key === 'home' ? '首页' : '博客';
        if ($key === 'home') {
            $id = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
            $url = home_url('/');
        } elseif ($key === 'blog') {
            $id = get_option('show_on_front') === 'page' ? (int) get_option('page_for_posts') : 0;
            if (get_option('show_on_front') === 'page' && !$id) {
                throw new \RuntimeException('missing_blog');
            }
            $url = $id ? get_permalink($id) : home_url('/');
        } elseif (preg_match('/^post:([1-9][0-9]*)$/D', $key, $m)) {
            $id = (int) $m[1];
            $url = get_permalink($id);
        } else {
            throw new \RuntimeException('invalid_selection');
        }
        if ($id) {
            $p = get_post($id);
            if (
                !$p ||
                !in_array($p->post_type, ['post', 'page'], true) ||
                $p->post_status !== 'publish' ||
                $p->post_password !== '' ||
                !is_post_publicly_viewable($p)
            ) {
                throw new \RuntimeException('nonpublic_page');
            }
            if (
                class_exists('PageNest\\Tools\\Registry') &&
                \PageNest\Tools\Registry::forPage($id)
            ) {
                $allowed = false;
                if (\PageNest\Tools\Registry::healthy()) {
                    foreach (\PageNest\Tools\Registry::indexEntries() as $tool) {
                        if ($tool['kind'] === 'internal' && $tool['binding']['post_id'] === $id) {
                            $allowed = true;
                            break;
                        }
                    }
                }
                if (!$allowed) {
                    throw new \RuntimeException('nonpublic_tool');
                }
            }
            $title = $p->post_title;
        }
        return [
            'key' => $key,
            'post_id' => $id,
            'url' => self::url((string) $url),
            'title' => mb_substr($title, 0, 200),
            'reason' => $reason,
        ];
    }
    public static function selection(array $keys): array
    {
        if (!$keys || count($keys) > 8) {
            throw new \RuntimeException('selection_limit');
        }
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new \RuntimeException('invalid_selection');
            }
        }
        $out = [];
        foreach (array_unique($keys) as $key) {
            $p = self::resolve($key);
            $out[$p['url']] = $p;
        }
        return array_values($out);
    }
    public static function suggested(): array
    {
        $out = [];
        $add = static function ($key, $reason) use (&$out) {
            try {
                $p = self::resolve($key, $reason);
                if (count($out) < 8) {
                    $out[$p['url']] = $p;
                }
            } catch (\Throwable $e) {
            }
        };
        $add('home', '站点首页');
        $add('blog', '文章列表');
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'has_password' => false,
            'numberposts' => 100,
            'orderby' => 'ID',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);
        usort($posts, static fn($a, $b) => strlen($a->post_content) <=> strlen($b->post_content));
        if ($posts) {
            $add(
                'post:' . $posts[(int) floor(count($posts) / 2)]->ID,
                '有限公开样本的正文长度中位页',
            );
        }
        foreach (
            [
                '~\$|<pre|<code|wp:code~i' => '公式或代码',
                '~<img|wp:image|\.pdf~i' => '图片或 PDF',
                '~目录|导航|wp:list|<ul~i' => '通用导览',
            ]
            as $pattern => $reason
        ) {
            foreach ($posts as $p) {
                if (preg_match($pattern, $p->post_content)) {
                    $add('post:' . $p->ID, $reason);
                    break;
                }
            }
        }
        if (class_exists('PageNest\\Tools\\Registry') && \PageNest\Tools\Registry::healthy()) {
            foreach (\PageNest\Tools\Registry::indexEntries() as $tool) {
                if ($tool['kind'] === 'internal') {
                    $add('post:' . $tool['binding']['post_id'], '健康公开工具注册表入口');
                }
            }
        }
        foreach ($posts as $p) {
            $add('post:' . $p->ID, '公开代表样本');
            if (count($out) >= 8) {
                break;
            }
        }
        return array_values($out);
    }
}
