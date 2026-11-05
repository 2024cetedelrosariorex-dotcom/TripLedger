-- ==================================================
-- TripLedger Database Schema
-- For installation into a NEW or EMPTY database
-- ==================================================

-- Select a NEW, EMPTY database in your client before executing this file.
-- No CREATE DATABASE or USE statement: never redirects an isolated install.

-- ==================================================
-- 1. USER ACCOUNTS
-- Roles: Authorized Staff and Administrator
-- ==================================================

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    username VARCHAR(50) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    role VARCHAR(30) NOT NULL DEFAULT 'Authorized Staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- ==================================================
-- 2. VEHICLES
-- ==================================================

CREATE TABLE vehicles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    plate_number VARCHAR(30) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_vehicle_plate (plate_number)
) ENGINE=InnoDB;

-- ==================================================
-- 3. DRIVERS
-- Drivers do not have system accounts.
-- ==================================================

CREATE TABLE drivers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    full_name VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_driver_name (full_name)
) ENGINE=InnoDB;

-- ==================================================
-- 4. ANNUAL NUMBERING COUNTERS
-- Sequence continues across months within a year.
-- Each year has its own counter.
-- ==================================================

CREATE TABLE annual_counters (
    ticket_year SMALLINT UNSIGNED PRIMARY KEY,
    last_sequence INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ==================================================
-- 5. ISSUED TRIP TICKETS
-- Number format: YYYY-MM-NNNN
-- Example: 2026-10-0001
-- ==================================================

CREATE TABLE trip_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    ticket_number VARCHAR(40) NOT NULL,
    ticket_year SMALLINT UNSIGNED NOT NULL,
    ticket_sequence INT UNSIGNED NOT NULL,

    transaction_date DATE NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_id BIGINT UNSIGNED NOT NULL,

    remarks TEXT NULL,

    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    submission_token CHAR(64) NULL,

    CONSTRAINT fk_ticket_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES vehicles (id),

    CONSTRAINT fk_ticket_driver
        FOREIGN KEY (driver_id)
        REFERENCES drivers (id),

    CONSTRAINT fk_ticket_creator
        FOREIGN KEY (created_by)
        REFERENCES users (id),

    CONSTRAINT fk_ticket_updater
        FOREIGN KEY (updated_by)
        REFERENCES users (id),

    UNIQUE KEY uq_ticket_number (ticket_number),

    UNIQUE KEY uq_year_sequence
        (ticket_year, ticket_sequence),

    UNIQUE KEY uq_submission_token (submission_token),

    INDEX idx_transaction_date (transaction_date),
    INDEX idx_vehicle_date (vehicle_id, transaction_date)
) ENGINE=InnoDB;

-- ==================================================
-- 6. PHYSICAL TICKET RETURNS
-- A ticket may have only one active recorded return.
-- Reversed returns remain available for history.
-- ==================================================

CREATE TABLE ticket_returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    trip_ticket_id BIGINT UNSIGNED NOT NULL,
    return_date DATE NOT NULL,

    remarks TEXT NULL,

    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    reversed_by BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,

    active_ticket_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE
                WHEN reversed_at IS NULL THEN trip_ticket_id
                ELSE NULL
            END
        ) STORED,

    CONSTRAINT fk_return_ticket
        FOREIGN KEY (trip_ticket_id)
        REFERENCES trip_tickets (id),

    CONSTRAINT fk_return_recorder
        FOREIGN KEY (recorded_by)
        REFERENCES users (id),

    CONSTRAINT fk_return_reverser
        FOREIGN KEY (reversed_by)
        REFERENCES users (id),

    UNIQUE KEY uq_one_active_return (active_ticket_id),

    INDEX idx_return_ticket (trip_ticket_id)
) ENGINE=InnoDB;

-- ==================================================
-- 7. AUDIT LOGS
-- Records ticket and account-management activities.
-- Passwords and password hashes must not be logged.
-- ==================================================

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,
    trip_ticket_id BIGINT UNSIGNED NULL,

    action VARCHAR(60) NOT NULL,

    old_values JSON NULL,
    new_values JSON NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id)
        REFERENCES users (id),

    CONSTRAINT fk_audit_ticket
        FOREIGN KEY (trip_ticket_id)
        REFERENCES trip_tickets (id),

    INDEX idx_audit_ticket (trip_ticket_id),
    INDEX idx_audit_date (created_at)
) ENGINE=InnoDB;
-- Persistent non-secret AI switches. Gemini is opt-in; local lookup is always available.
CREATE TABLE ai_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    explanations_enabled TINYINT(1) NOT NULL DEFAULT 0,
    analysis_enabled TINYINT(1) NOT NULL DEFAULT 0,
    model VARCHAR(100) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO ai_settings (id) VALUES (1);

CREATE TABLE schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO schema_migrations (version) VALUES ('001_session_submission'), ('002_ai_settings'), ('003_timestamp_constraints');
