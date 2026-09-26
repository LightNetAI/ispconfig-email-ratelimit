-- =====================================================================
-- email_ratelimit - explicit external rate limits
--
-- This table is written by the ISPConfig panel and read by the server
-- processes (which need the master database grants declared in
-- install/master_grants.list), so it lives in master scope.
--
-- The per-domain / per-mailbox / server-wide settings are stored in columns
-- added to the core tables mail_domain, mail_user and server (see
-- install/installer.php), because those tables are already replicated to
-- every mail server by ISPConfig itself.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `email_ratelimit` (
  `ratelimit_id`      int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sys_userid`        int(11) unsigned NOT NULL DEFAULT 0,
  `sys_groupid`       int(11) unsigned NOT NULL DEFAULT 0,
  `sys_perm_user`     varchar(5) NOT NULL DEFAULT '',
  `sys_perm_group`    varchar(5) NOT NULL DEFAULT '',
  `sys_perm_other`    varchar(5) NOT NULL DEFAULT '',
  `server_id`         int(11) unsigned NOT NULL DEFAULT 0,
  `source`            varchar(255) NOT NULL DEFAULT '',
  `type`              enum('in','out','both') NOT NULL DEFAULT 'out',
  `interval`          int(11) unsigned NOT NULL DEFAULT 3600,
  `limit`             int(11) unsigned NOT NULL DEFAULT 0,
  `active`            enum('n','y') NOT NULL DEFAULT 'y',
  PRIMARY KEY (`ratelimit_id`),
  KEY `server_id` (`server_id`),
  KEY `source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
