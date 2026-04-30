CREATE TABLE IF NOT EXISTS `typecho_mail` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `content` text NOT NULL,
  `sent` int(1) DEFAULT '0',
  `created` int(10) unsigned DEFAULT '0',
  `updated` int(10) unsigned DEFAULT '0',
  `attempts` int(10) unsigned DEFAULT '0',
  `last_error` text NULL,
  `next_retry` int(10) unsigned DEFAULT '0',
  `locked_until` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8;
