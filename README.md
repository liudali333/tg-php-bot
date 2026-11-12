# Telegram Menu Manager

基于 PHP 8.3 和 Docker Compose 的 Telegram 多级菜单机器人，配套深色网页管理后台。示例菜单不含品牌或业务信息，可自行替换。

## 快速开始

1. 安装 Docker 和 Docker Compose，将项目解压到任意目录。
2. 复制 `.env.example` 为 `.env`，填写 BotFather 提供的 `TG_BOT_TOKEN`，设置 `ADMIN_USER` 和至少 8 位的 `ADMIN_PASSWORD`。示例不提供默认密码。
3. 不需要代理时保持 `TG_PROXY` 为空；需要时填写可访问的 HTTP/SOCKS 代理地址。
4. 在项目目录执行 `docker compose up -d --build`。
5. 打开 `http://服务器局域网IP:18080`，登录管理后台。
6. 向机器人发送 `/start` 查看菜单。

NAS 和普通 Docker 主机均可部署。日志可通过 `docker compose logs --tail 50` 查看。

## 功能

- 多级菜单、文字自动回复、HTTPS 外部链接。
- 按钮排序、菜单编辑、配置导出、Telegram 风格即时预览。
- 图片、视频和文档回复：将文件放入 `media/`，后台填写文件名；不支持网页上传。
- 发布前校验，历史配置备份保存在 `data/menus-backup-*.json`。
- 发布时，无法从 `home` 到达的菜单自动补充到首页，已有子菜单层级保持不变。

发布不会修改已发出的 Telegram 消息。发送 `/start` 获取最新首页。

## 配置与升级

`menus.json` 使用 `pages`、`replies` 结构；按钮使用 `page`、`reply` 或 `url` 指定目标。内置菜单仅作演示。

升级时保留现有 `.env`、`menus.json`、`data/` 和 `media/`，合并程序文件后重新构建。不要用示例菜单覆盖业务配置。后台需要对 `menus.json` 和 `data/` 有写入权限。

## 访问与数据

后台为单管理员基础版，使用会话认证和 CSRF 校验，尚无完善的登录限速或多用户权限。默认 HTTP，端口仅应在可信网络中使用；远程访问请配置 VPN 或 HTTPS 反向代理及访问限制。

不要提交 `.env`、真实 Token、管理员密码、运行数据或媒体文件。项目提供 `.gitignore` 和 `.dockerignore`。不要让多个程序同时改写菜单配置。

## 发布说明

当前分发包已使用通用名称和示例配置，不包含个人品牌信息。仓库所有者需在公开发布前选择并添加适用的开源许可证；本包未擅自指定许可证。部署、Telegram 联网和实际浏览器显示需在目标环境验证。
