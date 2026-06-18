-- profile-app slice 2: reports table.
-- Receives spam/abuse signals from /u/<slug> pages. No admin UI yet — the
-- table is the UI.

BEGIN;

CREATE TABLE reports (
    id               BIGSERIAL PRIMARY KEY,
    target_type      TEXT NOT NULL CHECK (target_type IN ('profile','practice','credential')),
    target_id        BIGINT NOT NULL,
    reason           TEXT NOT NULL,
    body             TEXT,
    reporter_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    reporter_ip      INET,
    status           TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','actioned','dismissed')),
    admin_note       TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    actioned_at      TIMESTAMPTZ
);
CREATE INDEX idx_reports_target ON reports(target_type, target_id);
CREATE INDEX idx_reports_status ON reports(status, created_at DESC);
CREATE INDEX idx_reports_ip_recent ON reports(reporter_ip, created_at);

COMMIT;
