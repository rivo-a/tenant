-- Audit log: business context (caretaker whose business an action affected)
-- Existing rows keep owner_admin_id NULL and stay readable.

ALTER TABLE audit_logs ADD COLUMN owner_admin_id INTEGER DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_audit_owner ON audit_logs(owner_admin_id);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
