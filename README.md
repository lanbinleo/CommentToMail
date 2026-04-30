## CommentToMail

> 一个Typecho异步邮件推送插件

适用版本: Typecho 1.2.0 / php 8.0 +

## 安装参考

1. clone或下载本项目
2. 重命名下载文件为 `CommentToMail`
3. 移动文件夹至 ~/usr/plugins/ 下
4. 后台启用插件, 选择 `SMTP` 或 `Resend API` 并填写发信配置
5. 通过网址监控等服务 定时访问指定URL来发送邮件 (推荐使用uptimerobot)

## Resend API

发信方式选择 `Resend API` 后, 填写:

- `Resend API Key`: 在 Resend 后台创建的 API Key
- `Resend 发件邮箱`: 已验证域名下的邮箱地址, 例如 `no-reply@example.com`
- `Resend API 地址`: 默认 `https://api.resend.com/emails` 即可

插件会按 Resend 官方 Email API 发送 JSON 请求, 并继续复用原有评论队列、测试邮件和回复通知逻辑。

## 邮件模板

插件设置页中可以直接编辑「博主通知邮件模板」和「访客回复通知邮件模板」。支持变量包括:

`{{siteTitle}}`, `{{title}}`, `{{author}}`, `{{author_p}}`, `{{ip}}`, `{{mail}}`, `{{permalink}}`, `{{manage}}`, `{{text}}`, `{{text_p}}`, `{{contactme}}`, `{{time}}`, `{{status}}`

留空时会回退使用 `template/owner.html` 与 `template/guest.html`。

## 安全加固

- 限制模板文件编辑接口只能写入插件模板目录内的 `.html` 文件
- 队列反序列化只允许恢复插件自己的评论对象
- 发送任务 Key 使用常量时间比较
- SMTP 不再默认关闭 TLS 证书校验
- 邮件发送失败时不会被误判为成功并清理队列
- 调试日志目录权限收紧, 写入时加锁并清理换行

## Copyright

CommentToMail 作为一款老牌Typecho 邮件推送插件, 具有多个分支. 但大都长时间未更新, 且无法支持 php8 与 Typecho 1.2.0. 

本项目部分参考原项目 且对其进行大量重构.

邮件服务采用[PHPMailer](https://github.com/PHPMailer/PHPMailer)

本项目采用 GNU GENERAL PUBLIC LICENSE 开源
