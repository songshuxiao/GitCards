/*!
 * GitCards — Typecho Plugin
 * 懒加载脚本（客户端渲染模式）
 *
 * 使用 IntersectionObserver 在卡片进入视口时从 Git 平台 API
 * 获取仓库数据并渲染卡片内容。
 *
 * 原项目: https://github.com/daidr/gitCards
 * @license MIT
 */
(function () {
    'use strict';

    /** 平台编号 → 名称 */
    var PLATFORMS = { 1: 'GitHub', 2: 'Coding', 3: 'Gitee', 4: 'GitLab' };

    /** SVG 图标 */
    var ICONS = {
        star: '<svg class="gc-icon" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z"/></svg>',
        fork: '<svg class="gc-icon" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M5 5.372v.878c0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75v-.878a2.25 2.25 0 111.5 0v.878a2.25 2.25 0 01-2.25 2.25h-1.5v2.128a2.251 2.251 0 11-1.5 0V8.5h-1.5A2.25 2.25 0 013.5 6.25v-.878a2.25 2.25 0 111.5 0zM5 3.25a.75.75 0 10-1.5 0 .75.75 0 001.5 0zm6.75.75a.75.75 0 100-1.5.75.75 0 000 1.5zm-3 8.75a.75.75 0 10-1.5 0 .75.75 0 001.5 0z"/></svg>',
        arrow: '<svg class="gc-icon" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M6.22 3.22a.75.75 0 011.06 0l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 010-1.06z"/></svg>'
    };

    /**
     * HTML 转义
     * @param {string} str
     * @return {string}
     */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * 格式化数字（千位转 k）
     * @param {number} count
     * @return {string}
     */
    function formatCount(count) {
        if (count >= 1000) {
            var k = count / 1000;
            return (k === Math.floor(k) ? k : k.toFixed(1)) + 'k';
        }
        return String(count);
    }

    /**
     * 从 URL 解析 owner/repo
     * @param {string} url
     * @return {{owner: string, repo: string}|null}
     */
    function parseUrl(url) {
        var match = url.match(/\/([^\/]+)\/([^\/?#]+?)(?:\.git)?\/?(?:[?#].*)?$/);
        if (!match) return null;
        return { owner: match[1], repo: match[2] };
    }

    /**
     * 从各平台 API 获取仓库数据
     * @param {number} type 平台编号
     * @param {string} url  仓库 URL
     * @return {Promise<Object|null>}
     */
    function fetchRepo(type, url) {
        var parsed = parseUrl(url);
        if (!parsed) return Promise.resolve(null);

        var apiUrl;
        var headers = { 'Accept': 'application/json' };

        switch (type) {
            case 1: // GitHub
                apiUrl = 'https://api.github.com/repos/' + encodeURIComponent(parsed.owner) + '/' + encodeURIComponent(parsed.repo);
                break;
            case 3: // Gitee
                apiUrl = 'https://gitee.com/api/v5/repos/' + encodeURIComponent(parsed.owner) + '/' + encodeURIComponent(parsed.repo);
                break;
            case 4: // GitLab
                apiUrl = 'https://gitlab.com/api/v4/projects/' + encodeURIComponent(parsed.owner + '/' + parsed.repo);
                break;
            case 2: // Coding (third-party API, may be unavailable)
                apiUrl = 'https://codingapi.daidr.me/api/user/' + encodeURIComponent(parsed.owner) + '/project/' + encodeURIComponent(parsed.repo);
                break;
            default:
                return Promise.resolve(null);
        }

        return fetch(apiUrl, { headers: headers })
            .then(function (resp) {
                if (!resp.ok) return null;
                return resp.json();
            })
            .then(function (json) {
                if (!json) return null;
                return normalizeData(type, json, parsed, url);
            })
            .catch(function () { return null; });
    }

    /**
     * 将各平台 API 响应统一为标准格式
     * @param {number} type
     * @param {Object} json
     * @param {Object} parsed
     * @param {string} url
     * @return {Object|null}
     */
    function normalizeData(type, json, parsed, url) {
        try {
            switch (type) {
                case 1: // GitHub
                    return {
                        owner: json.owner.login,
                        ownerUrl: json.owner.html_url,
                        repo: json.name,
                        repoUrl: json.html_url,
                        description: json.description || '',
                        homepage: json.homepage || '',
                        stars: json.stargazers_count || 0,
                        forks: json.forks_count || 0
                    };
                case 3: // Gitee
                    return {
                        owner: json.owner.login,
                        ownerUrl: json.owner.html_url,
                        repo: json.path || json.name,
                        repoUrl: json.html_url,
                        description: json.description || '',
                        homepage: json.homepage || '',
                        stars: json.stargazers_count || 0,
                        forks: json.forks_count || 0
                    };
                case 4: // GitLab
                    return {
                        owner: json.namespace.path || json.namespace.name,
                        ownerUrl: json.web_url.replace(/\/[^\/]+$/, ''),
                        repo: json.path,
                        repoUrl: json.web_url,
                        description: json.description || '',
                        homepage: json.web_url,
                        stars: json.star_count || 0,
                        forks: json.forks_count || 0
                    };
                case 2: // Coding
                    var data = json.data || json;
                    return {
                        owner: data.owner_user_name || parsed.owner,
                        ownerUrl: url.replace(/\/[^\/]+$/, ''),
                        repo: data.display_name || parsed.repo,
                        repoUrl: data.https_url || url,
                        description: data.description || '',
                        homepage: data.https_url || url,
                        stars: data.star_count || 0,
                        forks: data.fork_count || 0
                    };
            }
        } catch (e) {
            return null;
        }
        return null;
    }

    /**
     * 渲染卡片内容
     * @param {Element} block 卡片容器
     * @param {Object} data   仓库数据
     * @param {number} type   平台编号
     */
    function renderCard(block, data, type) {
        var platform = PLATFORMS[type] || 'Git';
        var descHtml = '';

        if (data.description) {
            var desc = escapeHtml(data.description);
            if (data.homepage) {
                var homepage = escapeHtml(data.homepage);
                desc += ' <a href="' + homepage + '" target="_blank" rel="noopener noreferrer">' + homepage + '</a>';
            }
            descHtml = '<p class="desc">' + desc + '</p>';
        }

        block.classList.remove('gitcards-lazy');
        block.innerHTML =
            '<div class="gitcard-body">' +
            '<span class="gitcard-tag">' + platform + '</span>' +
            '<div class="gitcard-header">' +
            '<a href="' + escapeHtml(data.ownerUrl) + '" class="ownername" target="_blank" rel="noopener noreferrer">' + escapeHtml(data.owner) + '/</a>' +
            '<a href="' + escapeHtml(data.repoUrl) + '" class="reponame" target="_blank" rel="noopener noreferrer">' + escapeHtml(data.repo) + '</a>' +
            '</div>' +
            descHtml +
            '<div class="gitdata">' +
            '<span class="git-stars" title="Stars">' + ICONS.star + ' ' + formatCount(data.stars) + '</span>' +
            '<span class="git-forks" title="Forks">' + ICONS.fork + ' ' + formatCount(data.forks) + '</span>' +
            '<a class="viewmore" href="' + escapeHtml(data.repoUrl) + '" title="前往查看" target="_blank" rel="noopener noreferrer">' + ICONS.arrow + '</a>' +
            '</div>' +
            '</div>';
    }

    /**
     * 渲染降级卡片（API 失败时）
     * @param {Element} block
     * @param {number} type
     * @param {string} url
     */
    function renderFallback(block, type, url) {
        var platform = PLATFORMS[type] || 'Git';
        var display = url.replace(/^https?:\/\//, '').replace(/\/$/, '');

        block.classList.remove('gitcards-lazy');
        block.classList.add('gitcards-fallback');
        block.innerHTML =
            '<div class="gitcard-body">' +
            '<span class="gitcard-tag">' + platform + '</span>' +
            '<div class="gitcard-header">' +
            '<a href="' + escapeHtml(url) + '" class="reponame" target="_blank" rel="noopener noreferrer">' + escapeHtml(display) + '</a>' +
            '</div>' +
            '<div class="gitdata">' +
            '<a class="viewmore" href="' + escapeHtml(url) + '" title="前往查看" target="_blank" rel="noopener noreferrer">' + ICONS.arrow + '</a>' +
            '</div>' +
            '</div>';
    }

    /**
     * 初始化懒加载
     */
    function init() {
        var blocks = document.querySelectorAll('.gitcards-block.gitcards-lazy');
        if (blocks.length === 0) return;

        // IntersectionObserver 支持
        if (!('IntersectionObserver' in window)) {
            // 降级：直接加载所有卡片
            blocks.forEach(function (block) { loadCard(block); });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadCard(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '100px' });

        blocks.forEach(function (block) { observer.observe(block); });
    }

    /**
     * 加载单个卡片数据
     * @param {Element} block
     */
    function loadCard(block) {
        var type = parseInt(block.getAttribute('data-gitsite') || '1', 10);
        var url = block.getAttribute('data-giturl') || '';

        if (!url) {
            renderFallback(block, type, url);
            return;
        }

        fetchRepo(type, url).then(function (data) {
            if (data) {
                renderCard(block, data, type);
            } else {
                renderFallback(block, type, url);
            }
        });
    }

    // DOM 就绪后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
