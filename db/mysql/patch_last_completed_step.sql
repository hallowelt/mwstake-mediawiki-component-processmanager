-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: db/patch_last_completed_step.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  /*_*/processes
ADD  p_last_completed_step VARBINARY(128) DEFAULT NULL;