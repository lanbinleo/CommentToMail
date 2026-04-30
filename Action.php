<?php

namespace TypechoPlugin\CommentToMail;

/**
 * CommentToMail
 * Typecho 异步评论邮件提醒插件
 * 
 * @copyright  Copyright (c) 2022 xcsoft
 * @license    GNU General Public License 3.0
 */

use \Utils\Helper;
use \Typecho\{Widget, Db};
use \TypechoPlugin\CommentToMail\lib\Email;
use \TypechoPlugin\CommentToMail\lib\Comment;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/Exception.php';

/**
 * action
 * 
 * @package CommentToMail
 */
class Action extends Widget implements \Widget\ActionInterface
{
    /** 
     * 数据库对象 
     * 
     * @var Db  
     */
    private Db $_db;

    /** 
     * 表前缀  
     * 
     * @var string  
     */
    private string $_prefix;

    /** 
     * 插件配置信息 
     * 
     * @var \Typecho\Config
     */
    private \Typecho\Config $_cfg;

    /** 
     * 系统配置信息 
     * 
     * @var \Widget\Options
     */
    private \Widget\Options $_options;

    /** 
     * 当前登录用户 
     * 
     * @var object 
     */
    private object $_user;

    /** 
     * 模板文件目录
     * 
     *  @var string 
     */
    private string $_template_dir = __DIR__ . '/template/';

    /**
     * 邮件对象
     *
     * @var Email
     */
    private Email $_email;

    /**
     * 评论对象
     *
     * @var \TypechoPlugin\CommentToMail\lib\Comment
     */
    private \TypechoPlugin\CommentToMail\lib\Comment $_comment;

    /**
     * 最近一次发送错误
     *
     * @var string
     */
    private string $_lastMailError = '';

    /**
     * 入口方法
     *
     * @access public
     * @return void
     */
    public function action()
    {
        $this->init();

        $this->on($this->request->is('do=deliverMail'))->deliverMail($this->request->key);  //邮件队列

        if (!$this->_user->hasLogin()) $this->response->redirect($this->_options->loginUrl); //用户未登录
        $this->_user->pass('administrator');
        $this->on($this->request->is('do=testMail'))->testMail();                           //测试邮件
        $this->on($this->request->is('do=editTheme'))->editTheme($this->request->edit);     //编辑主题
        $this->on($this->request->is('do=runQueue'))->deliverMail(null, false, false);      //后台手动发送队列
        $this->on($this->request->is('do=retryQueue'))->retryQueue();                       //重试失败队列
        $this->on($this->request->is('do=clearLogs'))->clearLogs();                         //清理发送日志
    }

    /**
     * 初始化
     *
     * @return void
     */
    public function init()
    {
        Plugin::ensureQueueTable();

        $this->_db = Db::get();
        $this->_prefix = $this->_db->getPrefix();

        $this->_user = $this->widget('\Widget\User');
        $this->_options = $this->widget('\Widget\Options');
        $this->_cfg = Helper::options()->plugin('CommentToMail');
    }

    /**
     * 发送邮件
     * 
     * @param string $key
     * @return void
     */
    private function deliverMail(?string $key, bool $checkKey = true, bool $throwJson = true): void
    {
        if ($checkKey && !hash_equals((string)$this->_cfg->key, (string)$key)) {
            $this->response->throwJson([
                'code' => -1,
                'msg' => 'Permission denied'
            ]);
        }

        $now = time();
        $limit = $this->queueLimit();
        $table = $this->_prefix . 'mail';
        $mailQueue = $this->_db->fetchAll("SELECT id, content, attempts FROM {$table} WHERE sent = 0 AND (next_retry IS NULL OR next_retry <= {$now}) AND (locked_until IS NULL OR locked_until <= {$now}) ORDER BY id ASC LIMIT {$limit}");

        //计数器
        $success = 0;
        $fail = 0;
        foreach ($mailQueue as &$mail) {
            if (!$this->claimQueueRow((int)$mail['id'])) {
                continue;
            }

            $comment = $this->decodeQueuedComment($mail['content']);

            /** 发送邮件 */
            if (!$comment instanceof Comment) {
                $this->markQueueFailed((int)$mail['id'], (int)($mail['attempts'] ?? 0), '队列内容无法解析');
                $fail++;
                continue;
            }
            $this->_comment = $comment;

            if ($this->processMail()) {
                $this->markQueueSent((int)$mail['id']);
                $success++;
            } else {
                $this->markQueueFailed((int)$mail['id'], (int)($mail['attempts'] ?? 0), $this->_lastMailError ?: '邮件发送失败');
                $fail++;
            }

            usleep(100); //休眠100毫秒 防止QPS限制
        }
        $this->cleanupOldLogs();

        $result = [
            'code' => 0,
            'msg' => 'success',
            'count' => [
                'all' => count($mailQueue),
                'success' => $success,
                'fail' => $fail,
            ],
        ];

        if ($throwJson) {
            $this->response->throwJson($result);
        }

        $this->widget('Widget_Notice')->set(
            _t('队列处理完成: 共 %d, 成功 %d, 失败 %d', count($mailQueue), $success, $fail),
            $fail > 0 ? 'notice' : 'success'
        );
        $this->response->goBack();
    }

    /**
     * 处理发信
     *
     * @return boolean
     */
    private function processMail(): bool
    {
        $this->_email = new Email();

        //发件人邮箱
        $this->_email->from = (string)(((string)$this->_cfg->mode === 'resend' && !empty($this->_cfg->resendFrom)) ? $this->_cfg->resendFrom : $this->_cfg->user);
        //发件人名称
        $this->_email->fromName = (string)($this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title);

        //向博主发邮件的标题格式
        $this->_email->titleForOwner = (string)$this->_cfg->titleForOwner;

        //向访客发邮件的标题格式
        $this->_email->titleForGuest = (string)$this->_cfg->titleForGuest;

        //验证博主是否接收自己的邮件
        $toMe = ($this->cfgEnabled('other', 'to_me') && $this->_comment->ownerId == $this->_comment->authorId) ? true : false;
        $sent = false;
        $success = true;
        $errors = [];

        //向博主发信
        // TODO $this->_comment->parent === '0' // parent === ‘0’ 时 为根评论
        // 如果在此处判断 会导致 别人评论别人的评论时 不会发送邮件给博主 后续fix
        if (in_array($this->_comment->status, $this->cfgArray('status'), true) && $this->_comment->type !== '1' && $this->cfgEnabled('other', 'to_owner') && ($toMe || $this->_comment->ownerId != $this->_comment->authorId)) {
            if (!$this->_cfg->mail) {
                self::widget('\Widget\Users\Author@temp' . $this->_comment->cid, ['uid' => $this->_comment->ownerId])->to($user);
                $this->_email->reciver = $user->mail;
            } else {
                $this->_email->reciver = $this->_cfg->mail;
            }
            if (empty($this->_cfg->name)) {
                self::widget('\Widget\Users\Author@temp' . $this->_comment->cid, ['uid' => $this->_comment->ownerId])->to($user);
                $this->_email->reciverName = $user->name;
            } else {
                $this->_email->reciverName = $this->_cfg->name;
            }

            // 设置邮件回复信息
            $this->_email->replyTo = $this->_comment->mail; //评论者的邮箱
            $this->_email->replyToName = $this->_comment->author;
            $result = $this->authorMail()->sendMail();
            $sent = true;
            if ($result !== true) {
                $success = false;
                $errors[] = (string)$result;
            }
        }

        /** 向访客发信 */
        if ($this->_comment->parent !== '0' && $this->_comment->status == 'approved' && $this->cfgEnabled('other', 'to_guest')) {
            /**  如果联系我的邮件地址为空，则使用文章作者的邮件地址 */
            if (!$this->_cfg->contactme) {
                if (!isset($user) || !$user) {
                    self::widget('\Widget\Users\Author@temp' . $this->_comment->cid, array('uid' => $this->_comment->ownerId))->to($user);
                }
                $this->_comment->contactme = $user->mail;
            } else {
                $this->_comment->contactme = $this->_cfg->contactme;
            }

            $original = $this->_db->fetchRow($this->_db->select('author', 'mail', 'text')->from('table.comments')->where('coid = ?', $this->_comment->parent));
            // 被评论者
            if ($original && ($this->cfgEnabled('other', 'to_me') || $this->_comment->mail != $original['mail'])) {
                $this->_comment->originalText   = $original['text'];
                $this->_comment->originalAuthor = $original['author'];

                $this->_email->reciver = $original['mail'];
                $this->_email->reciverName = $original['author'];
                $this->_email->replyTo  = $this->_comment->mail; //当前评论者的邮箱
                $this->_email->replyToName = $this->_comment->author ? $this->_comment->author : $this->_options->title;
                $result = $this->guestMail()->sendMail();
                $sent = true;
                if ($result !== true) {
                    $success = false;
                    $errors[] = (string)$result;
                }
            }
        }

        $this->_lastMailError = implode('; ', array_filter($errors));
        unset($this->_comment); //销毁评论对象
        unset($this->_email); //销毁对象
        return $sent ? $success : true;
    }

    /**
     * 作者邮件信息
     * @return $this
     */
    private function authorMail()
    {
        $date = new \Typecho\Date($this->_comment->created);
        $status = [
            "approved" => '通过',
            "waiting"  => '待审',
            "spam"     => '垃圾'
        ];
        $search  = array(
            '{{siteTitle}}',
            '{{title}}',
            '{{author}}',
            '{{ip}}',
            '{{mail}}',
            '{{permalink}}',
            '{{manage}}',
            '{{text}}',
            '{{time}}',
            '{{status}}'
        );
        $replace = [
            $this->_options->title,
            $this->_comment->title,
            $this->_comment->author,
            $this->_comment->ip,
            $this->_comment->mail,
            $this->_comment->permalink,
            $this->_options->siteUrl . __TYPECHO_ADMIN_DIR__ . "manage-comments.php",
            $this->_comment->text,
            $date->format('Y-m-d H:i:s'),
            $status[$this->_comment->status]
        ];

        $this->_email->msgHtml = str_replace($search, $replace, $this->getTemplate('owner'));
        $this->_email->subject = str_replace($search, $replace, $this->_email->titleForOwner);
        $this->_email->altBody = "作者:" . $this->_comment->author . "\r\n链接:" . $this->_comment->permalink . "\r\n评论:\r\n" . $this->_comment->text;

        return $this;
    }

    /**
     * 访客邮件信息
     */
    public function guestMail()
    {
        $date = new \Typecho\Date($this->_comment->created);
        $search = [
            '{{siteTitle}}',
            '{{title}}',
            '{{author_p}}',
            '{{author}}',
            '{{permalink}}',
            '{{text}}',
            '{{text_p}}',
            '{{contactme}}',
            '{{time}}'
        ];
        $replace = [
            $this->_options->title,
            $this->_comment->title,
            $this->_comment->originalAuthor,
            $this->_comment->author,
            $this->_comment->permalink,
            $this->_comment->text,
            $this->_comment->originalText,
            $this->_comment->contactme,
            $date->format('Y-m-d H:i:s'),
        ];

        $this->_email->msgHtml = str_replace($search, $replace, $this->getTemplate('guest'));
        $this->_email->subject = str_replace($search, $replace, $this->_email->titleForGuest);
        $this->_email->altBody = "作者:" . $this->_comment->author . "\r\n链接:" . $this->_comment->permalink . "\r\n评论:\r\n" . $this->_comment->text;

        return $this;
    }

    /**
     * 发送邮件
     * 
     * @return bool|string|null
     */
    public function sendMail(): bool|string|NULL
    {
        /** 载入邮件组件 */
        if ((string)$this->_cfg->mode === 'resend') {
            return $this->sendByResend();
        }

        $mailer = new PHPMailer();
        $mailer->CharSet = 'UTF-8';
        $mailer->Encoding = 'base64';

        /** 选择发信模式 */
        switch ($this->_cfg->mode) {
            case 'mail':
                break;
            case 'sendmail':
                $mailer->IsSendmail();
                break;
            case 'smtp':
                $mailer->IsSMTP();
                if ($this->cfgEnabled('validate', 'validate')) $mailer->SMTPAuth = true;

                if ($this->cfgEnabled('validate', 'ssl')) {
                    $mailer->SMTPSecure = "ssl";
                } else if ($this->cfgEnabled('validate', 'tls')) {
                    $mailer->SMTPSecure = "tls";
                }

                $mailer->Host     = $this->_cfg->host;
                $mailer->Port     = $this->_cfg->port;
                $mailer->Username = $this->_cfg->user;
                $mailer->Password = $this->_cfg->pass;
                break;
        }

        $mailer->SetFrom($this->_email->from, $this->_email->fromName);
        if (isset($this->_email->replyTo) && isset($this->_email->replyToName)) $mailer->AddReplyTo($this->_email->replyTo, $this->_email->replyToName);
        $mailer->Subject = $this->_email->subject;
        $mailer->AltBody = $this->_email->altBody;
        if ($this->cfgEnabled('validate', 'solve544')) $mailer->AddCC($this->_email->from); // 躲避审查造成的 544 错误

        $mailer->MsgHTML($this->_email->msgHtml);
        $mailer->AddAddress($this->_email->reciver, $this->_email->reciverName);

        $result = $mailer->Send();
        if (!$result) $result = $mailer->ErrorInfo;

        $mailer->ClearAddresses();
        $mailer->ClearReplyTos();

        return $result;
    }

    private function sendByResend(): bool|string
    {
        $apiKey = trim((string)($this->_cfg->resendApiKey ?? ''));
        $from = trim((string)($this->_cfg->resendFrom ?? $this->_email->from));
        $endpoint = trim((string)($this->_cfg->resendApiUrl ?? ''));
        $endpoint = $endpoint ?: 'https://api.resend.com/emails';

        if ($apiKey === '') return 'Resend API Key 不能为空';
        if ($from === '') return 'Resend 发件邮箱不能为空';
        if (empty($this->_email->reciver)) return '收件人邮箱不能为空';
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) return 'Resend API 地址格式不正确';
        if (stripos($endpoint, 'https://') !== 0) return 'Resend API 地址必须使用 HTTPS';

        $payload = [
            'from' => $this->formatResendFrom($from, $this->_email->fromName),
            'to' => [$this->_email->reciver],
            'subject' => $this->_email->subject,
            'html' => $this->_email->msgHtml,
            'text' => $this->_email->altBody,
        ];

        if (!empty($this->_email->replyTo)) {
            $payload['reply_to'] = [$this->_email->replyTo];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) return 'Resend 请求内容编码失败';

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $body,
            ]);

            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) return 'Resend 请求失败: ' . $error;
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                    ]),
                    'content' => $body,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
            ]);
            $response = file_get_contents($endpoint, false, $context);
            $status = $this->httpStatusFromHeaders($http_response_header ?? []);
        }

        if ($status >= 200 && $status < 300) {
            return true;
        }

        return 'Resend 返回错误(' . $status . '): ' . (string)$response;
    }

    private function formatResendFrom(string $email, string $name = ''): string
    {
        $email = trim($email);
        $name = trim($name);
        if ($name === '') return $email;

        $name = str_replace(['"', "\r", "\n"], ['', '', ''], $name);
        return $name . ' <' . $email . '>';
    }

    private function httpStatusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    private function decodeQueuedComment(string $payload): ?Comment
    {
        $content = base64_decode($payload, true);
        if ($content === false) return null;

        $comment = @unserialize($content, [
            'allowed_classes' => [Comment::class, 'stdClass']
        ]);

        if ($comment instanceof Comment) {
            return $comment;
        }

        if (is_object($comment)) {
            return $this->hydrateLegacyComment($comment);
        }

        return null;
    }

    private function hydrateLegacyComment(object $legacy): Comment
    {
        $comment = new Comment();
        $comment->cid = (int)($legacy->cid ?? 0);
        $comment->coid = (int)($legacy->coid ?? 0);
        $comment->created = (int)($legacy->created ?? time());
        $comment->author = (string)($legacy->author ?? '');
        $comment->authorId = (int)($legacy->authorId ?? 0);
        $comment->ownerId = (int)($legacy->ownerId ?? 0);
        $comment->mail = (string)($legacy->mail ?? '');
        $comment->ip = (string)($legacy->ip ?? '');
        $comment->title = (string)($legacy->title ?? '');
        $comment->text = (string)($legacy->text ?? '');
        $comment->permalink = (string)($legacy->permalink ?? '');
        $comment->status = (string)($legacy->status ?? 'approved');
        $comment->parent = (string)($legacy->parent ?? '0');
        $comment->type = (string)($legacy->type ?? '2');

        return $comment;
    }

    private function markQueueSent(int $id): void
    {
        $this->_db->query($this->_db->update($this->_prefix . 'mail')->rows([
            'sent' => 1,
            'updated' => time(),
            'last_error' => '',
            'next_retry' => 0,
            'locked_until' => 0,
        ])->where('id = ?', $id));
    }

    private function markQueueFailed(int $id, int $attempts, string $error): void
    {
        $attempts++;
        $maxAttempts = $this->maxAttempts();
        $failed = $attempts >= $maxAttempts;
        $backoff = min(3600, 60 * (2 ** max(0, $attempts - 1)));

        $this->_db->query($this->_db->update($this->_prefix . 'mail')->rows([
            'sent' => $failed ? 2 : 0,
            'updated' => time(),
            'attempts' => $attempts,
            'last_error' => $this->shortText($error, 2000),
            'next_retry' => $failed ? 0 : time() + $backoff,
            'locked_until' => 0,
        ])->where('id = ?', $id));
    }

    private function claimQueueRow(int $id): bool
    {
        $now = time();
        $lockedUntil = $now + 300;
        $affected = $this->_db->query($this->_db->update($this->_prefix . 'mail')->rows([
            'locked_until' => $lockedUntil,
        ])->where('id = ? AND sent = ? AND (locked_until IS NULL OR locked_until <= ?)', $id, 0, $now));

        return (int)$affected > 0;
    }

    private function cleanupOldLogs(): void
    {
        $days = (int)($this->_cfg->logKeepDays ?? 30);
        if ($days < 1) return;

        $before = time() - ($days * 86400);
        $this->_db->query($this->_db->delete($this->_prefix . 'mail')->where('sent = ? AND updated > ? AND updated < ?', 1, 0, $before));
    }

    private function retryQueue(): void
    {
        $id = (int)$this->request->get('id', 0);
        $rows = [
            'sent' => 0,
            'updated' => time(),
            'attempts' => 0,
            'last_error' => '',
            'next_retry' => 0,
            'locked_until' => 0,
        ];

        $query = $this->_db->update($this->_prefix . 'mail')->rows($rows)->where('sent = ?', 2);
        if ($id > 0) {
            $query->where('id = ?', $id);
        }

        $this->_db->query($query);
        $this->widget('Widget_Notice')->set(_t('失败队列已重新加入待发送列表'), 'success');
        $this->response->goBack();
    }

    private function clearLogs(): void
    {
        $this->_db->query($this->_db->delete($this->_prefix . 'mail')->where('sent <> ?', 0));
        $this->widget('Widget_Notice')->set(_t('发送日志已清理'), 'success');
        $this->response->goBack();
    }

    private function queueLimit(): int
    {
        $limit = (int)($this->_cfg->batchSize ?? 10);
        return max(1, min(100, $limit));
    }

    private function maxAttempts(): int
    {
        $attempts = (int)($this->_cfg->maxAttempts ?? 5);
        return max(1, min(20, $attempts));
    }

    private function shortText(string $text, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }

        return substr($text, 0, $max);
    }

    /**
     * 获取邮件模板
     * 
     * @param string $type
     * @return string
     */
    public function getTemplate(string $template = 'owner'): string
    {
        $cfgKey = $template . 'Template';
        if (!empty($this->_cfg->{$cfgKey})) {
            return (string)$this->_cfg->{$cfgKey};
        }

        $filename = $this->_template_dir  . $template . '.html';

        if (!file_exists($filename)) {
            throw new \Typecho\Widget\Exception('模板文件' . $template . '不存在', 404);
        }

        return file_get_contents($filename);
    }

    /**
     * 邮件发送测试
     */
    public function testMail()
    {
        if (self::widget('TypechoPlugin\CommentToMail\Console')->testMailForm()->validate()) {
            $this->response->goBack();
        }

        $email = $this->request->from('toName', 'to', 'title', 'content');

        $this->_email = new Email();

        $this->_email->from = (string)(((string)$this->_cfg->mode === 'resend' && !empty($this->_cfg->resendFrom)) ? $this->_cfg->resendFrom : $this->_cfg->user);
        $this->_email->fromName = (string)($this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title);
        $this->_email->reciver = $email['to'] ? $email['to'] : $this->_user->mail;
        $this->_email->reciverName = $email['toName'] ? $email['toName'] : $this->_user->screenName;
        $this->_email->subject = $email['title'];
        $this->_email->altBody = $email['content'];
        $this->_email->msgHtml = $email['content'];

        $result = $this->sendMail();

        /** 提示信息 */
        $this->widget('\Widget\Notice')->set(
            $result === true ? _t('邮件发送成功') : _t('邮件发送失败: ' . $result),
            $result === true ? 'success' : 'notice'
        );

        /** 转向原页 */
        $this->response->goBack();
    }

    /**
     * 编辑模板文件
     * @param $file
     * @throws \Typecho\Widget\Exception
     */
    public function editTheme($file)
    {
        $path = $this->templatePath($file);

        if ($path && is_writeable($path)) {
            if (file_put_contents($path, (string)$this->request->content, LOCK_EX) !== false) {
                $this->widget('Widget_Notice')->set(_t("文件 %s 的更改已经保存", $file), 'success');
            } else {
                $this->widget('Widget_Notice')->set(_t("文件 %s 无法被写入", $file), 'error');
            }
            $this->response->goBack();
        } else {
            throw new \Typecho\Widget\Exception(_t('您编辑的模板文件不存在'));
        }
    }

    private function templatePath($file): ?string
    {
        $file = basename((string)$file);
        if (!preg_match('/^[A-Za-z0-9_.-]+\.html$/', $file)) {
            return null;
        }

        $path = realpath($this->_template_dir . $file);
        $dir = realpath($this->_template_dir);
        if ($path === false || $dir === false || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        return $path;
    }

    private function cfgArray(string $key): array
    {
        if (empty($this->_cfg->{$key})) {
            return [];
        }
        return is_array($this->_cfg->{$key}) ? $this->_cfg->{$key} : [$this->_cfg->{$key}];
    }

    private function cfgEnabled(string $key, string $value): bool
    {
        return in_array($value, $this->cfgArray($key), true);
    }
}
