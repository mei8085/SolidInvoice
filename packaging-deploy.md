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
| 安装脚本 | 将已构建的产物部署到目标系统 | [packaging/install.sh](packaging/install.sh)、[packaging/nfpm.yaml](packaging/nfpm.yaml) |
| 容器构建 | 生成 Docker 镜像和静态二进制 | [docker/linux-static-build.Dockerfile](docker/linux-static-build.Dockerfile)、[docker-bake.hcl](docker-bake.hcl) |
| 环境变量加载 | 多层配置的读取、合并、持久化 | [src/CoreBundle/ConfigWriter.php](src/CoreBundle/ConfigWriter.php)、[src/CoreBundle/Config/Loader/EnvLoader.php](src/CoreBundle/Config/Loader/EnvLoader.php) |
| 版本号注入 | 从源码常量到运行时二进制的版本传递 | [src/CoreBundle/SolidInvoiceCoreBundle.php](src/CoreBundle/SolidInvoiceCoreBundle.php)、[scripts/bump_version.sh](scripts/bump_version.sh) |

---

## 安装脚本体系

### 1. 通用安装脚本（install.sh）

[packaging/install.sh](packaging/install.sh) 是面向最终用户的一键安装入口。

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

[packaging/nfpm.yaml](packaging/nfpm.yaml) 定义了 deb/rpm/apk 等系统包的构建配置。

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
| Snap | [packaging/snap/snapcraft.yaml](packaging/snap/snapcraft.yaml) | 沙盒化安装 |
| AUR | [packaging/aur/PKGBUILD](packaging/aur/PKGBUILD) | Arch Linux |
| Scoop | [packaging/scoop/solidinvoice.json](packaging/scoop/solidinvoice.json) | Windows |
| Winget | [packaging/winget/SolidInvoice.SolidInvoice.yaml](packaging/winget/SolidInvoice.SolidInvoice.yaml) | Windows |
| Chocolatey | [packaging/chocolatey/solidinvoice.nuspec](packaging/chocolatey/solidinvoice.nuspec) | Windows |

---

## 容器构建流程

### 1. 构建体系总览

SolidInvoice 采用 **Docker Buildx + Bake** 实现多架构镜像构建，核心是 FrankenPHP 静态编译技术。

**两条构建路径：**

| 路径 | Dockerfile | 用途 |
|------|------------|------|
| 静态编译构建 | [docker/linux-static-build.Dockerfile](docker/linux-static-build.Dockerfile) | 多阶段，从源码编译出静态二进制 + 容器镜像 |
| 轻量化打包 | [docker/package.Dockerfile](docker/package.Dockerfile) | 单阶段，将预构建二进制打包成容器 |

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

[docker-bake.hcl](docker-bake.hcl) 定义了构建矩阵：

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

二进制构建的核心是 [frankenphp/build-solidinvoice.sh](frankenphp/build-solidinvoice.sh)，它采用**假 xcaddy 替换策略**来复用上游构建脚本。

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
4. 用 [frankenphp/bin/xcaddy](frankenphp/bin/xcaddy) 假脚本替换 static-php-cli 的 xcaddy
5. 运行上游 `build-static.sh`
6. 将输出从 `frankenphp-{os}-{arch}` 重命名为 `solidinvoice-{os}-{arch}`

### 5. 假 xcaddy 脚本的编译逻辑

[frankenphp/bin/xcaddy](frankenphp/bin/xcaddy) 是关键的"伪装者"脚本：

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

## Kubernetes / Helm 部署架构

SolidInvoice 提供了完整的 Helm Chart 用于 Kubernetes 部署，位于 helm/solidinvoice/ 目录。Chart 遵循 Kubernetes 最佳实践，通过 ConfigMap、Secret、PVC、Job、Deployment 等多种资源协同工作，实现了配置管理、数据持久化、自动安装、数据库迁移和异步任务处理。

### 1. Helm Chart 总览

[helm/solidinvoice/Chart.yaml](helm/solidinvoice/Chart.yaml) 定义了 Chart 的基本信息和依赖：

| 依赖子 Chart | 用途 | 条件 |
|-------------|------|------|
| mysql (Bitnami) | MySQL 数据库 | mysql.enabled=true |
| postgresql (Bitnami) | PostgreSQL 数据库 | postgresql.enabled=true |
| redis (Bitnami) | Redis 消息队列/缓存 | edis.enabled=true |
| meilisearch | Meilisearch 搜索引擎 | meilisearch.enabled=true |

**核心资源清单：**

`
configmap.yaml        # 非敏感环境变量
secret.yaml           # 敏感配置（密钥、数据库密码等）
pvc.yaml              # 配置目录持久化
deployment.yaml       # Web 主应用 Deployment
service.yaml          # Service
ingress.yaml          # Ingress（可选）
hpa.yaml              # 水平自动扩缩（可选）
pdb.yaml              # Pod Disruption Budget
networkpolicy.yaml    # 网络策略
serviceaccount.yaml   # 服务账号
jobs/
  install.yaml        # 首次安装 Job（pre-install hook）
  install-secret.yaml # 安装用 admin 凭证 Secret
  migrate.yaml        # 数据库迁移 Job（pre-upgrade hook）
worker/
  deployment.yaml     # Messenger Worker Deployment
  hpa.yaml            # Worker HPA
`

---

### 2. ConfigMap  非敏感配置中心

[helm/solidinvoice/templates/configmap.yaml](helm/solidinvoice/templates/configmap.yaml) 存储所有非敏感的环境变量，作为应用配置的第一层。

**包含的配置项：**

| 变量 | 作用 | 来源 |
|------|------|------|
| SOLIDINVOICE_ENV | 运行环境（prod） | 硬编码 |
| SOLIDINVOICE_DEBUG | 调试模式开关（0） | 硬编码 |
| SOLIDINVOICE_DOCKER | Docker 环境标记 | 硬编码 |
| SOLIDINVOICE_CONFIG_DIR | Vault 配置目录（/etc/solidinvoice） | 硬编码 |
| SOLIDINVOICE_LOCALE | 应用语言 | alues.yaml  pp.locale |
| SOLIDINVOICE_ALLOW_REGISTRATION | 是否允许公开注册 | alues.yaml  pp.allowRegistration |
| SOLIDINVOICE_MAILER_SENDER | 邮件发件人地址 | alues.yaml  mailer.sender |
| SOLIDINVOICE_SENTRY_RELEASE | Sentry 版本号 | 可选，sentry.enabled=true 时 |
| FRANKENPHP_WORKER_MODE | FrankenPHP Worker 模式 | 可选，pp.workerMode=true 时 |

**设计要点：**
- ConfigMap 只存**非敏感**配置，敏感信息全部走 Secret
- 所有 Pod（主应用、Worker、Job）都通过 envFrom.configMapRef 统一加载

---

### 3. Secret  敏感配置管理

[helm/solidinvoice/templates/secret.yaml](helm/solidinvoice/templates/secret.yaml) 管理所有敏感配置，包括密钥、数据库连接、消息队列 DSN 等。

#### 3.1 APP_SECRET 的保留机制

Secret 中有一个关键设计：**SOLIDINVOICE_APP_SECRET 跨升级保留**。

`yaml
{{- if .Values.app.secret }}
  SOLIDINVOICE_APP_SECRET: {{ .Values.app.secret | quote }}
{{- else if and $existingSecret (index $existingSecret.data "SOLIDINVOICE_APP_SECRET") }}
  SOLIDINVOICE_APP_SECRET: {{ index $existingSecret.data "SOLIDINVOICE_APP_SECRET" | b64dec | quote }}
{{- else }}
  SOLIDINVOICE_APP_SECRET: {{ randAlphaNum 64 | quote }}
{{- end }}
`

**三级回退逻辑：**
1. **显式设置**：如果 alues.yaml 中指定了 pp.secret，使用它
2. **保留现有值**：通过 Helm lookup 函数查询集群中已存在的 Secret，复用旧值
3. **随机生成**：首次安装时，用 andAlphaNum 64 生成 64 位随机字符串

>  **为什么要保留？**
> APP_SECRET 用于 Symfony 的 CSRF 保护、Cookie 加密、Sessions 等。如果升级时重新生成，会导致所有用户会话失效、已签名的 URL 失效等问题。

#### 3.2 其他敏感配置

| 配置项 | 优先级逻辑 |
|--------|-----------|
| SOLIDINVOICE_DATABASE_URL | externalDatabase.url > mysql subchart > postgresql subchart |
| SOLIDINVOICE_MAILER_DSN | 存在 mailer.existingSecret 时走外部 Secret，否则存本 Secret |
| SOLIDINVOICE_MESSENGER_DSN | messenger.dsn > redis subchart > doctrine://default |
| SOLIDINVOICE_SENTRY_DSN | sentry.dsn（可选） |
| OAuth 凭证 | Google OAuth 等（可选） |

#### 3.3 外部 Secret 支持

对于数据库密码、邮件 DSN 等敏感信息，Chart 支持引用集群中已有的 Secret（通过 existingSecret 配置），无需将明文写入 values.yaml。

---

### 4. PVC  Secrets Vault 持久化

[helm/solidinvoice/templates/pvc.yaml](helm/solidinvoice/templates/pvc.yaml) 为 /etc/solidinvoice 目录提供持久化存储。

**关键设计：**

`yaml
metadata:
  annotations:
    helm.sh/resource-policy: keep   # Helm 卸载时保留 PVC
spec:
  accessModes:
    - ReadWriteOnce                  # 默认单节点读写
  resources:
    requests:
      storage: 1Gi                   # 1GB 存储空间
`

**为什么需要持久化？**

/etc/solidinvoice 目录存放的是 Symfony Secrets Vault 的加密文件：
- defuse.encrypt.key  加密密钥（首次运行自动生成）
- pp.base64 等加密配置文件

这些文件一旦丢失，所有已加密的配置就无法解密。所以 PVC 设置了 helm.sh/resource-policy: keep，即使执行 helm uninstall 也不会删除 PVC。

**多副本注意事项：**
- 默认 ReadWriteOnce 只允许单节点挂载
- 如果 eplicaCount > 1，需要使用 ReadWriteMany 的 StorageClass 或外部共享存储

---

### 5. 安装 Job  首次部署初始化

[helm/solidinvoice/templates/jobs/install.yaml](helm/solidinvoice/templates/jobs/install.yaml) 是 pre-install Hook，在首次安装时自动运行 CLI 安装向导。

#### 5.1 触发条件与时机

| 属性 | 值 | 含义 |
|------|---|------|
| helm.sh/hook | pre-install | 在 Helm 安装主资源之前执行 |
| helm.sh/hook-weight |  | 执行权重 |
| helm.sh/hook-delete-policy | efore-hook-creation,hook-succeeded | 成功后删除，下次创建前先删旧的 |
| ackoffLimit | 可配置（默认 1） | 失败重试次数 |

#### 5.2 安装流程

`
检查是否已安装  已安装  退出（幂等）
      未安装
 运行 app:install 命令
     
 配置数据库连接
     
 创建管理员账号
     
 写入安装标记到 Vault
`

**核心命令：**
`ash
/usr/local/bin/solidinvoice console app:install \
  --database-driver=pdo_mysql \
  --database-host=... \
  --admin-email="" \
  --admin-password="" \
  --no-interaction
`

#### 5.3 admin 凭证 Secret

[helm/solidinvoice/templates/jobs/install-secret.yaml](helm/solidinvoice/templates/jobs/install-secret.yaml) 存储安装用的管理员凭证，Hook 权重为 -5（比安装 Job 更早执行）。

安装凭证有两种来源：
- 直接在 alues.yaml 中设置 install.adminEmail 和 install.adminPassword（不推荐生产环境）
- 通过 install.existingSecret 引用已有的 Secret

安装成功后，这个 Secret 会被自动删除（hook-succeeded 策略）。

#### 5.4 数据库等待 InitContainer

如果启用了 MySQL 或 PostgreSQL 子 Chart，安装 Job 会有一个 wait-for-mysql / wait-for-postgresql InitContainer，用 
c -z 轮询数据库端口，确保数据库就绪后再开始安装。

---

### 6. 迁移 Job  升级时数据库迁移

[helm/solidinvoice/templates/jobs/migrate.yaml](helm/solidinvoice/templates/jobs/migrate.yaml) 是 pre-upgrade Hook，在每次 Helm 升级时先执行数据库迁移。

| 属性 | 值 | 含义 |
|------|---|------|
| helm.sh/hook | pre-upgrade | 在 Helm 升级主资源之前执行 |
| ctiveDeadlineSeconds | 可配置 | 最长执行时间，防止卡死 |
| estartPolicy | OnFailure | 失败时重启 Pod 重试 |

**执行命令：**
`ash
/usr/local/bin/solidinvoice console doctrine:migrations:migrate \
  --no-interaction --no-ansi
`

**协作关系：**
- 迁移 Job 运行时，旧版本的 Deployment 还在运行
- 迁移完成后，Helm 才开始滚动更新 Deployment
- 保证数据库 schema 先升级，应用代码后升级

---

### 7. Worker Deployment  异步任务处理

[helm/solidinvoice/templates/worker/deployment.yaml](helm/solidinvoice/templates/worker/deployment.yaml) 运行独立的 Messenger Consumer Worker。

#### 7.1 为什么要独立 Deployment？

SolidInvoice 使用 Symfony Messenger 处理异步任务（发送邮件、生成 PDF、通知等）。Chart 选择了**独立 Deployment** 的架构，而不是在主应用里跑 worker：

**主应用 Deployment 启动参数：**
`ash
solidinvoice run --disable-https --messenger-workers=0
`

**Worker Deployment 启动参数：**
`ash
solidinvoice worker --workers=N
`

**架构优势：**
- **独立扩缩容**：Web 流量和任务量的峰值不一定同步，可以分别调整副本数
- **独立资源配置**：Worker 通常更吃 CPU 和内存，可以单独设置 requests/limits
- **故障隔离**：Worker 崩溃不影响 Web 服务，反之亦然
- **独立 HPA**：Worker 可以基于队列长度或 CPU 利用率自动扩缩

#### 7.2 Worker 健康检查

Worker 使用 exec 类型的 liveness probe，检查 messenger:consume 进程是否存在：

`ash
ps aux | grep '[m]essenger:consume' | grep -v grep
`

初始延迟 60 秒，每 60 秒检查一次，给 Worker 启动和任务处理留出充足时间。

---

### 8. 主 Deployment  Web 服务

[helm/solidinvoice/templates/deployment.yaml](helm/solidinvoice/templates/deployment.yaml) 是主要的 Web 应用 Deployment。

#### 8.1 滚动更新策略

`yaml
strategy:
  type: RollingUpdate
  rollingUpdate:
    maxSurge: 1        # 升级时最多新增 1 个 Pod
    maxUnavailable: 0  # 升级过程中不可用 Pod 数为 0
`

确保升级过程零停机。

#### 8.2 配置变更触发滚动更新

Pod template 的 annotations 中包含 ConfigMap 和 Secret 的 checksum：

`yaml
annotations:
  checksum/config: {{ include (print $.Template.BasePath "/configmap.yaml") . | sha256sum }}
  checksum/secret: {{ include (print $.Template.BasePath "/secret.yaml") . | sha256sum }}
`

当 ConfigMap 或 Secret 内容变化时，checksum 会变化，触发 Deployment 滚动更新，确保 Pod 使用最新配置。

#### 8.3 健康检查

使用 /health 端点进行三类探针：
- **startupProbe**：启动探针，最多等待 300 秒（30 次  10 秒）
- **livenessProbe**：存活探针，每 15 秒检查一次
- **readinessProbe**：就绪探针，每 10 秒检查一次

#### 8.4 统一的环境变量注入

通过 solidinvoice.commonEnv 模板（定义在 _env.tpl）实现统一的环境变量注入：

`yaml
envFrom:
  - configMapRef:
      name: {{ include "solidinvoice.fullname" . }}
  - secretRef:
      name: {{ include "solidinvoice.fullname" . }}
`

ConfigMap + Secret 通过 envFrom 批量注入，再加上按需添加的特殊 env 条目（如外部数据库 Secret 引用、Meilisearch 配置等）。

---

### 9. 资源协作全景

#### 9.1 首次安装时序

`
helm install
    
     0. 先创建 PVC（同步等待绑定）
    
     pre-install hook 阶段
         Hook Weight -5: install-secret（admin 凭证）
         Hook Weight  0: install Job
                Init: wait-for-mysql（等待数据库就绪）
                运行 app:install 命令
                     配置数据库
                     创建管理员
                     写入安装标记到 Vault
    
     主资源创建
          ConfigMap
          Secret
          Service
          主 Deployment
          Worker Deployment
`

#### 9.2 升级部署时序

`
helm upgrade
    
     pre-upgrade hook 阶段
         Hook Weight 0: migrate Job
              运行 doctrine:migrations:migrate
    
     主资源滚动更新
          ConfigMap 变更  checksum 变化  Deployment 滚动
          Secret 变更   checksum 变化  Deployment 滚动
          主 Deployment 滚动更新
          Worker Deployment 滚动更新
`

#### 9.3 配置数据流

`
values.yaml
    
     configmap.yaml  ConfigMap 资源 
                                           envFrom  所有 Pod
     secret.yaml  Secret 资源 
    
     外部 Secret 引用（existingSecret）
          数据库密码（subchart secret）
          邮件 DSN
          Sentry DSN
          OAuth 凭证
`

#### 9.4 Secrets Vault 数据流向

`
PVC (/etc/solidinvoice)
    
     所有 Pod 挂载（主应用、Worker、Job）
         读取加密的 Vault 文件
         使用 defuse 密钥解密
    
     写入操作
          安装 Job 写入安装标记、数据库配置等
          应用运行时通过 ConfigWriter 动态写入
`

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
│  $SOLIDINVOICE_CONFIG_DIR/ 下的加密密钥             │
├─────────────────────────────────────────────────────┤
│  第 5 层：EnvVarLoader 动态加载                     │
│  EnvLoader、BuildIdLoader 等运行时生成              │
└─────────────────────────────────────────────────────┘
```

**优先级规则**：外层优先。即操作系统环境变量优先级最高，最内层的动态加载优先级最低。

### 2. 第 1-2 层：系统与二进制层

在 [frankenphp/app.go](frankenphp/app.go#L103-L149) 的 `initializeApp()` 函数中设置默认值：

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

[public/index.php](public/index.php) 定义了运行时入口：

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

**.env 文件加载顺序（Symfony Dotenv 标准行为，优先级从低到高）：**
1. .env  公共默认值
2. .env.local  本地覆盖（不提交到 Git）
3. .env.{SOLIDINVOICE_ENV}  环境特定（如 .env.prod）
4. .env.{SOLIDINVOICE_ENV}.local  环境特定本地覆盖（最高优先级）

> .env.dist 是**模板文件**，不会被自动加载，仅作为 .env 的参考模板。
### 4. 第 4 层：Secrets Vault

[config/packages/framework.php](config/packages/framework.php#L41-L44) 配置了 Secrets Vault：

```php
$config->secrets()
    ->enabled(true)
    ->vaultDirectory(env('SOLIDINVOICE_CONFIG_DIR'))
;
```

[src/CoreBundle/ConfigWriter.php](src/CoreBundle/ConfigWriter.php) 是操作 Vault 的封装类：

- 所有配置键自动添加 `SOLIDINVOICE_` 前缀
- 配置存储路径由 `SOLIDINVOICE_CONFIG_DIR` 决定
- 写入时自动处理 OPCache 失效（避免缓存旧配置）

**Vault 文件位置（`SOLIDINVOICE_CONFIG_DIR` 的实际落点）：**

| 运行方式 | 配置目录 | 来源 |
|----------|----------|------|
| Docker 容器 | `/etc/solidinvoice/` | Dockerfile `ENV` 设置 |
| systemd / deb/rpm 包 | `/etc/solidinvoice/` | `solidinvoice.env` 环境文件 |
| FrankenPHP 二进制（solidinvoice run） | `~/.config/SolidInvoice/` | Go `os.UserConfigDir()` |
| 直接运行 PHP（源码方式，非 test） | `{项目根}/config/env/` | `config/services.php` 默认值 |
| 直接运行 PHP（test 环境） | `{项目根}/var/cache/test/config/` | `config/services.php` test 专用 |
| Snap 安装 | `$SNAP_COMMON/config` | `snapcraft.yaml` |

> 默认数据库（SQLite）也位于配置目录下：`$SOLIDINVOICE_CONFIG_DIR/db/solidinvoice.db`
### 5. 第 5 层：EnvVarLoader 动态加载

Symfony 的 `EnvVarLoaderInterface` 允许在容器编译时动态注入环境变量作为默认值。
Secrets Vault 本身也是通过这个机制加载的（`AbstractVault` 实现了该接口）。

**(1) EnvLoader — 旧配置迁移（一次性）**

[src/CoreBundle/Config/Loader/EnvLoader.php](src/CoreBundle/Config/Loader/EnvLoader.php) 是一个**迁移工具**，而非日常配置加载器：

- 检查 `config/env/env.php` 和 `config/env.php`（新旧两个位置）
- 将旧的数据库参数（`database_host` 等）转换为 `DATABASE_URL`
- 将 `secret` 键重命名为 `APP_SECRET`
- 所有键转为大写并加上 `SOLIDINVOICE_` 前缀后保存到 Vault
- 仅在旧文件存在时触发，全新安装不会运行
- 迁移完成后删除旧文件

**(2) BuildIdLoader — 构建 ID 生成**

[src/CoreBundle/Config/Loader/BuildIdLoader.php](src/CoreBundle/Config/Loader/BuildIdLoader.php) 生成唯一构建 ID：

- 若 `SOLIDINVOICE_BUILD_ID` 环境变量已设置（即 Vault 中已有），跳过
- 否则生成 UUID v7 作为构建 ID 并保存到 Vault
- 用于资产缓存清除、版本追踪、部署标识等

### 6. 配置读取链路

以数据库配置为例，完整的读取链路（优先级从高到低，共 8 级）：

| 优先级 | 来源 | 说明 | 示例 |
|--------|------|------|------|
| 1（最高） | 操作系统环境变量 | 用户在运行前设置的环境变量 | `export SOLIDINVOICE_DATABASE_URL=...` |
| 2 | Go 二进制默认值 | FrankenPHP 在 PHP 启动前通过 `os.Setenv` 设置的默认值 | `SOLIDINVOICE_ENV=prod` |
| 3 | `.env.{env}.local` | 环境特定的本地覆盖文件（不提交 Git） | `.env.prod.local` |
| 4 | `.env.{env}` | 环境特定的配置文件 | `.env.prod` |
| 5 | `.env.local` | 本地通用覆盖文件（不提交 Git） | `.env.local` |
| 6 | `.env` | 公共默认值文件 | `.env` |
| 7 | Secrets Vault / EnvVarLoader | 加密的 Vault 配置和动态加载器 | `env.php` 加密文件 |
| 8（最低） | 容器参数默认值 | 在服务定义中的默认值 | `%env(SOLIDINVOICE_DATABASE_URL)%` 默认值 |

**实际应用：**
```php
// config/packages/doctrine.php
$dbalConfig->connection('default')
    ->url(env('SOLIDINVOICE_DATABASE_URL')->resolve())
```

这里 `env()` 是 Symfony DI 的环境变量处理器，会按照上述优先级查找。
找到即止，后面的层级作为 fallback。

---

## 版本号注入链路

版本号从源码到运行时经历了**五次传递**，跨越 PHP、Shell、Go 三种语言。

### 1. 版本号的"家"：源代码常量

[src/CoreBundle/SolidInvoiceCoreBundle.php](src/CoreBundle/SolidInvoiceCoreBundle.php#L24) 是版本号的唯一真相源：

```php
final public const VERSION = '3.0.0-alpha2';
```

这个常量用于：
- 安装时写入 `version` 数据库表
- CLI 命令显示
- API 响应头
- 任何需要版本号的 PHP 代码

### 2. 版本号更新：bump_version.sh

[scripts/bump_version.sh](scripts/bump_version.sh) 负责版本号递增：

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

[scripts/build_dist.sh](scripts/build_dist.sh) 构建分发归档：

- 版本号作为参数传入（或从 git 分支/提交推断）
- 归档文件名包含版本号：`SolidInvoice-{VERSION}.tar.gz`
- 归档内的 `.env` 文件写入环境信息（但**不包含版本号**）

[scripts/build_binary.sh](scripts/build_binary.sh) 作为 wrapper：
- 导出 `SOLIDINVOICE_VERSION` 环境变量
- 调用 `frankenphp/build-solidinvoice.sh`

### 4. Go 编译注入：假 xcaddy 脚本

在 [frankenphp/bin/xcaddy](frankenphp/bin/xcaddy#L105) 中通过 Go 链接器注入：

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

当应用首次运行且未安装时，[src/InstallBundle/Listener/RequestListener.php](src/InstallBundle/Listener/RequestListener.php) 拦截所有请求：

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
| PHP | [SolidInvoiceCoreBundle::VERSION](src/CoreBundle/SolidInvoiceCoreBundle.php#L24) | 数据库写入、API 响应、CLI 显示 |
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

| 运行方式 | 配置目录 | 来源 |
|----------|----------|------|
| Docker 容器 | `/etc/solidinvoice/` | `linux-static-build.Dockerfile` |
| systemd / deb/rpm 包 | `/etc/solidinvoice/` | `solidinvoice.env` 环境文件 |
| FrankenPHP 二进制（solidinvoice run） | `~/.config/SolidInvoice/` | Go `os.UserConfigDir()` |
| 直接运行 PHP（源码方式，非 test） | `{项目根}/config/env/` | `config/services.php` 默认值 |
| 直接运行 PHP（test 环境） | `{项目根}/var/cache/test/config/` | `config/services.php` test 专用 |
| Snap 安装 | `$SNAP_COMMON/config` | `snapcraft.yaml` |

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

[frankenphp/app.go](frankenphp/app.go#L686-L703) 中的 `extractEmbeddedApp` 函数有一个巧妙的缓存机制：

```go
appPath := filepath.Join(appDir, "."+appName, "app_"+string(embeddedAppChecksum))
```

- 解压路径包含应用内容的校验和
- 校验和匹配时直接复用，不用每次都解压
- 升级后校验和变化，自动解压新版本

**边界**：这也意味着多个版本的二进制会在用户目录下留下多个 `app_<checksum>` 目录，不会自动清理。

### 6. 构建脚本的 --local 模式

[scripts/build_dist.sh](scripts/build_dist.sh) 和 [scripts/build_binary.sh](scripts/build_binary.sh) 都支持 `--local` 模式：

| 模式 | 行为 | 适用场景 |
|------|------|----------|
| 正常 | `git clone` 从远程拉取 | 生产构建、CI |
| `--local` | `rsync` 复制本地文件 | 开发测试、快速迭代 |

**注意**：`--local` 模式不会执行 git 操作，版本号使用当前 commit SHA（短）或传入的参数。

---

## 相关文件索引

### 构建脚本
- [scripts/build_dist.sh](scripts/build_dist.sh) — 分发归档构建
- [scripts/build_binary.sh](scripts/build_binary.sh) — 二进制构建包装器
- [scripts/bump_version.sh](scripts/bump_version.sh) — 版本号递增
- [scripts/create_release.sh](scripts/create_release.sh) — GitHub Release 创建

### 容器/FrankenPHP
- [docker/linux-static-build.Dockerfile](docker/linux-static-build.Dockerfile) — 静态编译 Dockerfile
- [docker/package.Dockerfile](docker/package.Dockerfile) — 轻量打包 Dockerfile
- [docker-bake.hcl](docker-bake.hcl) — Buildx Bake 配置
- [frankenphp/build-solidinvoice.sh](frankenphp/build-solidinvoice.sh) — 二进制构建脚本
- [frankenphp/build-static.sh](frankenphp/build-static.sh) — 上游 FrankenPHP 构建脚本
- [frankenphp/bin/xcaddy](frankenphp/bin/xcaddy) — 假 xcaddy 脚本
- [frankenphp/app.go](frankenphp/app.go) — Go 主程序

### 安装部署
- [packaging/install.sh](packaging/install.sh) — 通用安装脚本
- [packaging/nfpm.yaml](packaging/nfpm.yaml) — 系统包配置
- [packaging/systemd/solidinvoice.service](packaging/systemd/solidinvoice.service) — systemd 服务单元
- [packaging/systemd/solidinvoice.env](packaging/systemd/solidinvoice.env) — 环境配置模板

### Kubernetes / Helm
- [helm/solidinvoice/Chart.yaml](helm/solidinvoice/Chart.yaml)  Helm Chart 定义与依赖
- [helm/solidinvoice/values.yaml](helm/solidinvoice/values.yaml)  默认配置值
- [helm/solidinvoice/templates/configmap.yaml](helm/solidinvoice/templates/configmap.yaml)  ConfigMap 模板
- [helm/solidinvoice/templates/secret.yaml](helm/solidinvoice/templates/secret.yaml)  Secret 模板
- [helm/solidinvoice/templates/pvc.yaml](helm/solidinvoice/templates/pvc.yaml)  PVC 模板
- [helm/solidinvoice/templates/deployment.yaml](helm/solidinvoice/templates/deployment.yaml)  主应用 Deployment
- [helm/solidinvoice/templates/jobs/install.yaml](helm/solidinvoice/templates/jobs/install.yaml)  安装 Job
- [helm/solidinvoice/templates/jobs/install-secret.yaml](helm/solidinvoice/templates/jobs/install-secret.yaml)  安装凭证 Secret
- [helm/solidinvoice/templates/jobs/migrate.yaml](helm/solidinvoice/templates/jobs/migrate.yaml)  迁移 Job
- [helm/solidinvoice/templates/worker/deployment.yaml](helm/solidinvoice/templates/worker/deployment.yaml)  Worker Deployment
- [helm/solidinvoice/templates/_env.tpl](helm/solidinvoice/templates/_env.tpl)  环境变量模板
- [helm/solidinvoice/templates/_helpers.tpl](helm/solidinvoice/templates/_helpers.tpl)  通用模板助手

### 环境变量与配置
- [src/CoreBundle/ConfigWriter.php](src/CoreBundle/ConfigWriter.php) — 配置写入器
- [src/CoreBundle/Config/Loader/EnvLoader.php](src/CoreBundle/Config/Loader/EnvLoader.php) — 旧配置迁移加载器
- [src/CoreBundle/Config/Loader/BuildIdLoader.php](src/CoreBundle/Config/Loader/BuildIdLoader.php) — 构建 ID 生成器
- [public/index.php](public/index.php) — 应用入口
- [.env.dist](.env.dist) — 环境变量模板

### 安装流程
- [src/InstallBundle/Command/InstallCommand.php](src/InstallBundle/Command/InstallCommand.php) — CLI 安装命令
- [src/InstallBundle/Listener/RequestListener.php](src/InstallBundle/Listener/RequestListener.php) — 安装请求拦截器
- [src/InstallBundle/Step/GenerateSecretStep.php](src/InstallBundle/Step/GenerateSecretStep.php) — 密钥生成步骤
- [src/InstallBundle/Step/CreateDatabaseStep.php](src/InstallBundle/Step/CreateDatabaseStep.php) — 创建数据库步骤
- [src/InstallBundle/Step/RunMigrationsStep.php](src/InstallBundle/Step/RunMigrationsStep.php) — 执行迁移步骤

### 版本号
- [src/CoreBundle/SolidInvoiceCoreBundle.php](src/CoreBundle/SolidInvoiceCoreBundle.php) — 版本号常量定义
