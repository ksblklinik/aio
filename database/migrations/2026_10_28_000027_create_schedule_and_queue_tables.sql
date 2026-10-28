-- ============================================================================
-- Migration: 2026_10_28_000027_create_schedule_and_queue_tables.sql
-- Description: Universal resource schedules, appointments, and live human queue engine
-- ============================================================================

CREATE TABLE IF NOT EXISTS resource_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    staff_contact_id INTEGER NULL,
    room_id INTEGER NULL,
    service_item_id INTEGER NULL,
    day_of_week INTEGER NOT NULL, -- 0=Minggu, 1=Senin, ..., 6=Sabtu
    start_time TEXT NOT NULL,      -- '08:00'
    end_time TEXT NOT NULL,        -- '12:00'
    quota INTEGER NOT NULL DEFAULT 30,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (room_id) REFERENCES facilities_rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (service_item_id) REFERENCES items(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_resource_schedules_tenant_day ON resource_schedules(tenant_id, day_of_week);
CREATE INDEX IF NOT EXISTS idx_resource_schedules_tenant_staff ON resource_schedules(tenant_id, staff_contact_id);
CREATE INDEX IF NOT EXISTS idx_resource_schedules_tenant_room ON resource_schedules(tenant_id, room_id);

CREATE TABLE IF NOT EXISTS appointments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    appointment_number TEXT NOT NULL,
    client_contact_id INTEGER NULL,
    client_name TEXT NOT NULL,
    client_phone TEXT NULL,
    client_email TEXT NULL,
    staff_contact_id INTEGER NULL,
    room_id INTEGER NULL,
    service_item_id INTEGER NULL,
    appointment_date TEXT NOT NULL, -- 'YYYY-MM-DD'
    start_time TEXT NOT NULL,       -- 'HH:MM'
    end_time TEXT NULL,             -- 'HH:MM'
    status TEXT NOT NULL DEFAULT 'booked', -- 'booked', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'
    notes TEXT NULL,
    cancellation_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (client_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (room_id) REFERENCES facilities_rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (service_item_id) REFERENCES items(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_appointments_tenant_number ON appointments(tenant_id, appointment_number);
CREATE INDEX IF NOT EXISTS idx_appointments_tenant_date ON appointments(tenant_id, appointment_date);
CREATE INDEX IF NOT EXISTS idx_appointments_tenant_client ON appointments(tenant_id, client_contact_id);
CREATE INDEX IF NOT EXISTS idx_appointments_tenant_staff ON appointments(tenant_id, staff_contact_id);
CREATE INDEX IF NOT EXISTS idx_appointments_tenant_status ON appointments(tenant_id, status);

CREATE TABLE IF NOT EXISTS queue_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    ticket_number TEXT NOT NULL,   -- 'A-001', 'B-015', 'F-003'
    prefix TEXT NOT NULL DEFAULT 'A', -- 'A', 'B', 'F'
    sequence_number INTEGER NOT NULL,
    ticket_date TEXT NOT NULL,     -- 'YYYY-MM-DD'
    service_name TEXT NOT NULL DEFAULT 'Poli Umum',
    room_id INTEGER NULL,
    space_id INTEGER NULL,
    client_contact_id INTEGER NULL,
    client_name TEXT NOT NULL DEFAULT 'Pengunjung Umum',
    appointment_id INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'waiting', -- 'waiting', 'called', 'serving', 'transferred', 'completed', 'skipped'
    called_at TIMESTAMP NULL,
    serving_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    called_by INTEGER NULL,
    call_count INTEGER NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES facilities_rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (space_id) REFERENCES facilities_spaces(id) ON DELETE SET NULL,
    FOREIGN KEY (client_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (called_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_queue_tickets_tenant_date_prefix ON queue_tickets(tenant_id, ticket_date, prefix);
CREATE INDEX IF NOT EXISTS idx_queue_tickets_tenant_status ON queue_tickets(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_queue_tickets_tenant_room ON queue_tickets(tenant_id, room_id);
CREATE INDEX IF NOT EXISTS idx_queue_tickets_tenant_space ON queue_tickets(tenant_id, space_id);
