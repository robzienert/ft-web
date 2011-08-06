CREATE TABLE `fuckup_votes` (
  `fuckup_id` int(11) unsigned NOT NULL,
  `client_ip` int(11) unsigned NOT NULL,
  `value` tinyint(1) NOT NULL COMMENT '+1: up, -1: down',
  `date_created` datetime not null default '0000-00-00 00:00:00',
  PRIMARY KEY (`fuckup_id`,`client_ip`),
  CONSTRAINT `fuckup_votes_ibfk_1` FOREIGN KEY (`fuckup_id`) REFERENCES `fuckups` (`fuckup_id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `fuckups` ADD COLUMN `votes_up` int(11) NOT NULL DEFAULT '0' AFTER `fuckup`;
ALTER TABLE `fuckups` ADD COLUMN `votes_down` int(11) NOT NULL DEFAULT '0' AFTER `votes_up`;
