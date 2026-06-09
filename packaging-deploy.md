# SolidInvoice 打包部署协作机制详解

本文档深入解析 SolidInvoice 的打包发布与部署体系，涵盖安装脚本、容器构建、环境变量加载、版本号注入四大核心模块的边界与协作关系。

---

## 目录

- [整体架构概览](#整体架构概览)
- [安装脚本体系](#安装脚本体系)
- [容器构建流程](#容器构建流程)
- [环境变量加载机制](#环境变量加载机制)
- [版本号注入链路](#版本号注入链路)
- [端到端协作全景](#端到端协作全景)
- [关键边界与注意事项](#关键边界与注意事项)

---

## 整体架构概览

SolidInvoice 采用**分层分发**策略，从源码到最终运行共经历 5 个阶段：

```
源码阶段 → 构建阶段 → 分发阶段 → 安装阶段 → 运行阶段
    ↓          ↓          ↓          ↓          ↓
  PHP代码    build_dist   分发包    install.sh   FrankenPHP
  前端资源   build_binary  二进制    systemd     PHP 应用
  Go代码    docker build   容器镜像   deb/rpm    Secret Vault
```

**四大核心模块的定位：**

| 模块 | 职责边界 | 主要文件 |
|------|----------|----------|
| 安装脚本 | 将已构建的产物部署到目标系统 | [packaging/install.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/install.sh)、[packaging/nfpm.yaml](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/nfpm.yaml) |
| 容器构建 | 生成 Docker 镜像和静态二进制 | [docker/linux-static-build.Dockerfile](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker/linux-static-build.Dockerfile)、[docker-bake.hcl](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker-bake.hcl) |
| 环境变量加载 | 多层配置的读取、合并、持久化 | [src/CoreBundle/ConfigWriter.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/ConfigWriter.php)、[src/CoreBundle/Config/Loader/EnvLoader.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/Config/Loader/EnvLoader.php) |
| 版本号注入 | 从源码常量到运行时二进制的版本传递 | [src/CoreBundle/SolidInvoiceCoreBundle.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/SolidInvoiceCoreBundle.php)、[scripts/bump_version.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/bump_version.sh) |

---

## 安装脚本体系

### 1. 通用安装脚本（install.sh）

[packaging/install.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/install.sh) 是面向最终用户的一键安装入口。

**核心能力：**
- 自动检测 OS（Linux/macOS）和架构（amd64/arm64）
- 从 GitHub Releases 下载对应平台的静态二进制
- 自动检测 sudo/doas 权限提升
- 可选安装 systemd 服务

**安装流程：**
```
detect_os → detect_arch → detect_downloader
     ↓
get_latest_version (GitHub API)
     ↓
check_existing (已安装则升级)
     ↓
needs_sudo (判断是否需要提权)
     ↓
install_binary (下载 → 校验 → 安装到 /usr/local/bin)
     ↓
install_systemd_service (可选，Linux 专用)
```

**边界注意：**
- 安装脚本**只负责部署二进制**，不执行应用初始化（数据库配置等由 Web 安装向导完成）
- systemd 服务默认不安装，需显式设置 `INSTALL_SERVICE=1`
- 配置目录 `/etc/solidinvoice` 仅在安装 systemd 时创建

### 2. 系统包分发（nFPM）

[packaging/nfpm.yaml](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/nfpm.yaml) 定义了 deb/rpm/apk 等系统包的构建配置。

**包内结构：**
```
/usr/bin/solidinvoice              # 主二进制
/usr/lib/systemd/system/          # systemd 服务单元
/etc/solidinvoice/solidinvoice.env # 环境配置 (config|noreplace)
/var/lib/solidinvoice/             # 数据目录
```

**关键特性：**
- `solidinvoice.env` 标记为 `config|noreplace`：升级时不覆盖用户已修改的配置
- 自带 postinstall/preremove/postremove 脚本处理服务启停
- 依赖推荐：MySQL/MariaDB（推荐）、PostgreSQL/nginx（建议）

### 3. 其他安装渠道

| 渠道 | 配置文件 | 说明 |
|------|----------|------|
| Snap | [packaging/snap/snapcraft.yaml](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/snap/snapcraft.yaml) | 沙盒化安装 |
| AUR | [packaging/aur/PKGBUILD](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/aur/PKGBUILD) | Arch Linux |
| Scoop | [packaging/scoop/solidinvoice.json](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/scoop/solidinvoice.json) | Windows |
| Winget | [packaging/winget/SolidInvoice.SolidInvoice.yaml](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/winget/SolidInvoice.SolidInvoice.yaml) | Windows |
| Chocolatey | [packaging/chocolatey/solidinvoice.nuspec](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/chocolatey/solidinvoice.nuspec) | Windows |

---

## 容器构建流程

### 1. 构建体系总览

SolidInvoice 采用 **Docker Buildx + Bake** 实现多架构镜像构建，核心是 FrankenPHP 静态编译技术。

**两条构建路径：**

| 路径 | Dockerfile | 用途 |
|------|------------|------|
| 静态编译构建 | [docker/linux-static-build.Dockerfile](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker/linux-static-build.Dockerfile) | 多阶段，从源码编译出静态二进制 + 容器镜像 |
| 轻量化打包 | [docker/package.Dockerfile](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker/package.Dockerfile) | 单阶段，将预构建二进制打包成容器 |

### 2. 静态编译构建详解

`linux-static-build.Dockerfile` 采用**多阶段构建**策略：

**阶段一：Builder (golang:alpine)**
- 安装构建工具链（alpine-sdk、cmake、PHP 8.4、Composer 等）
- 复制 dist 包、脚本、frankenphp 源码、composer 配置
- 调用 `scripts/build_binary.sh` 执行完整构建

**阶段二：Runtime (alpine)**
- 仅复制编译好的二进制
- 设置环境变量和入口点
- 体积仅数十 MB

**关键构建参数（Build Args）：**

| 参数 | 作用 | 默认值 |
|------|------|--------|
| `SOLIDINVOICE_VERSION` | 版本号，注入二进制和环境 | - |
| `PHP_VERSION` | 编译的 PHP 版本 | 8.4 |
| `PHP_EXTENSIONS` | PHP 扩展列表 | 自动检测 |
| `NO_COMPRESS` | 跳过 UPX 压缩 | 1 |
| `RELEASE` | 是否上传到 GitHub | 0 |

### 3. Docker Bake 配置

[docker-bake.hcl](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker-bake.hcl) 定义了构建矩阵：

- **多平台**：`linux/amd64`、`linux/arm64`
- **多标签**：根据 `SOLIDINVOICE_VERSION` 自动生成 major、minor、patch 三级标签
- **函数 `semver`**：智能解析版本号，生成符合 Docker 标签规范的标签

**标签生成示例（版本 v2.3.11）：**
```
solidinvoice/solidinvoice:2
solidinvoice/solidinvoice:2.3
solidinvoice/solidinvoice:2.3.11
```

### 4. FrankenPHP 构建链路

二进制构建的核心是 [frankenphp/build-solidinvoice.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/build-solidinvoice.sh)，它采用**假 xcaddy 替换策略**来复用上游构建脚本。

**构建策略（巧妙的 hack）：**
```
上游 build-static.sh → 调用 xcaddy build → 【被替换】 → 我们的假 xcaddy
                                                           ↓
                                                    编译 app.go
                                                           ↓
                                              只有 SolidInvoice 命令的二进制
```

**为什么这样做？**
- 上游 `build-static.sh` 负责 PHP 静态编译、依赖处理等复杂工作
- 但它编译出的是标准 FrankenPHP/Caddy 二进制，有大量不需要的命令
- 通过替换 xcaddy 二进制为自定义脚本，既复用了上游能力，又得到定制化输出

**构建步骤：**
1. 设置 `SOLIDINVOICE_VERSION` → 映射为 `FRANKENPHP_VERSION`
2. 解压 `app.tar.gz` 到 `dist/embed-app/`
3. 判断是否为 Fresh Build（xcaddy 未安装），若是则走两阶段构建
4. 用 [frankenphp/bin/xcaddy](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/bin/xcaddy) 假脚本替换 static-php-cli 的 xcaddy
5. 运行上游 `build-static.sh`
6. 将输出从 `frankenphp-{os}-{arch}` 重命名为 `solidinvoice-{os}-{arch}`

### 5. 假 xcaddy 脚本的编译逻辑

[frankenphp/bin/xcaddy](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/bin/xcaddy) 是关键的"伪装者"脚本：

```bash
go build \
  -buildmode=pie \
  -ldflags "-linkmode=external -extldflags '${EXTLDFLAGS}' \
            -X 'github.com/caddyserver/caddy/v2.CustomVersion=${VERSION}'" \
  -tags="${BUILD_TAGS}" \
  -o "${OUTPUT}" \
  .
```

**关键点：**
- 通过 `-ldflags -X` 注入版本号到 Caddy 的 `CustomVersion` 变量
- Linux 使用 `-static-pie` 产生完全静态的 PIE 可执行文件
- 构建标签排除了不需要的 Caddy 模块（nobadger、nomysql、nopgx、nowatcher）

---

## 环境变量加载机制

SolidInvoice 的环境变量体系是**五层叠加**的结构，从外到内逐层覆盖。

### 1. 五层加载模型

```
┌─────────────────────────────────────────────────────┐
│  第 1 层：操作系统环境 / 容器环境变量                 │
│  (docker run -e / systemd EnvironmentFile)          │
├─────────────────────────────────────────────────────┤
│  第 2 层：Go 二进制默认值 (initializeApp)            │
│  app.go 中设置的 SOLIDINVOICE_ENV, DEBUG 等         │
├─────────────────────────────────────────────────────┤
│  第 3 层：Symfony Dotenv (.env 文件)                │
│  .env.dist → .env.local 等                         │
├─────────────────────────────────────────────────────┤
│  第 4 层：Symfony Secrets Vault (加密存储)           │
│  config/secrets/ 目录下的加密密钥                   │
├─────────────────────────────────────────────────────┤
│  第 5 层：EnvVarLoader 动态加载                     │
│  EnvLoader、BuildIdLoader 等运行时生成              │
└─────────────────────────────────────────────────────┘
```

**优先级规则**：外层优先。即操作系统环境变量优先级最高，最内层的动态加载优先级最低。

### 2. 第 1-2 层：系统与二进制层

在 [frankenphp/app.go](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/app.go#L103-L149) 的 `initializeApp()` 函数中设置默认值：

```go
envVars := map[string]string{
    upperAppName + "_CONFIG_DIR": filepath.Join(configDir, appName),
    upperAppName + "_ENV":        "prod",
    upperAppName + "_DEBUG":      "0",
    "APP_PATH":                   appPath,
    "SOLIDINVOICE_RUNTIME":       "frankenphp",
}

// 仅在未设置时才设置（操作系统环境变量优先）
for key, value := range envVars {
    if os.Getenv(key) == "" {
        os.Setenv(key, value)
    }
}
```

**边界注意：**
- Go 层的默认值**不会覆盖**已有的系统环境变量
- `SOLIDINVOICE_DOCKER` 环境变量由 Dockerfile 设置，用于判断是否在容器中运行
- `APP_PATH` 指向嵌入式应用解压后的目录（`~/.solidinvoice/app_<checksum>/`）

### 3. 第 3 层：Symfony Dotenv

[public/index.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/public/index.php) 定义了运行时入口：

```php
$_SERVER['APP_RUNTIME_OPTIONS'] = [
    'env_var_name' => 'SOLIDINVOICE_ENV',
    'debug_var_name' => 'SOLIDINVOICE_DEBUG'
];
```

**变量名映射：**
- Symfony 标准的 `APP_ENV` → 被替换为 `SOLIDINVOICE_ENV`
- Symfony 标准的 `APP_DEBUG` → 被替换为 `SOLIDINVOICE_DEBUG`

这意味着所有环境变量都使用 `SOLIDINVOICE_` 前缀，与 Symfony 框架的默认命名空间解耦。

**.env 文件加载顺序（Symfony 标准行为）：**
1. `.env` — 公共默认值
2. `.env.local` — 本地覆盖（不提交到 Git）
3. `.env.{SOLIDINVOICE_ENV}` — 环境特定（如 `.env.prod`）
4. `.env.{SOLIDINVOICE_ENV}.local` — 环境特定本地覆盖

### 4. 第 4 层：Secrets Vault

[config/packages/framework.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/config/packages/framework.php#L41-L44) 配置了 Secrets Vault：

```php
$config->secrets()
    ->enabled(true)
    ->vaultDirectory(env('SOLIDINVOICE_CONFIG_DIR'))
;
```

[ConfigWriter](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/ConfigWriter.php) 是操作 Vault 的封装类：

- 所有配置键自动添加 `SOLIDINVOICE_` 前缀
- 配置存储路径由 `SOLIDINVOICE_CONFIG_DIR` 决定
- 写入时自动处理 OPCache 失效（避免缓存旧配置）

**Vault 文件位置：**
- Docker 容器：`/etc/solidinvoice/`（VOLUME 挂载点）
- systemd 安装：`/etc/solidinvoice/`
- 手动运行：`~/.config/SolidInvoice/`（Go 的 `os.UserConfigDir()`）

### 5. 第 5 层：EnvVarLoader 动态加载

Symfony 的 `EnvVarLoaderInterface` 允许在容器编译时动态注入环境变量。

**(1) EnvLoader — 旧配置迁移**

[EnvLoader.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/Config/Loader/EnvLoader.php) 处理从旧版 `env.php` 到 Secrets Vault 的迁移：

- 检查 `config/env/env.php` 和 `config/env.php`（新旧两个位置）
- 将旧的数据库参数（`database_host` 等）转换为 `DATABASE_URL`
- 将 `secret` 键重命名为 `APP_SECRET`
- 所有键转为大写并保存到 Vault
- 迁移完成后删除旧文件

**(2) BuildIdLoader — 构建 ID 生成**

[BuildIdLoader.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/Config/Loader/BuildIdLoader.php) 生成唯一构建 ID：

- 若 `SOLIDINVOICE_BUILD_ID` 环境变量已设置，跳过
- 否则生成 UUID v7 作为构建 ID 并保存到 Vault
- 用于资产缓存清除、版本追踪等

### 6. 配置读取链路

以数据库配置为例，完整的读取链路：

```
DATABASE_URL 查找顺序：
  1. 操作系统环境变量 (最高优先级)
  2. .env.local 文件
  3. .env.{env} 文件
  4. .env 文件
  5. Secrets Vault 中的 SOLIDINVOICE_DATABASE_URL
  6. EnvVarLoader 动态注入的值
  7. 容器参数中的默认值 (最低优先级)
```

**实际应用：**
```php
// config/packages/doctrine.php
$dbalConfig->connection('default')
    ->url(env('SOLIDINVOICE_DATABASE_URL')->resolve())
```

这里 `env()` 是 Symfony DI 的环境变量处理器，会按照上述优先级查找。

---

## 版本号注入链路

版本号从源码到运行时经历了**五次传递**，跨越 PHP、Shell、Go 三种语言。

### 1. 版本号的"家"：源代码常量

[SolidInvoiceCoreBundle.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/SolidInvoiceCoreBundle.php#L24) 是版本号的唯一真相源：

```php
final public const VERSION = '3.0.0-alpha2';
```

这个常量用于：
- 安装时写入 `version` 数据库表
- CLI 命令显示
- API 响应头
- 任何需要版本号的 PHP 代码

### 2. 版本号更新：bump_version.sh

[scripts/bump_version.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/bump_version.sh) 负责版本号递增：

**更新三个文件：**
1. `src/CoreBundle/SolidInvoiceCoreBundle.php` — PHP 常量
2. `package.json` — 前端包版本
3. `composer.json` — PHP 包版本

**版本递增逻辑：**
```bash
# 从常量中读取当前版本
current_version=$(awk -F\' '/public const VERSION/ {print $2}' $FILE)
clean_version="${current_version%-dev}"
# 默认递增 patch 版本
next_version=$(bump_version "$clean_version" 2 1)
```

### 3. 构建时传递：build_dist.sh → build_binary.sh

[scripts/build_dist.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/build_dist.sh) 构建分发归档：

- 版本号作为参数传入（或从 git 分支/提交推断）
- 归档文件名包含版本号：`SolidInvoice-{VERSION}.tar.gz`
- 归档内的 `.env` 文件写入环境信息（但**不包含版本号**）

[scripts/build_binary.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/build_binary.sh) 作为 wrapper：
- 导出 `SOLIDINVOICE_VERSION` 环境变量
- 调用 `frankenphp/build-solidinvoice.sh`

### 4. Go 编译注入：假 xcaddy 脚本

在 [frankenphp/bin/xcaddy](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/bin/xcaddy#L105) 中通过 Go 链接器注入：

```bash
go build \
  -ldflags "-X 'github.com/caddyserver/caddy/v2.CustomVersion=${VERSION}'" \
  ...
```

**原理：** Go 的 `-ldflags -X` 可以在编译时设置包级变量的值。这里设置的是 Caddy 框架的 `CustomVersion` 变量。

### 5. 运行时读取：双向版本查询

**Go 层版本：**
```bash
solidinvoice version     # 显示 Go/Caddy 层的版本（注入的 CustomVersion）
solidinvoice build-info  # 显示构建详情
```

**PHP 层版本：**
```php
// 在 PHP 代码中
SolidInvoiceCoreBundle::VERSION  // '3.0.0-alpha2'
```

**关键注意**：Go 层的版本号和 PHP 层的版本号**不是同一个来源**：
- Go 层：编译时通过 ldflags 注入，来自构建参数
- PHP 层：代码中的常量，来自源代码

正常情况下两者应该一致，但如果构建参数与源码不匹配，可能出现不一致。

### 6. Docker 镜像层版本

在 Dockerfile 中，版本号同时存在于三个地方：
1. `ARG SOLIDINVOICE_VERSION` — 构建参数
2. `ENV SOLIDINVOICE_VERSION` — 容器运行时环境变量
3. `LABEL org.opencontainers.image.*` — OCI 镜像标签

镜像内的二进制自带版本号，环境变量中的版本号供 PHP 应用读取。

---

## 端到端协作全景

### 1. 从提交到发布的完整流程

```
开发者提交代码
      │
      ▼
bump_version.sh (更新版本号常量)
      │
      ▼
GitHub Actions CI 触发
      │
      ├─→ build_dist.sh ──→ 生成 .tar.gz / .zip
      │                         │
      │                         └─→ 上传到 GitHub Release
      │
      └─→ docker buildx bake ──→ 调用 linux-static-build.Dockerfile
                                       │
                                       ▼
                              build_binary.sh (容器内执行)
                                       │
                                       ▼
                              build-solidinvoice.sh
                                       │
                                       ▼
                              假 xcaddy → go build (注入版本号)
                                       │
                                       ▼
                              生成 solidinvoice 二进制
                                       │
                                       ▼
                              推送到 Docker Hub (多标签)
                                       │
                                       ▼
                              nfpm 打包 → deb/rpm/apk
                                       │
                                       ▼
                              各平台包仓库发布
```

### 2. 从安装到运行的环境变量传递

```
用户启动容器 / 启动服务
      │
      │  docker run -e SOLIDINVOICE_DATABASE_URL=...
      │  或 systemd 加载 /etc/solidinvoice/solidinvoice.env
      │
      ▼
┌─────────────────────────────────────┐
│  FrankenPHP Go 二进制 (app.go)      │
│  - 设置默认环境变量                  │
│  - 解压嵌入式 PHP 应用              │
│  - 启动 Caddy 服务器                │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  PHP 应用 (public/index.php)        │
│  - APP_RUNTIME_OPTIONS 映射变量名   │
│  - 加载 .env 文件                   │
│  - 加载 Secrets Vault              │
│  - 运行 EnvVarLoader                │
└──────────────┬──────────────────────┘
               │
               ▼
        Symfony 容器编译
         （所有配置生效）
               │
               ▼
        应用正常运行
```

### 3. 安装向导的协作

当应用首次运行且未安装时，[RequestListener](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/InstallBundle/Listener\RequestListener.php) 拦截所有请求：

1. **检测**：检查 `installed` 标记是否存在于 Vault
2. **重定向**：未安装则跳转到 `_system_install` 路由
3. **临时密钥**：用 Session ID 作为临时 `APP_SECRET`（安装完成后替换）
4. **安装步骤**：
   - 系统要求检查
   - 数据库配置 → 保存到 Vault
   - 生成密钥（`APP_SECRET`）
   - 创建数据库
   - 执行迁移
   - 创建管理员用户
   - 写入 `installed` 时间戳 + 版本号到 `version` 表

---

## 关键边界与注意事项

### 1. 版本号的两个"真相源"

**容易踩坑的点**：PHP 代码中的 `VERSION` 常量与 Go 二进制的版本号是独立设置的。

| 层面 | 来源 | 用途 |
|------|------|------|
| PHP | [SolidInvoiceCoreBundle::VERSION](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/SolidInvoiceCoreBundle.php#L24) | 数据库写入、API 响应、CLI 显示 |
| Go | 构建时 `-ldflags -X` 注入 | `solidinvoice version` 命令输出 |

**一致性保障**：构建脚本 `build_binary.sh` 将同一个 `SOLIDINVOICE_VERSION` 同时传递给 dist 构建和 Go 编译。但 dist 包内的 PHP 代码版本是**源码中硬编码**的，而非构建时注入。

### 2. 环境变量命名的双重前缀

由于使用了 Symfony 的 Secrets 系统，存在前缀叠加：

- `ConfigWriter::CONFIG_PREFIX = 'SOLIDINVOICE_'`
- 存入 Vault 的键会自动加上此前缀
- 读取时通过 `env('SOLIDINVOICE_DATABASE_URL')` 读取

**易错点**：如果手动调用 `$vault->seal('DATABASE_URL', ...)` 会缺少前缀，导致应用读不到。必须通过 `ConfigWriter` 写入。

### 3. 配置目录的位置不固定

`SOLIDINVOICE_CONFIG_DIR` 的值取决于运行方式：

| 运行方式 | 配置目录 |
|----------|----------|
| Docker 容器 | `/etc/solidinvoice`（Dockerfile 设置） |
| systemd 服务 | `/etc/solidinvoice`（env 文件设置） |
| 手动执行二进制 | `~/.config/SolidInvoice`（Go UserConfigDir） |
| 直接运行 PHP | `config/secrets/`（项目内默认） |

这也是为什么有 `SOLIDINVOICE_CONFIG_DIR` 环境变量的原因——解耦配置位置与应用代码。

### 4. 安装前与安装后的 APP_SECRET

**安装前**：`RequestListener` 用 Session ID 作为临时 `APP_SECRET`
- 保证安装向导页面能正常渲染（Symfony 需要 secret）
- 不持久化，仅用于安装过程

**安装后**：`GenerateSecretStep` 生成真正的随机密钥
- 使用 `defuse/php-encryption` 的安全随机数生成器
- 保存到 Secrets Vault
- 后续请求使用真实密钥

### 5. 嵌入式应用的解压与缓存

[app.go](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/app.go#L686-L703) 中的 `extractEmbeddedApp` 函数有一个巧妙的缓存机制：

```go
appPath := filepath.Join(appDir, "."+appName, "app_"+string(embeddedAppChecksum))
```

- 解压路径包含应用内容的校验和
- 校验和匹配时直接复用，不用每次都解压
- 升级后校验和变化，自动解压新版本

**边界**：这也意味着多个版本的二进制会在用户目录下留下多个 `app_<checksum>` 目录，不会自动清理。

### 6. 构建脚本的 --local 模式

[build_dist.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/build_dist.sh) 和 [build_binary.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/build_binary.sh) 都支持 `--local` 模式：

| 模式 | 行为 | 适用场景 |
|------|------|----------|
| 正常 | `git clone` 从远程拉取 | 生产构建、CI |
| `--local` | `rsync` 复制本地文件 | 开发测试、快速迭代 |

**注意**：`--local` 模式不会执行 git 操作，版本号使用当前 commit SHA（短）或传入的参数。

---

## 相关文件索引

### 构建脚本
- [scripts/build_dist.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/build_dist.sh) — 分发归档构建
- [scripts/build_binary.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/build_binary.sh) — 二进制构建包装器
- [scripts/bump_version.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/bump_version.sh) — 版本号递增
- [scripts/create_release.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/scripts/create_release.sh) — GitHub Release 创建

### 容器/FrankenPHP
- [docker/linux-static-build.Dockerfile](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker/linux-static-build.Dockerfile) — 静态编译 Dockerfile
- [docker/package.Dockerfile](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker/package.Dockerfile) — 轻量打包 Dockerfile
- [docker-bake.hcl](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/docker-bake.hcl) — Buildx Bake 配置
- [frankenphp/build-solidinvoice.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/build-solidinvoice.sh) — 二进制构建脚本
- [frankenphp/build-static.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/build-static.sh) — 上游 FrankenPHP 构建脚本
- [frankenphp/bin/xcaddy](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/bin/xcaddy) — 假 xcaddy 脚本
- [frankenphp/app.go](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/frankenphp/app.go) — Go 主程序

### 安装部署
- [packaging/install.sh](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/install.sh) — 通用安装脚本
- [packaging/nfpm.yaml](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/nfpm.yaml) — 系统包配置
- [packaging/systemd/solidinvoice.service](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/systemd/solidinvoice.service) — systemd 服务单元
- [packaging/systemd/solidinvoice.env](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/packaging/systemd/solidinvoice.env) — 环境配置模板

### 环境变量与配置
- [src/CoreBundle/ConfigWriter.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/ConfigWriter.php) — 配置写入器
- [src/CoreBundle/Config/Loader/EnvLoader.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/Config/Loader/EnvLoader.php) — 旧配置迁移加载器
- [src/CoreBundle/Config/Loader/BuildIdLoader.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/Config/Loader/BuildIdLoader.php) — 构建 ID 生成器
- [public/index.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/public/index.php) — 应用入口
- [.env.dist](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/.env.dist) — 环境变量模板

### 安装流程
- [src/InstallBundle/Command/InstallCommand.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/InstallBundle/Command/InstallCommand.php) — CLI 安装命令
- [src/InstallBundle/Listener\RequestListener.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/InstallBundle/Listener\RequestListener.php) — 安装请求拦截器
- [src/InstallBundle/Step/GenerateSecretStep.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/InstallBundle/Step/GenerateSecretStep.php) — 密钥生成步骤
- [src/InstallBundle/Step/CreateDatabaseStep.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/InstallBundle/Step/CreateDatabaseStep.php) — 创建数据库步骤
- [src/InstallBundle/Step/RunMigrationsStep.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/InstallBundle/Step/RunMigrationsStep.php) — 执行迁移步骤

### 版本号
- [src/CoreBundle/SolidInvoiceCoreBundle.php](file:///d:/fz/0508-2/solo-dogfeeding/code/120-SolidInvoice/src/CoreBundle/SolidInvoiceCoreBundle.php) — 版本号常量定义
