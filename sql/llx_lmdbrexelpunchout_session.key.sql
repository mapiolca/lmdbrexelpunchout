ALTER TABLE llx_lmdbrexelpunchout_session ADD UNIQUE INDEX uk_lmdbrexelpunchout_session_token (token_hash);
ALTER TABLE llx_lmdbrexelpunchout_session ADD INDEX idx_lmdbrexelpunchout_session_entity (entity);
ALTER TABLE llx_lmdbrexelpunchout_session ADD INDEX idx_lmdbrexelpunchout_session_order (fk_commandefourn);
ALTER TABLE llx_lmdbrexelpunchout_session ADD INDEX idx_lmdbrexelpunchout_session_soc (fk_soc);
ALTER TABLE llx_lmdbrexelpunchout_session ADD INDEX idx_lmdbrexelpunchout_session_user (fk_user);
ALTER TABLE llx_lmdbrexelpunchout_session ADD INDEX idx_lmdbrexelpunchout_session_status (entity, status);
ALTER TABLE llx_lmdbrexelpunchout_session ADD INDEX idx_lmdbrexelpunchout_session_validity (entity, date_validity);
