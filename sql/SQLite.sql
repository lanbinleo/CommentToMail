CREATE TABLE IF NOT EXISTS `typecho_mail` (
  `id` INTEGER NOT NULL PRIMARY KEY,
  `content` text NOT NULL,
  `sent` int(1) default 0,
  `created` int(10) default 0,
  `updated` int(10) default 0,
  `attempts` int(10) default 0,
  `last_error` text NULL,
  `next_retry` int(10) default 0,
  `locked_until` int(10) default 0
);
