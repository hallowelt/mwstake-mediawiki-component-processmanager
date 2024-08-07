-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db/processes.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/processes (
  p_pid BLOB NOT NULL, p_state BLOB NOT NULL,
  p_exitcode SMALLINT UNSIGNED DEFAULT NULL,
  p_exitstatus CLOB DEFAULT '', p_started BLOB DEFAULT NULL,
  p_timeout INTEGER NOT NULL, p_output CLOB DEFAULT NULL,
  p_steps CLOB DEFAULT NULL, p_last_completed_step BLOB DEFAULT NULL
);
