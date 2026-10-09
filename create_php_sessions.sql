-- Shared server-side PHP sessions for Boogie's Vercel deployment.
-- Run this once in Supabase Dashboard > SQL Editor.

CREATE SCHEMA IF NOT EXISTS private;
REVOKE ALL ON SCHEMA private FROM PUBLIC, anon, authenticated;
GRANT USAGE ON SCHEMA private TO postgres;

CREATE TABLE IF NOT EXISTS private.php_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    session_data TEXT NOT NULL,
    last_activity TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS php_sessions_last_activity_idx
    ON private.php_sessions (last_activity);

REVOKE ALL ON TABLE private.php_sessions FROM PUBLIC, anon, authenticated;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE private.php_sessions TO postgres;
