<?php

declare(strict_types=1);

namespace TypechoPlugin\GitCards;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 *
 * 在文章中通过短代码 [gitcard] 插入 Git 仓库信息卡片，
 * 支持 GitHub、Gitee、GitLab、Coding 平台。
 *
 * 原项目: https://github.com/daidr/gitCards (WordPress 插件)
 *
 * @package GitCards
 * @author  songshuxiao
 * @version 1.3.1
 * @link    https://github.com/songshuxiao/GitCards
 * @license MIT
 */
class Plugin implements PluginInterface
{
    /** @var string 插件版本号 */
    public const VERSION = '1.0.0';

    /** @var array<int,string> 平台编号 → 名称映射 */
    public const PLATFORMS = [
        1 => 'GitHub',
        2 => 'Coding',
        3 => 'Gitee',
        4 => 'GitLab',
    ];

    /**
     * 插件激活：注册钩子、创建缓存目录、添加快捷插入按钮
     *
     * @return string 激活提示消息
     */
    public static function activate()
    {
        // 内容过滤器（在 Markdown 渲染之后触发）
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = __CLASS__ . '::contentFilter';
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerpt   = __CLASS__ . '::excerptFilter';

        // 前端资源注入（CSS + 懒加载 JS）
        \Typecho\Plugin::factory('Widget\Archive')->header = __CLASS__ . '::headerInsert';
        \Typecho\Plugin::factory('Widget\Archive')->footer = __CLASS__ . '::footerInsert';

        // 编辑页快捷插入按钮（在“选项”侧边栏和底部输出 JS）
        \Typecho\Plugin::factory('admin/write-post.php')->option = [__CLASS__, 'renderOptionBlock'];
        \Typecho\Plugin::factory('admin/write-page.php')->option  = [__CLASS__, 'renderOptionBlock'];
        \Typecho\Plugin::factory('admin/footer.php')->end         = [__CLASS__, 'renderFooterJs'];

        // 创建缓存目录
        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
            // 写入 .htaccess 防止缓存文件被直接访问（兼容 Apache 2.2/2.4）
            $htaccess = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";
            @file_put_contents($cacheDir . '/.htaccess', $htaccess);
        }

        return _t('GitCards 插件已激活，可在文章中使用 [gitcard] 短代码插入 Git 仓库卡片，并已添加快捷插入按钮。');
    }

    /**
     * 插件停用：清理缓存文件
     */
    public static function deactivate()
    {
        $cacheDir = __DIR__ . '/cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @unlink($cacheDir . '/.htaccess');
            @rmdir($cacheDir);
        }
    }

    /**
     * 插件配置面板
     *
     * @param Form $form 配置表单
     */
    public static function config(Form $form)
    {
        $githubToken = new Text(
            'githubToken',
            null,
            '',
            'GitHub Personal Access Token',
            '用于提高 GitHub API 速率限制（匿名 60 次/小时，认证 5000 次/小时）。'
            . '留空则使用匿名访问。建议在 GitHub Settings → Developer settings → Personal access tokens 创建。'
        );
        $form->addInput($githubToken);

        $cacheTtl = new Text(
            'cacheTtl',
            null,
            '3600',
            '缓存有效期（秒）',
            'API 数据缓存时间，默认 3600 秒（1 小时）。设为 0 则禁用缓存（不推荐，可能导致 API 速率限制）。'
        );
        $form->addInput($cacheTtl);

        $renderMode = new Radio(
            'renderMode',
            [
                'server' => '服务端渲染（推荐，SEO 友好，数据缓存在服务器）',
                'lazy'   => '客户端懒加载（原版方式，需 JavaScript，数据在浏览器中获取）',
            ],
            'server',
            '渲染模式',
            '服务端渲染在服务器获取数据并缓存，页面加载即可见；'
            . '客户端懒加载在浏览器中通过 IntersectionObserver 懒加载，不阻塞页面渲染但需 JavaScript。'
        );
        $form->addInput($renderMode);

        $defaultPlatform = new Radio(
            'defaultPlatform',
            [
                'auto' => '自动检测（根据 URL 域名判断）',
                '1'    => 'GitHub',
                '2'    => 'Coding',
                '3'    => 'Gitee',
                '4'    => 'GitLab',
            ],
            'auto',
            '默认平台',
            '当短代码未指定 type 属性时的默认平台。'
        );
        $form->addInput($defaultPlatform);

        $apiTimeout = new Text(
            'apiTimeout',
            null,
            '5',
            'API 请求超时（秒）',
            '请求 Git 平台 API 的超时时间，默认 5 秒。超时后显示降级卡片。'
        );
        $form->addInput($apiTimeout);
    }

    /**
     * 个人配置（本插件不使用）
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    // ========================================================================
    //  钩子回调
    // ========================================================================

    /**
     * 内容过滤器：解析文章正文中的短代码
     *
     * @param string             $content 文章 HTML 内容
     * @param mixed              $widget  内容组件对象
     * @return string
     */
    public static function contentFilter($content, $widget = null): string
    {
        if (!is_string($content) || $content === '') {
            return is_string($content) ? $content : '';
        }

        $config   = self::getConfig();
        $parser   = new Parser();
        $fetcher  = new Fetcher($config);
        $renderer = new Renderer();

        $renderMode = $config['renderMode'] ?? 'server';

        $result = $parser->parse($content, function (array $atts, ?string $innerUrl) use ($config, $fetcher, $renderer, $renderMode): string {
            // 获取 URL：优先使用 url 属性，其次使用内嵌内容
            $url = $atts['url'] ?? $innerUrl ?? '';
            if ($url === '') {
                return '';
            }

            // 获取平台类型
            $type = self::resolveType($atts, $url, $config);

            if ($renderMode === 'lazy') {
                return $renderer->renderLazy($type, $url);
            }

            // 服务端渲染
            $data = $fetcher->fetch($type, $url);
            if ($data === null) {
                return $renderer->renderFallback($type, $url);
            }

            return $renderer->render($data, $type);
        });

        return $result;
    }

    /**
     * 摘要过滤器：将短代码替换为简单文本链接（避免在摘要中渲染完整卡片）
     *
     * @param string $content 摘要内容
     * @param mixed  $widget  内容组件对象
     * @return string
     */
    public static function excerptFilter($content, $widget = null): string
    {
        if (!is_string($content) || $content === '') {
            return is_string($content) ? $content : '';
        }

        $config = self::getConfig();
        $parser = new Parser();

        $result = $parser->parse($content, function (array $atts, ?string $innerUrl) use ($config): string {
            $url  = $atts['url'] ?? $innerUrl ?? '';
            $type = self::resolveType($atts, $url, $config);
            $name = self::PLATFORMS[$type] ?? 'Git';

            if ($url === '') {
                return '';
            }

            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">[' . $name . ']</a>';
        });

        return $result;
    }

    /**
     * 页头插入：输出 CSS 样式表
     *
     * @param mixed ...$args 钩子参数
     */
    public static function headerInsert(...$args): void
    {
        $cssUrl = self::assetUrl('assets/gitcards.css');
        echo '<link rel="stylesheet" href="' . $cssUrl . '">' . "\n";
    }

    /**
     * 页脚注入：懒加载 JavaScript
     *
     * @param mixed ...$args
     */
    public static function footerInsert(...$args): void
    {
        $config = self::getConfig();
        if (($config['renderMode'] ?? 'server') !== 'lazy') {
            return;
        }

        $jsUrl = self::assetUrl('assets/gitcards.js');
        echo '<script src="' . $jsUrl . '" defer></script>' . "\n";
    }

    // ========================================================================
    //  快捷插入按钮（编辑页功能）
    // ========================================================================

    /**
     * 在编辑页侧边栏“选项”中插入 HTML 区块
     *
     * @param mixed $post 文章/页面对象（未使用）
     */
    public static function renderOptionBlock($post): void
    {
        ?>
        <section class="typecho-post-option">
            <label class="typecho-label">GitCard 工具</label>
            <p>
                <button type="button" id="gitcard-insert-btn" class="btn btn-primary btn-xs">
                    插入 [gitcard url=""]
                </button>
            </p>
            <p class="description">点击插入短代码，光标自动定位。</p>
        </section>
        <?php
    }

    /**
     * 在编辑页底部输出 JavaScript，实现按钮点击插入短代码
     */
    public static function renderFooterJs(): void
    {
        $scriptName = basename($_SERVER['SCRIPT_NAME']);
        if (in_array($scriptName, ['write-post.php', 'write-page.php'])) {
            ?>
            <script>
            window.addEventListener('load', function() {
                console.log('GitCard: 脚本已加载，准备绑定按钮...');
                var btn = document.getElementById('gitcard-insert-btn');
                if (!btn) {
                    console.warn('GitCard: 未找到按钮 ID');
                    return;
                }
                btn.addEventListener('click', function() {
                    console.log('GitCard: 按钮被点击');
                    var textarea = document.getElementById('text');
                    if (!textarea) {
                        alert('错误：找不到文章编辑器 (#text)，无法插入代码。');
                        return;
                    }
                    var code = '[gitcard url=""]';
                    var startPos = textarea.selectionStart;
                    var endPos = textarea.selectionEnd;
                    var oldValue = textarea.value;
                    if (typeof startPos !== 'number' || typeof endPos !== 'number') {
                        startPos = endPos = oldValue.length;
                        console.log('GitCard: 无法获取光标，追加到末尾');
                    }
                    var newValue = oldValue.substring(0, startPos) + code + oldValue.substring(endPos, oldValue.length);
                    textarea.value = newValue;
                    var event = new Event('input', { bubbles: true });
                    textarea.dispatchEvent(event);
                    var newCursorPos = startPos + 15; 
                    textarea.focus();
                    if (typeof textarea.setSelectionRange === 'function') {
                        textarea.setSelectionRange(newCursorPos, newCursorPos);
                    }
                    console.log('GitCard: 代码插入成功，光标已移动');
                });
            });
            </script>
            <?php
        }
    }

    // ========================================================================
    //  辅助方法
    // ========================================================================

    /**
     * 解析平台类型
     *
     * @param array<string,string> $atts   短代码属性
     * @param string               $url    仓库 URL
     * @param array<string,mixed>  $config 插件配置
     * @return int 平台编号
     */
    private static function resolveType(array $atts, string $url, array $config): int
    {
        // 优先使用短代码中的 type 属性
        if (isset($atts['type'])) {
            $type = (int) $atts['type'];
            if (isset(self::PLATFORMS[$type])) {
                return $type;
            }
        }

        // 其次使用配置中的默认平台
        $default = $config['defaultPlatform'] ?? 'auto';
        if ($default !== 'auto') {
            $type = (int) $default;
            if (isset(self::PLATFORMS[$type])) {
                return $type;
            }
        }

        // 自动检测
        return self::detectPlatform($url);
    }

    /**
     * 获取插件配置（带默认值）
     *
     * @return array<string,mixed>
     */
    private static function getConfig(): array
    {
        $defaults = [
            'githubToken'     => '',
            'cacheTtl'        => '3600',
            'renderMode'      => 'server',
            'defaultPlatform' => 'auto',
            'apiTimeout'      => '5',
        ];

        try {
            $options = Options::alloc();
            $saved   = $options->plugin('GitCards');
            foreach ($defaults as $key => $default) {
                $defaults[$key] = $saved->{$key} ?? $default;
            }
        } catch (\Throwable $e) {
            // 配置未保存时使用默认值
        }

        return $defaults;
    }

    /**
     * 获取插件静态资源 URL
     *
     * @param string $relative 资源相对路径
     * @return string
     */
    private static function assetUrl(string $relative): string
    {
        try {
            $options   = Options::alloc();
            $pluginUrl = rtrim($options->pluginUrl, '/');
        } catch (\Throwable $e) {
            $pluginUrl = '/usr/plugins';
        }

        return $pluginUrl . '/GitCards/' . ltrim($relative, '/');
    }

    /**
     * 根据 URL 自动检测 Git 平台
     *
     * @param string $url 仓库 URL
     * @return int 平台编号
     */
    public static function detectPlatform(string $url): int
    {
        $url = strtolower($url);

        if (strpos($url, 'github.com') !== false) {
            return 1;
        }
        if (strpos($url, 'gitee.com') !== false) {
            return 3;
        }
        if (strpos($url, 'gitlab.com') !== false || strpos($url, 'gitlab.') !== false) {
            return 4;
        }
        if (strpos($url, 'coding.net') !== false
            || strpos($url, 'tencent.com') !== false
            || strpos($url, 'dev.tencent') !== false) {
            return 2;
        }

        return 1; // 默认 GitHub
    }
}
