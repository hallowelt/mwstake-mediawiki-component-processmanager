-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db/processes.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/processes (
  p_pid VARBINARY(128) NOT NULL,
  p_state VARBINARY(255) NOT NULL,
  p_exitcode TINYINT UNSIGNED DEFAULT NULL,
  p_exitstatus LONGTEXT DEFAULT NULL,
  p_started BINARY(14) DEFAULT NULL,
  p_timeout INT NOT NULL,
  p_output LONGTEXT DEFAULT NULL,
  p_steps LONGTEXT DEFAULT NULL
) /*$wgDBTableOptions*/;
