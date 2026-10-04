<?php
/**
 * Database schema (dbDelta format).
 *
 * @package Krivo\ChatFlow
 */

namespace Krivo\ChatFlow\Infrastructure\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the CREATE TABLE statements for dbDelta().
 *
 * dbDelta is picky: two spaces after PRIMARY KEY, one column per line, no trailing comma,
 * index prefixes for utf8mb4 columns, and JSON stored in longtext (MySQL 5.5.5 / MariaDB compatible).
 */
final class Schema {

	/**
	 * Returns all CREATE TABLE statements.
	 *
	 * @return string
	 */
	public static function sql(): string {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$flows   = Tables::flows();
		$leads   = Tables::leads();
		$runs    = Tables::runs();
		$stats   = Tables::stats();

		return "CREATE TABLE {$flows} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
  revision int(10) unsigned NOT NULL DEFAULT 0,
  config_draft longtext NOT NULL,
  config_published longtext NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  published_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset};
CREATE TABLE {$leads} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(36) NOT NULL,
  flow_id bigint(20) unsigned NOT NULL,
  flow_revision int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'new',
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(32) NOT NULL DEFAULT '',
  destination_key varchar(64) NOT NULL DEFAULT '',
  destination_label varchar(190) NOT NULL DEFAULT '',
  channel varchar(20) NOT NULL DEFAULT 'message',
  source varchar(20) NOT NULL DEFAULT 'widget',
  in_hours tinyint(1) NULL,
  device varchar(10) NOT NULL DEFAULT '',
  answers longtext NOT NULL,
  consent longtext NULL,
  page_url text NULL,
  page_title varchar(255) NOT NULL DEFAULT '',
  referrer text NULL,
  utm_source varchar(191) NOT NULL DEFAULT '',
  utm_medium varchar(191) NOT NULL DEFAULT '',
  utm_campaign varchar(191) NOT NULL DEFAULT '',
  utm_content varchar(191) NOT NULL DEFAULT '',
  utm_term varchar(191) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY flow_created (flow_id,created_at),
  KEY status_created (status,created_at),
  KEY email (email(100)),
  KEY created_at (created_at)
) {$charset};
CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  flow_id bigint(20) unsigned NOT NULL,
  lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
  trigger_key varchar(40) NOT NULL,
  action_id varchar(40) NOT NULL,
  action_type varchar(40) NOT NULL,
  context text NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
  next_attempt_at datetime NULL,
  locked_at datetime NULL,
  http_status smallint(5) unsigned NULL,
  error_code varchar(40) NOT NULL DEFAULT '',
  error_message varchar(255) NOT NULL DEFAULT '',
  duration_ms int(10) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  KEY queue (status,next_attempt_at),
  KEY lead_id (lead_id),
  KEY flow_created (flow_id,created_at),
  KEY created_at (created_at)
) {$charset};
CREATE TABLE {$stats} (
  flow_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  metric varchar(24) NOT NULL,
  total int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (flow_id,day,metric)
) {$charset};";
	}
}
