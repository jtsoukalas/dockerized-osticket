/**
 * @signature 3985c33fdcd5dfb07b18f9f0c7ec87a1
 * @version v1.18.5
 * @title Add help topic filter and delivery retries to webhooks
 */

-- 1. Help topic filter (comma separated topic ids, empty = all topics),
--    retry limit per webhook and the internal note event
ALTER TABLE `%TABLE_PREFIX%webhook`
    ADD COLUMN `topic_ids` text AFTER `timeout`,
    ADD COLUMN `max_retries` tinyint(3) unsigned NOT NULL DEFAULT '3' AFTER `topic_ids`,
    ADD COLUMN `event_staff_note` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `event_client_reply`;

-- 2. Queue of failed deliveries waiting to be retried by cron
CREATE TABLE IF NOT EXISTS `%TABLE_PREFIX%webhook_delivery` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `webhook_id` int(11) unsigned NOT NULL,
  `event` varchar(64) NOT NULL,
  `ticket_id` int(11) unsigned NOT NULL DEFAULT '0',
  `payload` mediumtext NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `response_code` smallint(5) unsigned NOT NULL DEFAULT '0',
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `next_attempt` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`, `next_attempt`),
  KEY `webhook_id` (`webhook_id`)
) DEFAULT CHARSET=utf8;

-- 3. Update the Schema Signature
UPDATE `%TABLE_PREFIX%config`
    SET `value` = 'a2cc43cb13f0a52b0b3fdaf8fddb6720'
    WHERE `key` = 'schema_signature' AND `namespace` = 'core';
