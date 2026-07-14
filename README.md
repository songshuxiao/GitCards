# GitCards

> GitCards for Typecho 将 GitHub / Gitee / GitLab / Coding 仓库以精美的信息卡片形式嵌入文章中。

## 简介

GitCards 最初是 [daidr](https://github.com/daidr) 为 WordPress 编写的插件，本项目将其转写为 Typecho 插件，适配 **Typecho 1.3.0** 和 **PHP 8.4**。

插件通过短代码 `[gitcard]` 在文章中插入 Git 仓库信息卡片，卡片会自动从对应平台的 API 获取仓库数据（星标数、Fork 数、描述等）并渲染为带平台配色的卡片样式。

### 与原版 WordPress 插件的区别

| 特性 | WordPress 原版 | Typecho 版 |
|------|---------------|-----------|
| 渲染方式 | 客户端懒加载（AJAX） | **服务端渲染（默认）** + 客户端懒加载（可选） |
| 缓存 | 无 | 文件缓存（可配置 TTL） |
| SEO | 不友好（JS 渲染） | **SEO 友好**（服务端输出完整 HTML） |
| GitHub Token | 不支持 | **支持**（提高 API 速率限制） |
| 降级处理 | 显示 ERROR | 降级为简洁链接卡片 |
| 依赖 | Font Awesome CDN | **无外部依赖**（内联 SVG 图标） |

## 安装

1. 下载插件压缩包并解压，将 `GitCards` 文件夹上传至 Typecho 的 `usr/plugins/` 目录
2. 登录 Typecho 后台，进入「控制台 → 插件」
3. 在插件列表中找到 **GitCards**，点击「启用」
4. （可选）点击「设置」配置 GitHub Token、缓存时间等参数

### 目录结构

```
usr/plugins/GitCards/
├── Plugin.php              # 主插件类（钩子注册、配置面板）
├── Parser.php              # 短代码解析器
├── Fetcher.php             # API 数据获取器（含缓存）
├── Renderer.php            # 卡片 HTML 渲染器
├── assets/
│   ├── gitcards.css        # 卡片样式
│   └── gitcards.js         # 懒加载脚本（仅懒加载模式加载）
├── cache/                  # 缓存目录（自动创建）
└── README.md               # 说明文档
```

## 使用方法

在文章 Markdown 或 HTML 中插入短代码：

### 基本用法

```
[gitcard type="1" url="https://github.com/daidr/gitCards"]
```

### 自动检测平台（省略 type）

```
[gitcard url="https://gitee.com/oschina/git-osc"]
```

插件会根据 URL 中的域名自动判断平台。

### 包裹式写法

```
[gitcard type="1"]https://github.com/typecho/typecho[/gitcard]
```

### 属性说明

| 属性 | 说明 | 取值 |
|------|------|------|
| `type` | 平台类型（可省略，自动检测） | `1`=GitHub, `2`=Coding, `3`=Gitee, `4`=GitLab |
| `url` | 仓库地址 | 完整的仓库 URL |

## 配置项

在后台「控制台 → 插件 → GitCards → 设置」中可配置以下参数：

### GitHub Personal Access Token

GitHub API 匿名访问限制为 60 次/小时，配置 Token 后提升至 5000 次/小时。

- 前往 [GitHub Settings → Developer settings → Personal access tokens](https://github.com/settings/tokens)
- 生成新 Token，**无需勾选任何权限**（只需读取公开仓库信息）
- 将 Token 填入配置项

> 注意：Token 仅在服务端渲染模式下使用，不会暴露到前端。

### 缓存时间（秒）

API 数据的缓存有效期，默认 3600 秒（1 小时）。设为 0 可禁用缓存（不推荐，会频繁请求 API）。

### 渲染模式

| 模式 | 说明 |
|------|------|
| **服务端渲染（推荐）** | 在服务器端获取数据并渲染完整 HTML，SEO 友好，支持 Token |
| 客户端懒加载 | 在浏览器中通过 IntersectionObserver 懒加载，与原版 WordPress 插件行为一致 |

### 默认平台

当短代码未指定 `type` 且无法从 URL 自动检测时的默认平台。

### API 请求超时（秒）

请求 Git 平台 API 的超时时间，默认 5 秒。

## 支持的平台

| 平台 | type | API 地址 |
|------|------|---------|
| GitHub | 1 | `https://api.github.com/repos/{owner}/{repo}` |
| Coding（腾讯开发者） | 2 | `https://codingapi.daidr.me/api/...`（第三方 API，可能不可用） |
| Gitee | 3 | `https://gitee.com/api/v5/repos/{owner}/{repo}` |
| GitLab | 4 | `https://gitlab.com/api/v4/projects/{path}` |

> 注意：Coding 平台已迁移至腾讯开发者平台，其第三方 API 可能已失效。如遇 Coding 卡片加载失败，会自动降级为链接卡片。

## 卡片样式

每个平台有独立的配色方案：

- **GitHub** — 深灰色背景（`#414141`）
- **Coding** — 浅灰色背景（`#f4f4f4`）
- **Gitee** — 红色背景（`#c71d23`）
- **GitLab** — 橙色背景（`#fc6d26`）

卡片包含以下信息：
- 平台标签
- 仓库所有者（可点击跳转）
- 仓库名称（可点击跳转）
- 仓库描述（含主页链接）
- 星标数
- Fork 数
- 「前往查看」箭头按钮

## 常见问题

### 卡片显示为简洁链接（降级模式）

这通常是因为 API 请求失败，可能原因：
1. **GitHub API 速率限制** — 匿名访问限制 60 次/小时，请配置 Token
2. **服务器网络问题** — 确保服务器能访问 `api.github.com` 等域名
3. **仓库不存在或为私有** — 确认 URL 正确且仓库为公开

### 缓存如何清理

- 在插件设置页保存配置不会自动清理缓存
- 禁用插件时会自动清理缓存
- 手动清理：删除 `usr/plugins/GitCards/cache/` 目录下的 `.json` 文件

### 短代码在摘要中如何显示

在文章摘要中，短代码会被替换为简洁的文本链接，避免在摘要中出现完整卡片。

### 是否支持 Markdown 编辑器

支持。短代码可以在 Markdown 中直接使用，插件会在 Markdown 渲染后解析短代码。

## 开发者信息

### 钩子

插件注册了以下 Typecho 钩子：

```php
// 内容过滤（Markdown 渲染后）
\Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = '...::contentFilter';
\Typecho\Plugin::factory('Widget\Base\Contents')->excerpt   = '...::excerptFilter';

// 前端资源注入
\Typecho\Plugin::factory('Widget\Archive')->header = '...::headerInsert';
\Typecho\Plugin::factory('Widget\Archive')->footer = '...::footerInsert';
```

### 自定义样式

如需自定义卡片样式，可在主题 CSS 中覆盖 `.gitcards-block` 相关样式。CSS 变量定义在各 `[data-gitsite]` 选择器上：

```css
.gitcards-block[data-gitsite="1"] {
    --gc-color: #f4f4f4;
    --gc-bg: #414141;
    /* ... */
}
```

## 许可证

MIT License

## 致谢

- 原项目作者：[daidr](https://github.com/daidr)
- 原项目地址：https://github.com/daidr/gitCards
- SVG 图标来源：[GitHub Octicons](https://github.com/primer/octicons) (MIT)
