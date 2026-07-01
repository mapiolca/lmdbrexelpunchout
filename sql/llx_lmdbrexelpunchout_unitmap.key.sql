ALTER TABLE llx_lmdbrexelpunchout_unitmap ADD UNIQUE INDEX uk_lmdbrexelpunchout_unitmap (entity, supplier_unit);
ALTER TABLE llx_lmdbrexelpunchout_unitmap ADD INDEX idx_lmdbrexelpunchout_unitmap_unit (fk_unit);
