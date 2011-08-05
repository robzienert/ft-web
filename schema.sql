CREATE TABLE `fuckups` (
  `fuckup_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `who` varchar(255) NOT NULL DEFAULT '',
  `verb` varchar(40) NOT NULL DEFAULT '',
  `fuckup` text NOT NULL,
  `date_created` datetime NOT NULL,
  PRIMARY KEY (`fuckup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;