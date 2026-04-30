<?php

namespace TypechoPlugin\CommentToMail;

use \Typecho\{Widget};
use \Typecho\Db;
use \Typecho\Widget\Helper\Form;
use \Typecho\Widget\Helper\Form\Element\{Text, Hidden, Submit, Textarea};
use \TypechoPlugin\CommentToMail\lib\Comment;

/**
 * CommentToMail
 * Typecho 异步评论邮件提醒插件
 * 
 * @copyright  Copyright (c) 2022 xcsoft
 * @license    GNU General Public License 3.0
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Console
 * 
 * @package CommentToMail
 */
class Console extends Widget
{
    /** 
     * 模板文件目录
     * 
     *  @var string 
     */
    private string $_template_dir = __DIR__ . '/template/';

    /** 
     * 当前文件
     * 
     * @var string  
     */
    private string $_currentFile;

    /**
     * 执行函数
     *
     * @return void
     * @throws \Typecho\Widget\Exception
     */
    public function execute()
    {
        $this->widget('Widget_User')->pass('administrator');
        $files = glob($this->_template_dir . '*.html');
        $this->_currentFile = basename((string)$this->request->get('file', 'owner.html'));

        if (preg_match('/^[A-Za-z0-9_.-]+\.html$/', $this->_currentFile) && file_exists($this->_template_dir . $this->_currentFile)) {
            foreach ($files as $file) {
                if (!file_exists($file)) continue;
                $file = basename($file);
                $this->push(array(
                    'file'      =>  $file,
                    'current'   => ($file == $this->_currentFile)
                ));
            }
            return;
        }
        throw new \Typecho\Widget\Exception('模板文件不存在', 404);
    }

    /**
     * 获取菜单标题
     *
     * @return string
     */
    public function getMenuTitle(): string
    {
        return _t('编辑文件 %s', $this->_currentFile);
    }

    /**
     * 获取文件内容
     *
     * @return string
     */
    public function currentContent(): string
    {
        return htmlspecialchars(file_get_contents($this->_template_dir . $this->_currentFile), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 获取文件是否可读
     *
     * @return bool
     */
    public function currentIsWriteable(): bool
    {
        return is_writeable($this->_template_dir . $this->_currentFile);
    }

    /**
     * 获取当前文件
     *
     * @return string
     */
    public function currentFile(): string
    {
        return $this->_currentFile;
    }

    public function queueStats(): array
    {
        Plugin::ensureQueueTable();

        $db = Db::get();
        $prefix = $db->getPrefix();
        $stats = [
            0 => 0,
            1 => 0,
            2 => 0,
        ];

        $rows = $db->fetchAll("SELECT sent, COUNT(*) AS total FROM {$prefix}mail GROUP BY sent");
        foreach ($rows as $row) {
            $stats[(int)$row['sent']] = (int)$row['total'];
        }

        return $stats;
    }

    public function queueRows(int $limit = 50): array
    {
        Plugin::ensureQueueTable();

        $db = Db::get();
        $prefix = $db->getPrefix();
        $limit = max(1, min(100, $limit));
        $rows = $db->fetchAll("SELECT id, content, sent, created, updated, attempts, last_error, next_retry FROM {$prefix}mail ORDER BY id DESC LIMIT {$limit}");

        foreach ($rows as &$row) {
            $comment = $this->decodeComment((string)$row['content']);
            $row['summary'] = $comment ? [
                'author' => $comment->author,
                'mail' => $comment->mail,
                'title' => $comment->title,
                'permalink' => $comment->permalink,
            ] : [
                'author' => '',
                'mail' => '',
                'title' => '队列内容无法解析',
                'permalink' => '',
            ];
        }

        return $rows;
    }

    public function statusLabel(int $status): string
    {
        return [
            0 => '待发送',
            1 => '已发送',
            2 => '失败',
        ][$status] ?? '未知';
    }

    public function formatTime($timestamp): string
    {
        $timestamp = (int)$timestamp;
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-';
    }

    private function decodeComment(string $payload): ?Comment
    {
        $content = base64_decode($payload, true);
        if ($content === false) return null;

        $comment = @unserialize($content, [
            'allowed_classes' => [Comment::class, 'stdClass']
        ]);

        if ($comment instanceof Comment) return $comment;
        if (!is_object($comment)) return null;

        $hydrated = new Comment();
        $hydrated->cid = (int)($comment->cid ?? 0);
        $hydrated->coid = (int)($comment->coid ?? 0);
        $hydrated->created = (int)($comment->created ?? time());
        $hydrated->author = (string)($comment->author ?? '');
        $hydrated->authorId = (int)($comment->authorId ?? 0);
        $hydrated->ownerId = (int)($comment->ownerId ?? 0);
        $hydrated->mail = (string)($comment->mail ?? '');
        $hydrated->ip = (string)($comment->ip ?? '');
        $hydrated->title = (string)($comment->title ?? '');
        $hydrated->text = (string)($comment->text ?? '');
        $hydrated->permalink = (string)($comment->permalink ?? '');
        $hydrated->status = (string)($comment->status ?? 'approved');
        $hydrated->parent = (string)($comment->parent ?? '0');
        $hydrated->type = (string)($comment->type ?? '2');

        return $hydrated;
    }

    /**
     * 邮件测试表单
     * 
     * @return Form
     */
    public function testMailForm(): Form
    {
        /** 构建表单 */
        $options = Widget::widget('Widget_Options');
        $form = new Form(
            \Typecho\Common::url('/action/' . Plugin::$_action, $options->index),
            Form::POST_METHOD
        );

        /** 收件人名称 */
        $toName = new Text('toName', NULL, NULL, _t('收件人名称'), _t('为空则使用博主昵称'));
        $form->addInput($toName);

        /** 收件人邮箱 */
        $to = new Text('to', NULL, NULL, _t('收件人邮箱'), _t('为空则使用博主邮箱'));
        $form->addInput($to);

        /** 邮件标题 */
        $title = new Text('title', NULL, NULL, _t('邮件标题 *'));
        $form->addInput($title);

        /** 邮件内容 */
        $content = new Textarea('content', NULL, NULL, _t('邮件内容 *'));
        $content->input->setAttribute('class', 'w-100 mono');
        $form->addInput($content);

        /** 动作 */
        $do = new Hidden('do');
        $form->addInput($do);

        /** 提交按钮 */
        $submit = new Submit();
        $submit->input->setAttribute('class', 'btn primary');
        $form->addItem($submit);

        /** 设置值 */
        $do->value('testMail');
        $submit->value('发送邮件');

        /** 添加规则 */
        $to->addRule('email', _t('非法的邮件地址'));
        $title->addRule('required', _t('邮件标题不能为空'));
        $content->addRule('required', _t('邮件内容不能为空'));

        return $form;
    }
}
