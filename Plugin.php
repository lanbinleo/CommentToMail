<?php

namespace TypechoPlugin\CommentToMail;

use \Typecho\Plugin\PluginInterface;
use \Utils\Helper;
use \Typecho\{Widget, Db};
use \Typecho\Widget\Helper\Form\Element\{Password, Text, Radio, Checkbox, Textarea};

/**
 * 异步评论邮件提醒插件
 *
 * @package CommentToMail
 * @author  xcsoft
 * @version 1.2.9
 * @link https://xsot.cn
 * @LastEditDate 20240722
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once 'Log.php';

/**
 * Plugin
 * 
 * @package CommentToMail
 */
class Plugin implements PluginInterface
{
	/** 
	 * action name
	 * 
	 * @var string 
	 */
	public static $_action = 'comment-to-mail';

	/** 
	 * @var string
	 */
	public static $_panel  = 'CommentToMail/page/console.php';

	private static bool $_queueTableReady = false;

	/**
	 * 激活插件方法,如果激活失败,直接抛出异常
	 *
	 * @access public
	 * @return void
	 * @throws \Typecho\Plugin\Exception
	 */
	public static function activate()
	{
		$msg = self::dbInstall();
		\Typecho\Plugin::factory('\Widget\Feedback')->finishComment = ['TypechoPlugin\CommentToMail\Plugin', 'parseComment'];
		\Typecho\Plugin::factory('\Widget\Comments\Edit')->finishComment = ['TypechoPlugin\CommentToMail\Plugin', 'parseComment'];
		\Typecho\Plugin::factory('\Widget\Comments\Edit')->mark = ['TypechoPlugin\CommentToMail\Plugin', 'passComment'];

		Helper::addAction(self::$_action, 'TypechoPlugin\CommentToMail\Action');
		Helper::addPanel(1, self::$_panel, '评论邮件提醒', '评论邮件提醒控制台', 'administrator');
		return _t($msg);
	}

	/**
	 * 禁用插件
	 *
	 * @return void
	 * @throws \Typecho\Plugin\Exception
	 */
	public static function deactivate()
	{
		Helper::removeAction(self::$_action);
		Helper::removePanel(1, self::$_panel);
	}

	/**
	 * 获取插件配置面板
	 *
	 * @param \Typecho\Widget\Helper\Form $form 配置面板
	 * @return void
	 */
	public static function config(\Typecho\Widget\Helper\Form $form)
	{
		$options = Widget::widget('Widget_Options');

		$mode = new Radio(
			'mode',
			[
				'smtp' => 'smtp',
				'resend' => 'Resend API',
				'mail' => 'mail()',
				'sendmail' => 'sendmail()'
			],
			'smtp',
			'发信方式'
		);
		$form->addInput($mode);

		$host = new Text(
			'host',
			NULL,
			'',
			_t('SMTP地址'),
			_t('使用 SMTP 时填写 SMTP 服务器地址。使用 Resend API 时可留空。')
		);
		$form->addInput($host);

		$port = new Text(
			'port',
			NULL,
			'25',
			_t('SMTP端口'),
			_t('SMTP服务端口, 一般为25. SSL一般为465')
		);
		$port->input->setAttribute('class', 'mini');
		$form->addInput($port->addRule('isInteger', _t('端口号必须为数字')));

		$user = new Text(
			'user',
			NULL,
			NULL,
			_t('SMTP用户'),
			_t('SMTP服务验证用户名,一般为邮箱账户。使用 SMTP 时也会作为默认发件邮箱。')
		);
		$form->addInput($user);

		$pass = new Password(
			'pass',
			NULL,
			NULL,
			_t('SMTP密码')
		);
		$form->addInput($pass);

		$validate = new Checkbox(
			'validate',
			[
				'validate' => '服务器需要验证',
				'ssl' => 'ssl加密',
				'tls' => 'tls加密',
				'solve544' => '启用抄送以规避544错误'
			],
			['validate'],
			'SMTP验证'
		);
		$form->addInput($validate);

		$resendApiKey = new Password(
			'resendApiKey',
			NULL,
			NULL,
			_t('Resend API Key'),
			_t('发信方式选择 Resend API 时填写, 例如 re_xxxxxxxxx。')
		);
		$form->addInput($resendApiKey);

		$resendFrom = new Text(
			'resendFrom',
			NULL,
			NULL,
			_t('Resend 发件邮箱'),
			_t('必须是 Resend 已验证域名下的邮箱地址, 例如 no-reply@example.com。发件人名称使用下方“发件人名称”。')
		);
		$form->addInput($resendFrom->addRule('email', _t('请填写正确的 Resend 发件邮箱!')));

		$resendApiUrl = new Text(
			'resendApiUrl',
			NULL,
			'https://api.resend.com/emails',
			_t('Resend API 地址'),
			_t('默认即可。如需代理或自建网关, 请填写完整 HTTPS 地址。')
		);
		$form->addInput($resendApiUrl);

		$fromName = new Text(
			'fromName',
			NULL,
			NULL,
			_t('发件人名称'),
			_t('发件人名称, 留空则使用博客标题')
		);
		$form->addInput($fromName);

		$mail = new Text(
			'mail',
			NULL,
			NULL,
			_t('接收邮件的地址'),
			_t('接收邮件的地址,如为空则使用文章作者个人设置中的邮件地址!')
		);
		$form->addInput($mail->addRule('email', _t('请填写正确的邮件地址!')));

		$contactme = new Text(
			'contactme',
			NULL,
			NULL,
			_t('模板中“联系我”的邮件地址'),
			_t('联系我用的邮件地址,如为空则使用文章作者个人设置中的邮件地址!')
		);
		$form->addInput($contactme->addRule('email', _t('请填写正确的邮件地址!')));

		$titleForOwner = new Text(
			'titleForOwner',
			null,
			"[{{title}}] 一文有新的评论",
			_t('博主接收邮件标题')
		);
		$form->addInput($titleForOwner->addRule('required', _t('博主接收邮件标题 不能为空')));

		$titleForGuest = new Text(
			'titleForGuest',
			null,
			"您在 [{{title}}] 的评论有了回复",
			_t('访客接收邮件标题')
		);
		$form->addInput($titleForGuest->addRule('required', _t('访客接收邮件标题 不能为空')));

		$templateHelp = _t('支持变量: {{siteTitle}}, {{title}}, {{author}}, {{author_p}}, {{ip}}, {{mail}}, {{permalink}}, {{manage}}, {{text}}, {{text_p}}, {{contactme}}, {{time}}, {{status}}。留空时使用插件 template 目录中的默认模板。');

		$ownerTemplate = new Textarea(
			'ownerTemplate',
			null,
			self::defaultTemplate('owner'),
			_t('博主通知邮件模板'),
			$templateHelp
		);
		$ownerTemplate->input->setAttribute('class', 'w-100 mono');
		$form->addInput($ownerTemplate);

		$guestTemplate = new Textarea(
			'guestTemplate',
			null,
			self::defaultTemplate('guest'),
			_t('访客回复通知邮件模板'),
			$templateHelp
		);
		$guestTemplate->input->setAttribute('class', 'w-100 mono');
		$form->addInput($guestTemplate);

		$status = new Checkbox(
			'status',
			[
				'approved' => '提醒已通过评论',
				'waiting' => '提醒待审核评论',
				'spam' => '提醒垃圾评论'
			],
			['approved', 'waiting'],
			'提醒设置',
			_t('该选项仅针对博主, 访客只发送已通过的评论。')
		);
		$form->addInput($status);

		$other = new Checkbox(
			'other',
			[
				'to_owner' => '有评论及回复时, 发邮件通知博主.',
				'to_guest' => '评论被回复时, 发邮件通知评论者.',
				'to_me' => '自己回复自己的评论时, 发邮件通知. (同时针对博主和访客)',
				'isSync' => '评论提交后异步触发发送队列',
			],
			['to_owner', 'to_guest'],
			'其他设置',
			NULL
		);
		$form->addInput($other->multiMode());

		$batchSize = new Text(
			'batchSize',
			null,
			'10',
			_t('每次发送队列数量'),
			_t('异步触发或定时任务每次最多处理多少封邮件。')
		);
		$batchSize->input->setAttribute('class', 'mini');
		$form->addInput($batchSize->addRule('isInteger', _t('每次发送队列数量必须为数字')));

		$maxAttempts = new Text(
			'maxAttempts',
			null,
			'5',
			_t('最大重试次数'),
			_t('超过次数后任务标记为失败, 可在后台手动重试。')
		);
		$maxAttempts->input->setAttribute('class', 'mini');
		$form->addInput($maxAttempts->addRule('isInteger', _t('最大重试次数必须为数字')));

		$logKeepDays = new Text(
			'logKeepDays',
			null,
			'30',
			_t('日志保留天数'),
			_t('成功发送记录会保留指定天数, 失败记录会一直保留到手动清理或重试成功。')
		);
		$logKeepDays->input->setAttribute('class', 'mini');
		$form->addInput($logKeepDays->addRule('isInteger', _t('日志保留天数必须为数字')));


		$entryUrl = ($options->rewrite) ? $options->siteUrl : $options->siteUrl . 'index.php'; // 博客网址

		$deliverMailUrl = rtrim($entryUrl, '/') . '/action/' . self::$_action . '?do=deliverMail&key={KEY}';
		$key = new Text(
			'key',
			null,
			\Typecho\Common::randString(16),
			_t('Key'),
			_t('执行发送任务地址为' . $deliverMailUrl)
		);
		$form->addInput($key->addRule('required', _t('key 不能为空.')));
	}

	/**
	 * 个人用户的配置面板
	 *
	 * @param \Typecho\Widget\Helper\Form $form
	 * @return void
	 */
	public static function personalConfig(\Typecho\Widget\Helper\Form $form)
	{
	}

	private static function defaultTemplate(string $name): string
	{
		$file = __DIR__ . '/template/' . $name . '.html';
		return file_exists($file) ? file_get_contents($file) : '';
	}

	public static function ensureQueueTable(): void
	{
		if (self::$_queueTableReady) return;

		self::dbInstall();
		self::$_queueTableReady = true;
	}

	private static function migrateQueueTable(Db $db, string $prefix, string $type): void
	{
		$table = $prefix . 'mail';
		$columns = [
			'created' => [
				'Mysql' => "int(10) unsigned DEFAULT '0'",
				'SQLite' => 'int(10) default 0',
				'Pgsql' => 'int DEFAULT 0',
			],
			'updated' => [
				'Mysql' => "int(10) unsigned DEFAULT '0'",
				'SQLite' => 'int(10) default 0',
				'Pgsql' => 'int DEFAULT 0',
			],
			'attempts' => [
				'Mysql' => "int(10) unsigned DEFAULT '0'",
				'SQLite' => 'int(10) default 0',
				'Pgsql' => 'int DEFAULT 0',
			],
			'last_error' => [
				'Mysql' => 'text NULL',
				'SQLite' => 'text NULL',
				'Pgsql' => 'text NULL',
			],
			'next_retry' => [
				'Mysql' => "int(10) unsigned DEFAULT '0'",
				'SQLite' => 'int(10) default 0',
				'Pgsql' => 'int DEFAULT 0',
			],
			'locked_until' => [
				'Mysql' => "int(10) unsigned DEFAULT '0'",
				'SQLite' => 'int(10) default 0',
				'Pgsql' => 'int DEFAULT 0',
			],
		];

		foreach ($columns as $column => $definitions) {
			try {
				$db->query("SELECT {$column} FROM {$table} LIMIT 1", Db::READ);
			} catch (\Typecho\Db\Exception $e) {
				$db->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definitions[$type]}", Db::WRITE);
			}
		}

		$now = time();
		$db->query("UPDATE {$table} SET created = {$now} WHERE created IS NULL OR created = 0", Db::WRITE);
		$db->query("UPDATE {$table} SET updated = created WHERE updated IS NULL OR updated = 0", Db::WRITE);
	}

	/**
	 * 建立 邮件队列 数据表
	 */
	public static function dbInstall()
	{
		self::$_queueTableReady = false;
		$installDb = Db::get();

		$adapter = explode('_', $installDb->getAdapterName());
		$adapter_typ = array_pop($adapter); //数据库类型 mysql/sqlite/postgres
		$type = $adapter_typ === "Mysqli" ? "Mysql" : $adapter_typ;
		$supported_adapter = ["Mysql", "Pgsql", "SQLite"];
        if (!in_array($type, $supported_adapter)) {
            throw new \Typecho\Plugin\Exception('数据表建立失败, 不支持的数据库驱动, (仅支持 Mysql, SQLite, PgSQL)');
        }

		$prefix = $installDb->getPrefix(); //表前缀

		$scripts = file_get_contents(__DIR__ . '/sql/' . $type . '.sql');
		$scripts = str_replace('typecho_', $prefix, $scripts);
		$scripts = str_replace('%charset%', 'utf8', $scripts);
		$scripts = explode(';', $scripts);
		try {
			foreach ($scripts as $script) {
				$script = trim($script);
				if ($script) $installDb->query($script, Db::WRITE);
			}
			self::migrateQueueTable($installDb, $prefix, $type);
			self::$_queueTableReady = true;
			return '邮件队列表已准备完成, 请继续设置发信信息';
		} catch (\Typecho\Db\Exception $e) {
			$code = $e->getCode();
			if (($type === 'Mysql' && $code === 1050) || ($type === 'SQLite' && ($code === 'HY000' || $code === 1))) {
				try {
					$script = "SELECT id, content, sent FROM {$prefix}mail";
					$installDb->query($script, Db::READ);
					self::migrateQueueTable($installDb, $prefix, $type);
					self::$_queueTableReady = true;
					return '检测到旧版邮件队列表, 已完成兼容迁移';
				} catch (\Typecho\Db\Exception $e) {
					throw new \Typecho\Plugin\Exception('数据表检测失败, 插件启用失败。错误代码:' . $code);
				}
			} else {
				throw new \Typecho\Plugin\Exception('数据表建立失败, 插件启用失败。错误代码:' . $code);
			}
		}
	}

	/**
	 * 获取邮件内容(拦截评论)
	 *
	 * @param $comment 调用参数
	 * @return void
	 */
	public static function parseComment($comment)
	{
		self::ensureQueueTable();

		$commentClass = new \TypechoPlugin\CommentToMail\lib\Comment;

		$commentClass->cid = $comment->cid;
		$commentClass->coid = $comment->coid;
		$commentClass->created = $comment->created;
		$commentClass->ip = $comment->ip;
		$commentClass->author = $comment->author;
		$commentClass->mail = $comment->mail;
		$commentClass->authorId = $comment->authorId;
		$commentClass->ownerId = $comment->ownerId;
		$commentClass->title = $comment->title;
		$commentClass->text = $comment->text;
		$commentClass->permalink = $comment->permalink;
		$commentClass->status = $comment->status;
		$commentClass->parent = $comment->parent;
		$commentClass->type = $comment->type ?? '2';

		// 添加至队列
		$db = Db::get();
		$db->query(
			$db->insert($db->getPrefix() . 'mail')->rows([
				'content' => base64_encode(serialize($commentClass)),
				'sent' => '0',
				'created' => time(),
				'updated' => time(),
				'attempts' => 0,
				'last_error' => '',
				'next_retry' => 0,
				'locked_until' => 0,
			])
		);

		// 如果同步就直接发邮件，否则添加至队列
		$keySync = Helper::options()->plugin('CommentToMail')->key;
		$optionsSync = Widget::widget('Widget_Options');
		$entryUrlSync = ($optionsSync->rewrite) ? $optionsSync->siteUrl : $optionsSync->siteUrl . 'index.php'; // 博客网址
		$deliverUrlSync = rtrim($entryUrlSync, '/') . '/action/' . self::$_action . '?do=deliverMail&key=' . rawurlencode($keySync);;

		$isSync = Helper::options()->plugin('CommentToMail')->other;
		$isSync = is_array($isSync) ? $isSync : (empty($isSync) ? [] : [$isSync]);
		if (in_array('isSync', $isSync, true)){
			self::triggerQueueAsync($deliverUrlSync);
		}
	}

	private static function triggerQueueAsync(string $url): void
	{
		$parts = parse_url($url);
		if (empty($parts['host']) || empty($parts['scheme'])) return;

		$scheme = strtolower($parts['scheme']);
		$host = $parts['host'];
		$port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
		$target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		$transport = $scheme === 'https' ? 'ssl://' . $host : $host;

		$socket = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 1, STREAM_CLIENT_CONNECT);
		if (!$socket) return;

		stream_set_blocking($socket, false);
		fwrite($socket, "GET {$target} HTTP/1.1\r\nHost: {$host}\r\nConnection: Close\r\n\r\n");
		fclose($socket);
	}
	
	/**
	 * 通过邮件 博主 通过邮件后 回调函数
	 *
	 * @param $comment,$edit,$status 调用参数
	 * @return void
	 */
	public static function passComment($comment, $edit, $status)
	{
		// 邮件 状态未通过时 > 访客不会收到通知, 只有访客被评论 的评论状态 为 approved时 才会 notify
		if ($status !== 'approved') return;
		$edit->status = 'approved';
		$edit->type = '1'; //标记 approved后的邮件 仅发送给访客 避免重复发送给博主
		
		self::parseComment($edit);
	}
}
