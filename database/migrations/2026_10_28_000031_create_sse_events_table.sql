-- Migration: Create SSE Events Table for Real-Time Server-Sent Events Push Bus
-- SPEC-036: Universal Server-Sent Events (SSE) Push Bus & Real-Time Broadcast

CREATE TABLE IF NOT EXISTS sse_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NULL,
    channel TEXT NOT NULL,
    event TEXT NOT NULL,
    payload TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sse_events_lookup ON sse_events(channel, id);
CREATE INDEX IF NOT EXISTS idx_sse_events_tenant ON sse_events(tenant_id, channel, id);
CREATE INDEX IF NOT EXISTS idx_sse_events_created_at ON sse_events(created_at);
