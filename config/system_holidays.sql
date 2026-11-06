CREATE TABLE IF NOT EXISTS system_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_system_holiday_date (holiday_date),
    KEY idx_system_holiday_date (holiday_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    geofence_id BIGINT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_school_holiday_date (geofence_id, holiday_date),
    KEY idx_school_holiday_date (holiday_date),
    CONSTRAINT fk_school_holidays_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_school_holidays_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
