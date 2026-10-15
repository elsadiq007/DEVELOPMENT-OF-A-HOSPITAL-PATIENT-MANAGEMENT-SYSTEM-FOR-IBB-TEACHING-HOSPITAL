USE hospital_db;

ALTER TABLE visits ADD COLUMN admitted TINYINT(1) NOT NULL DEFAULT 0 AFTER patient_id;
ALTER TABLE visits ADD COLUMN room_number VARCHAR(30) NULL AFTER admitted;
CREATE INDEX idx_visit_room ON visits(room_number);
