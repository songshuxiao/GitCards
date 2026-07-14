<?php

declare(strict_types=1);

namespace TypechoPlugin\GitCards;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Git 仓库数据获取器
 *
 * 负责从 GitHub / Gitee / GitLab / Coding 平台的 API 获取仓库信息，
 * 并提供基于文件系统的缓存机制以避免频繁请求导致速率限制。
 *
 * @package GitCards
 */
class Fetcher
{
    /** @var array<string,mixed> 插件配置 */
    private array $config;

    /** @var string 缓存目录路径 */
    private string $cacheDir;

    /**
     * @param array<string,mixed> $config 插件配置
     */
    public function __construct(array $config)
    {
        $this->config   = $config;
        $this->cacheDir = __DIR__ . '/cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 获取仓库数据（优先从缓存读取）
     *
     * @param int    $type 平台编号 (1=GitHub, 2=Coding, 3=Gitee, 4=GitLab)
     * @param string $url  仓库 URL
     * @return array<string,mixed>|null 仓库数据，失败返回 null
     */
    public function fetch(int $type, string $url): ?array
    {
        $cacheKey = $this->cacheKey($type, $url);
        $ttl      = (int) ($this->config['cacheTtl'] ?? 3600);

        // 尝试从缓存读取
        if ($ttl > 0) {
            $cached = $this->getCache($cacheKey, $ttl);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 从 API 获取
        $data = $this->fetchFromApi($type, $url);

        // 写入缓存（仅在成功时缓存）
        if ($data !== null && $ttl > 0) {
            $this->setCache($cacheKey, $data);
        }

        return $data;
    }

    /**
     * 从对应平台 API 获取数据
     *
     * @param int    $type 平台编号
     * @param string $url  仓库 URL
     * @return array<string,mixed>|null
     */
    private function fetchFromApi(int $type, string $url): ?array
    {
        return match ($type) {
            1       => $this->fetchGithub($url),
            2       => $this->fetchCoding($url),
            3       => $this->fetchGitee($url),
            4       => $this->fetchGitlab($url),
            default => null,
        };
    }

    // ========================================================================
    //  平台 API 适配器
    // ========================================================================

    /**
     * GitHub API
     *
     * @param string $url 仓库 URL (https://github.com/{owner}/{repo})
     * @return array<string,mixed>|null
     */
    private function fetchGithub(string $url): ?array
    {
        $path = $this->extractRepoPath($url, 'github.com');
        if ($path === null) {
            return null;
        }

        $apiUrl = 'https://api.github.com/repos/' . $path;
        $headers = [
            'User-Agent: GitCards-Typecho-Plugin/' . Plugin::VERSION,
            'Accept: application/vnd.github+json',
        ];

        $token = $this->config['githubToken'] ?? '';
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $json = $this->httpGet($apiUrl, $headers);
        if ($json === null) {
            return null;
        }

        $repo = json_decode($json, true);
        if (!is_array($repo) || isset($repo['message'])) {
            return null;
        }

        return [
            'owner'       => $repo['owner']['login'] ?? '',
            'ownerUrl'    => $repo['owner']['html_url'] ?? '',
            'repo'        => $repo['name'] ?? '',
            'repoUrl'     => $repo['html_url'] ?? $url,
            'description' => $repo['description'] ?? '',
            'homepage'    => $repo['homepage'] ?? '',
            'stars'       => (int) ($repo['stargazers_count'] ?? 0),
            'forks'       => (int) ($repo['forks_count'] ?? 0),
        ];
    }

    /**
     * Gitee API
     *
     * @param string $url 仓库 URL (https://gitee.com/{owner}/{repo})
     * @return array<string,mixed>|null
     */
    private function fetchGitee(string $url): ?array
    {
        $path = $this->extractRepoPath($url, 'gitee.com');
        if ($path === null) {
            return null;
        }

        $apiUrl = 'https://gitee.com/api/v5/repos/' . $path;
        $json = $this->httpGet($apiUrl, [
            'User-Agent: GitCards-Typecho-Plugin/' . Plugin::VERSION,
        ]);

        if ($json === null) {
            return null;
        }

        $repo = json_decode($json, true);
        if (!is_array($repo) || isset($repo['message'])) {
            return null;
        }

        return [
            'owner'       => $repo['owner']['login'] ?? '',
            'ownerUrl'    => $repo['owner']['html_url'] ?? '',
            'repo'        => $repo['path'] ?? ($repo['name'] ?? ''),
            'repoUrl'     => $repo['html_url'] ?? $url,
            'description' => $repo['description'] ?? '',
            'homepage'    => $repo['homepage'] ?? '',
            'stars'       => (int) ($repo['stargazers_count'] ?? 0),
            'forks'       => (int) ($repo['forks_count'] ?? 0),
        ];
    }

    /**
     * GitLab API
     *
     * @param string $url 仓库 URL (https://gitlab.com/{owner}/{repo})
     * @return array<string,mixed>|null
     */
    private function fetchGitlab(string $url): ?array
    {
        $path = $this->extractRepoPath($url, 'gitlab.com');
        if ($path === null) {
            return null;
        }

        // GitLab API 需要对路径进行 URL 编码
        $encodedPath = urlencode($path);
        $apiUrl = 'https://gitlab.com/api/v4/projects/' . $encodedPath;
        $json = $this->httpGet($apiUrl, [
            'User-Agent: GitCards-Typecho-Plugin/' . Plugin::VERSION,
        ]);

        if ($json === null) {
            return null;
        }

        $repo = json_decode($json, true);
        if (!is_array($repo) || isset($repo['message'])) {
            return null;
        }

        $namespace = $repo['namespace']['path'] ?? ($repo['namespace']['name'] ?? '');

        return [
            'owner'       => $namespace,
            'ownerUrl'    => rtrim($repo['web_url'] ?? $url, '/') !== ''
                ? preg_replace('#/[^/]+$#', '', $repo['web_url'] ?? $url)
                : $url,
            'repo'        => $repo['path'] ?? ($repo['name'] ?? ''),
            'repoUrl'     => $repo['web_url'] ?? $url,
            'description' => $repo['description'] ?? '',
            'homepage'    => '',
            'stars'       => (int) ($repo['star_count'] ?? 0),
            'forks'       => (int) ($repo['forks_count'] ?? 0),
        ];
    }

    /**
     * Coding API（腾讯开发者平台）
     *
     * 注意：原 WordPress 插件使用的第三方 API (codingapi.daidr.me) 可能已失效，
     * 此处保留代码结构以供参考，实际使用时可能无法获取数据。
     *
     * @param string $url 仓库 URL
     * @return array<string,mixed>|null
     */
    private function fetchCoding(string $url): ?array
    {
        // 尝试从 URL 中提取用户名和项目名
        // 格式: https://dev.tencent.com/u/{user}/p/{project} 或 https://{user}.coding.net/p/{project}
        $user = '';
        $project = '';

        if (preg_match('#/u/([^/]+)/p/([^/]+)#', $url, $m)) {
            $user = $m[1];
            $project = $m[2];
        } elseif (preg_match('#://([^/]+)\.coding\.net/p/([^/]+)#', $url, $m)) {
            $user = $m[1];
            $project = $m[2];
        }

        if ($user === '' || $project === '') {
            return null;
        }

        $apiUrl = "https://codingapi.daidr.me/api/user/{$user}/project/{$project}";
        $json = $this->httpGet($apiUrl, [
            'User-Agent: GitCards-Typecho-Plugin/' . Plugin::VERSION,
        ]);

        if ($json === null) {
            return null;
        }

        $resp = json_decode($json, true);
        if (!is_array($resp) || ($resp['code'] ?? -1) !== 0) {
            return null;
        }

        $data = $resp['data'] ?? [];

        return [
            'owner'       => $data['owner_user_name'] ?? $user,
            'ownerUrl'    => "https://dev.tencent.com/u/{$user}",
            'repo'        => $data['display_name'] ?? $project,
            'repoUrl'     => $data['https_url'] ?? $url,
            'description' => $data['description'] ?? '',
            'homepage'    => '',
            'stars'       => (int) ($data['star_count'] ?? 0),
            'forks'       => (int) ($data['fork_count'] ?? 0),
        ];
    }

    // ========================================================================
    //  HTTP 请求
    // ========================================================================

    /**
     * 发送 HTTP GET 请求
     *
     * @param string        $url     请求 URL
     * @param array<string> $headers 请求头
     * @return string|null 响应体，失败返回 null
     */
    private function httpGet(string $url, array $headers = []): ?string
    {
        $timeout = (int) ($this->config['apiTimeout'] ?? 5);

        // 优先使用 cURL
        if (function_exists('curl_init')) {
            return $this->curlGet($url, $headers, $timeout);
        }

        // 回退到 file_get_contents
        return $this->fgcGet($url, $headers, $timeout);
    }

    /**
     * 使用 cURL 发送 GET 请求
     *
     * @param string        $url
     * @param array<string> $headers
     * @param int           $timeout
     * @return string|null
     */
    private function curlGet(string $url, array $headers, int $timeout): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout + 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return null;
        }

        if ($httpCode >= 400) {
            return null;
        }

        return is_string($response) ? $response : null;
    }

    /**
     * 使用 file_get_contents 发送 GET 请求
     *
     * @param string        $url
     * @param array<string> $headers
     * @param int           $timeout
     * @return string|null
     */
    private function fgcGet(string $url, array $headers, int $timeout): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeout,
                'ignore_errors' => false,
            ],
            'ssl'  => [
                'verify_peer'  => true,
                'verify_host'  => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }

    // ========================================================================
    //  URL 解析
    // ========================================================================

    /**
     * 从仓库 URL 中提取 owner/repo 路径
     *
     * @param string $url     仓库 URL
     * @param string $domain  平台域名
     * @return string|null owner/repo 格式路径，失败返回 null
     */
    private function extractRepoPath(string $url, string $domain): ?string
    {
        // 移除协议前缀
        $clean = preg_replace('#^https?://#', '', $url);

        // 确保包含目标域名
        if ($clean === null || strpos($clean, $domain) !== 0) {
            return null;
        }

        // 移除域名
        $path = substr($clean, strlen($domain));

        // 移除开头的斜杠
        $path = ltrim($path, '/');

        // 移除结尾的斜杠
        $path = rtrim($path, '/');

        // 移除 .git 后缀
        $path = preg_replace('/\.git$/', '', $path);

        // 移除查询参数和锚点
        $path = preg_replace('/[?#].*$/', '', $path);

        if ($path === null || $path === '') {
            return null;
        }

        // 验证格式为 owner/repo
        $parts = explode('/', $path);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        // 只取前两段（owner/repo），忽略子路径
        return $parts[0] . '/' . $parts[1];
    }

    // ========================================================================
    //  缓存
    // ========================================================================

    /**
     * 生成缓存键
     *
     * @param int    $type
     * @param string $url
     * @return string
     */
    private function cacheKey(int $type, string $url): string
    {
        return md5($type . ':' . $url);
    }

    /**
     * 从缓存读取数据
     *
     * @param string $key 缓存键
     * @param int    $ttl 缓存有效期（秒）
     * @return array<string,mixed>|null
     */
    private function getCache(string $key, int $ttl): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';

        if (!file_exists($file)) {
            return null;
        }

        // 检查是否过期
        if (time() - filemtime($file) > $ttl) {
            @unlink($file);
            return null;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * 写入缓存
     *
     * @param string               $key  缓存键
     * @param array<string,mixed>  $data 仓库数据
     */
    private function setCache(string $key, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $file = $this->cacheDir . '/' . $key . '.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            @file_put_contents($file, $json, LOCK_EX);
        }
    }
}
