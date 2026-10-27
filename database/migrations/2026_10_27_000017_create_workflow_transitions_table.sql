-- Up
CREATE TABLE IF NOT EXISTS workflow_transitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    from_status VARCHAR(64) NOT NULL,
    to_status VARCHAR(64) NOT NULL,
    transition_name VARCHAR(64) NULL,
    actor_id INTEGER NULL,
    actor_name VARCHAR(255) NULL,
    notes TEXT NULL,
    metadata TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_workflow_transitions_lookup ON workflow_transitions(tenant_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_workflow_transitions_created ON workflow_transitions(tenant_id, created_at);

-- Down
DROP TABLE IF EXISTS workflow_transitions;
