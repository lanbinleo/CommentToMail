<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * CommentToMail
 * Typecho 异步评论邮件提醒插件
 * 
 * @copyright  Copyright (c) 2022 xcsoft
 * @license    GNU General Public License 3.0
 */

require_once 'header.php';
require_once 'menu.php';

use \Typecho\Widget;
use \TypechoPlugin\CommentToMail\Plugin;

$current = $request->get('act', 'index');
$theme = basename((string)$request->get('file', 'owner.html'));
$title = $current == 'index' ? $menu->title : ($current == 'queue' ? '队列与日志' : '编辑邮件模板 ' . $theme);
?>
<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        </div>
        <div class="row typecho-page-main" role="main">
            <!-- MENU -->
            <div class="col-mb-12">
                <ul class="typecho-option-tabs fix-tabs clearfix">
                    <li <?= ($current == 'index' ? ' class="current"' : '') ?>>
                        <a href="<?php $options->adminUrl('extending.php?panel=' . Plugin::$_panel); ?>"><?php _e('邮件发送测试'); ?></a>
                    </li>
                    <li <?= ($current == 'theme' ? ' class="current"' : '') ?>>
                        <a href="<?php $options->adminUrl('extending.php?panel=' . Plugin::$_panel . '&act=theme'); ?>">
                            <?php _e('编辑邮件模板'); ?>
                        </a>
                    </li>
                    <li <?= ($current == 'queue' ? ' class="current"' : '') ?>>
                        <a href="<?php $options->adminUrl('extending.php?panel=' . Plugin::$_panel . '&act=queue'); ?>">
                            <?php _e('队列与日志'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="<?php $options->adminUrl('options-plugin.php?config=CommentToMail') ?>"><?php _e('插件设置'); ?></a>
                    </li>
                </ul>
            </div>
            <?php
            if ($current == 'index') :
            ?>
                <div class="typecho-edit-theme">
                    <div class="col-mb-12 col-tb-8 col-9 content">
                        <?php Widget::widget('TypechoPlugin\CommentToMail\Console')->testMailForm()->render(); ?>
                    </div>
                </div>
            <?php
            elseif ($current == 'queue') :
                $console = Widget::widget('TypechoPlugin\CommentToMail\Console');
                $stats = $console->queueStats();
                $rows = $console->queueRows();
            ?>
                <div class="col-mb-12">
                    <p>
                        待发送: <strong><?php echo (int)$stats[0]; ?></strong>
                        &nbsp; 已发送: <strong><?php echo (int)$stats[1]; ?></strong>
                        &nbsp; 失败: <strong><?php echo (int)$stats[2]; ?></strong>
                    </p>
                    <p>
                        <a class="btn primary" href="<?php $options->index('/action/' . Plugin::$_action . '?do=runQueue'); ?>">立即处理队列</a>
                        <a class="btn" href="<?php $options->index('/action/' . Plugin::$_action . '?do=retryQueue'); ?>">重试全部失败</a>
                        <a class="btn" href="<?php $options->index('/action/' . Plugin::$_action . '?do=clearLogs'); ?>" onclick="return confirm('确定清理已完成和失败的发送日志吗？待发送队列不会被删除。');">清理日志</a>
                    </p>
                    <table class="typecho-list-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>状态</th>
                                <th>文章 / 收件线索</th>
                                <th>次数</th>
                                <th>创建时间</th>
                                <th>更新时间</th>
                                <th>下次重试</th>
                                <th>错误</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows) : ?>
                                <tr><td colspan="9">暂无队列或日志</td></tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td><?php echo (int)$row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($console->statusLabel((int)$row['sent']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if (!empty($row['summary']['permalink'])) : ?>
                                            <a href="<?php echo htmlspecialchars($row['summary']['permalink'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank">
                                                <?php echo htmlspecialchars($row['summary']['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                            </a>
                                        <?php else : ?>
                                            <?php echo htmlspecialchars($row['summary']['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        <?php endif; ?>
                                        <br>
                                        <small><?php echo htmlspecialchars($row['summary']['author'] . ' <' . $row['summary']['mail'] . '>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></small>
                                    </td>
                                    <td><?php echo (int)$row['attempts']; ?></td>
                                    <td><?php echo htmlspecialchars($console->formatTime($row['created']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($console->formatTime($row['updated']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($console->formatTime($row['next_retry']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td style="max-width: 260px; word-break: break-all;"><?php echo htmlspecialchars((string)$row['last_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if ((int)$row['sent'] === 2) : ?>
                                            <a href="<?php $options->index('/action/' . Plugin::$_action . '?do=retryQueue&id=' . (int)$row['id']); ?>">重试</a>
                                        <?php else : ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php
            else :
                Widget::widget('TypechoPlugin\CommentToMail\Console')->to($files);
            ?>
                <div class="typecho-edit-theme">
                    <div class="col-mb-12 col-tb-8 col-9 content">
                        <form method="post" name="theme" id="theme" action="<?php $options->index('/action/' . Plugin::$_action); ?>">
                            <label for="content" class="sr-only"><?php _e('编辑源码'); ?></label>
                            <textarea name="content" id="content" class="w-100 mono" <?php if (!$files->currentIsWriteable()) echo 'readonly'; ?>><?php echo $files->currentContent(); ?></textarea>
                            <p class="submit">
                                <?php if ($files->currentIsWriteable()) : ?>
                                    <input type="hidden" name="do" value="editTheme" />
                                    <input type="hidden" name="edit" value="<?php echo htmlspecialchars($files->currentFile(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
                                    <button type="submit" class="btn primary"><?php _e('保存文件'); ?></button>
                                <?php else : ?>
                                    <em><?php _e('文件无写入权限'); ?></em>
                                <?php endif; ?>
                            </p>
                        </form>
                    </div>
                    <ul class="col-mb-12 col-tb-4 col-3">
                        <li><strong>模板文件</strong></li>
                        <?php while ($files->next()) : ?>
                            <li <?php if ($files->current) echo "class='current'"; ?>>
                                <a href="<?php $options->adminUrl('extending.php?panel=' . Plugin::$_panel . '&act=theme' . '&file=' . rawurlencode($files->file)); ?>">
                                    <?php $files->file(); ?>
                                </a>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once 'copyright.php';
require_once 'common-js.php';
require_once 'footer.php';
?>
