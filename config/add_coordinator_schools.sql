CREATE TABLE coordinator_schools (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinator_user_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coordinator_school (coordinator_user_id),
    KEY idx_coordinator_schools_geofence (geofence_id),
    CONSTRAINT fk_coordinator_schools_user
        FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coordinator_schools_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coordinator_schools_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
);
