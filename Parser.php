<?php

declare(strict_types=1);

namespace TypechoPlugin\GitCards;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 短代码解析器
 *
 * 负责在文章 HTML 内容中查找 [gitcard] 短代码，
 * 提取属性和内嵌 URL，通过回调函数生成替换内容。
 *
 * 支持的短代码格式：
 *   [gitcard type="1" url="https://github.com/user/repo"]
 *   [gitcard type="1" url="https://github.com/user/repo"/]
 *   [gitcard type="1"]https://github.com/user/repo[/gitcard]
 *   [gitcard url="https://github.com/user/repo"]  （type 自动检测）
 *
 * @package GitCards
 */
class Parser
{
    /** @var string 短代码标签名 */
    private const TAG = 'gitcard';

    /**
     * 解析内容中的所有短代码并替换
     *
     * @param string   $content  文章 HTML 内容
     * @param callable $callback 回调函数签名: function(array $atts, ?string $innerUrl): string
     * @return string 替换后的内容
     */
    public function parse(string $content, callable $callback): string
    {
        if (strpos($content, '[' . self::TAG) === false) {
            return $content;
        }

        // 第一步：移除包裹短代码的 <p> 标签（Markdown 可能将短代码包裹在段落中）
        $content = $this->unwrapParagraphs($content);

        // 第二步：匹配并替换短代码
        // 匹配两种格式：
        //   1. 自闭合: [gitcard attrs] 或 [gitcard attrs/]
        //   2. 包裹式: [gitcard attrs]inner content[/gitcard]
        $pattern = '/\[' . self::TAG . '(\s+[^\]]*)?\](?:(\/)|([^\[]+)\[\/' . self::TAG . '\])?/i';

        $result = preg_replace_callback($pattern, function (array $matches) use ($callback): string {
            $attrStr   = $matches[1] ?? '';
            $selfClose = !empty($matches[2]);
            $innerUrl  = isset($matches[3]) ? trim($matches[3]) : null;

            $atts = $this->parseAttributes($attrStr);

            return $callback($atts, $innerUrl);
        }, $content);

        return $result ?? $content;
    }

    /**
     * 移除包裹短代码的 <p> 标签
     *
     * Markdown 渲染器可能将独立的短代码行包裹在 <p></p> 中，
     * 这会导致卡片上下出现空段落。此方法将这类 <p> 标签移除。
     *
     * @param string $content HTML 内容
     * @return string
     */
    private function unwrapParagraphs(string $content): string
    {
        // 匹配 <p>[gitcard...]</p> 和 <p>[gitcard...]...[/gitcard]</p>
        $pattern = '/<p>\s*(\[gitcard[^\]]*\](?:[^\[]*\[\/gitcard\])?)\s*<\/p>/i';

        return preg_replace($pattern, '$1', $content) ?? $content;
    }

    /**
     * 解析短代码属性字符串
     *
     * 支持双引号和单引号包裹的属性值：
     *   type="1" url="https://..." title='My Repo'
     *
     * @param string $attrStr 属性字符串
     * @return array<string,string> 属性键值对
     */
    private function parseAttributes(string $attrStr): array
    {
        $atts = [];

        if (trim($attrStr) === '') {
            return $atts;
        }

        // 匹配 key="value" 或 key='value' 格式
        $pattern = '/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/';

        if (preg_match_all($pattern, $attrStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $key       = strtolower($m[1]);
                $atts[$key] = $m[2] ?? ($m[3] ?? '');
            }
        }

        return $atts;
    }
}
