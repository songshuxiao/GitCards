<?php

declare(strict_types=1);

namespace TypechoPlugin\GitCards;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 卡片 HTML 渲染器
 *
 * 负责将仓库数据渲染为美观的 HTML 卡片，
 * 支持服务端完整渲染、懒加载占位和降级卡片三种模式。
 *
 * @package GitCards
 */
class Renderer
{
    /** @var string 星标图标 SVG (Octicons star) */
    private const ICON_STAR = '<svg class="gc-icon" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"/></svg>';

    /** @var string Fork 图标 SVG (Octicons repo-forked) */
    private const ICON_FORK = '<svg class="gc-icon" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M5 5.372v.878c0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75v-.878a2.25 2.25 0 111.5 0v.878a2.25 2.25 0 01-2.25 2.25h-1.5v2.128a2.251 2.251 0 11-1.5 0V8.5h-1.5A2.25 2.25 0 013.5 6.25v-.878a2.25 2.25 0 111.5 0zM5 3.25a.75.75 0 10-1.5 0 .75.75 0 001.5 0zm6.75.75a.75.75 0 100-1.5.75.75 0 000 1.5zm-3 8.75a.75.75 0 10-1.5 0 .75.75 0 001.5 0z"/></svg>';

    /** @var string 箭头图标 SVG (Octicons chevron-right) */
    private const ICON_ARROW = '<svg class="gc-icon" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M6.22 3.22a.75.75 0 011.06 0l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 010-1.06z"/></svg>';

    /** @var array<int,string> 平台名称映射 */
    private const PLATFORM_NAMES = [
        1 => 'GitHub',
        2 => 'Coding',
        3 => 'Gitee',
        4 => 'GitLab',
    ];

    /**
     * 渲染完整卡片（服务端模式）
     *
     * @param array<string,mixed> $data 仓库数据
     * @param int                 $type 平台编号
     * @return string
     */
    public function render(array $data, int $type): string
    {
        $platform = self::PLATFORM_NAMES[$type] ?? 'Git';
        $site     = (string) $type;

        $owner     = $this->esc($data['owner'] ?? '');
        $ownerUrl  = $this->esc($data['ownerUrl'] ?? '');
        $repo      = $this->esc($data['repo'] ?? '');
        $repoUrl   = $this->esc($data['repoUrl'] ?? '');
        $desc      = $this->esc($data['description'] ?? '');
        $homepage  = $this->esc($data['homepage'] ?? '');
        $stars     = $this->formatCount((int) ($data['stars'] ?? 0));
        $forks     = $this->formatCount((int) ($data['forks'] ?? 0));
        $iconStar  = self::ICON_STAR;
        $iconFork  = self::ICON_FORK;
        $iconArrow = self::ICON_ARROW;

        // 描述行（可能包含 homepage 链接）
        $descHtml = '';
        if ($desc !== '' || $homepage !== '') {
            $descContent = $desc;
            if ($homepage !== '') {
                $homepageDisplay = $this->shortenUrl($homepage);
                $descContent .= ($desc !== '' ? ' ' : '') . '<a href="' . $homepage
                    . '" target="_blank" rel="noopener noreferrer" class="homepage">' . $homepageDisplay . '</a>';
            }
            $descHtml = '<p class="desc">' . $descContent . '</p>';
        }

        return <<<HTML
<section class="gitcards-block" data-gitsite="{$site}">
    <div class="gitcard-body">
        <span class="gitcard-tag">{$platform}</span>
        <div class="gitcard-header">
            <a href="{$ownerUrl}" class="ownername" target="_blank" rel="noopener noreferrer">{$owner}/</a><a href="{$repoUrl}" class="reponame" target="_blank" rel="noopener noreferrer">{$repo}</a>
        </div>
        {$descHtml}
        <div class="gitdata">
            <span class="git-stars" title="Stars">{$iconStar}{$stars}</span>
            <span class="git-forks" title="Forks">{$iconFork}{$forks}</span>
            <a class="viewmore" href="{$repoUrl}" title="前往查看" target="_blank" rel="noopener noreferrer">{$iconArrow}</a>
        </div>
    </div>
</section>

HTML;
    }

    /**
     * 渲染懒加载占位卡片（客户端模式）
     *
     * @param int    $type 平台编号
     * @param string $url  仓库 URL
     * @return string
     */
    public function renderLazy(int $type, string $url): string
    {
        $platform = self::PLATFORM_NAMES[$type] ?? 'Git';
        $site     = (string) $type;
        $safeUrl  = $this->esc($url);

        return <<<HTML
<section class="gitcards-block gitcards-lazy" data-gitsite="{$site}" data-giturl="{$safeUrl}">
    <div class="gitcard-body">
        <span class="gitcard-tag">{$platform}</span>
        <div class="gitcard-loading">
            <div class="gc-spinner"></div>
        </div>
    </div>
</section>

HTML;
    }

    /**
     * 渲染降级卡片（API 获取失败时使用）
     *
     * @param int    $type 平台编号
     * @param string $url  仓库 URL
     * @return string
     */
    public function renderFallback(int $type, string $url): string
    {
        $platform = self::PLATFORM_NAMES[$type] ?? 'Git';
        $site     = (string) $type;
        $safeUrl  = $this->esc($url);
        $iconArrow = self::ICON_ARROW;

        // 尝试从 URL 提取 owner/repo 用于显示
        $display = $url;
        if (preg_match('#https?://[^/]+/([^/]+/[^/?#]+)#', $url, $m)) {
            $display = $m[1];
            $display = preg_replace('/\.git$/', '', $display);
        }
        $display = $this->esc($display);

        return <<<HTML
<section class="gitcards-block gitcards-fallback" data-gitsite="{$site}">
    <div class="gitcard-body">
        <span class="gitcard-tag">{$platform}</span>
        <div class="gitcard-header">
            <a href="{$safeUrl}" class="reponame" target="_blank" rel="noopener noreferrer">{$display}</a>
        </div>
        <div class="gitdata">
            <a class="viewmore" href="{$safeUrl}" title="前往查看" target="_blank" rel="noopener noreferrer">{$iconArrow}</a>
        </div>
    </div>
</section>

HTML;
    }

    // ========================================================================
    //  辅助方法
    // ========================================================================

    /**
     * HTML 转义
     *
     * @param string $value
     * @return string
     */
    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 格式化数字（千位转换为 k）
     *
     * @param int $count
     * @return string
     */
    private function formatCount(int $count): string
    {
        if ($count >= 1000) {
            $k = $count / 1000;
            // 整数显示为 "1k"，小数显示为 "1.2k"
            return $k === floor($k) ? $k . 'k' : number_format($k, 1) . 'k';
        }

        return (string) $count;
    }

    /**
     * 缩短 URL 用于显示
     *
     * @param string $url
     * @return string
     */
    private function shortenUrl(string $url): string
    {
        $url = preg_replace('#^https?://#', '', $url);
        $url = rtrim($url ?? '', '/');

        return $this->esc($url);
    }
}
