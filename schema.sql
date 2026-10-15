// IBB Specialist Hospital Minna //
CREATE DATABASE IF NOT EXISTS hospital_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hospital_db;

// USERS staff accounts / roles //

CREATE TABLE users (
  id            VARCHAR(40)  PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  pin_hash      VARCHAR(255) NOT NULL,          
  role          ENUM('admin','receptionist','nurse','doctor','pharmacy') NOT NULL,
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
// PATIENTS INFO//
CREATE TABLE patients (
  id            VARCHAR(40)  PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  phone         VARCHAR(30),
  age           VARCHAR(10),
  gender        ENUM('Male','Female','Other'),
  address       VARCHAR(255),
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_patient_name (name),
  INDEX idx_patient_phone (phone)
);

 // VISITS //
CREATE TABLE visits (
  id              VARCHAR(40) PRIMARY KEY,
  patient_id      VARCHAR(40) NOT NULL,
  room_number     VARCHAR(30) NULL,
  stage           ENUM('checked_in','with_doctor','pharmacy_pending','ready_checkout','completed') NOT NULL DEFAULT 'checked_in',
  checkin_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checkout_time   DATETIME NULL,

  // vitals recorded by nurse //
  temperature     DECIMAL(4,1) NULL,
  blood_pressure  VARCHAR(20)  NULL,
  pulse           INT          NULL,
  weight_kg       DECIMAL(5,2) NULL,
  vitals_notes    TEXT NULL,
  vitals_by       VARCHAR(40)  NULL,  
  vitals_at       DATETIME NULL,

  // doctor notes //
  doctor_id       VARCHAR(40) NULL,  
  diagnosis       TEXT NULL,
  doctor_notes    TEXT NULL,
  seen_at         DATETIME NULL,

  created_by      VARCHAR(40) NULL, 
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (doctor_id)  REFERENCES users(id)     ON DELETE SET NULL,
  FOREIGN KEY (vitals_by)  REFERENCES users(id)      ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)      ON DELETE SET NULL,

  INDEX idx_visit_stage (stage),
  INDEX idx_visit_patient (patient_id),
  INDEX idx_visit_room (room_number),
  INDEX idx_visit_room (room_number)
);

// Prescription //

CREATE TABLE prescriptions (
  id            VARCHAR(40) PRIMARY KEY,
  visit_id      VARCHAR(40) NOT NULL,
  drug_name     VARCHAR(150) NOT NULL,
  dosage        VARCHAR(100),
  instructions  VARCHAR(255),
  dispensed     TINYINT(1) NOT NULL DEFAULT 0,
  dispensed_by  VARCHAR(40) NULL, 
  dispensed_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
  FOREIGN KEY (dispensed_by) REFERENCES users(id) ON DELETE SET NULL,

  INDEX idx_rx_visit (visit_id)
);


// ACTIVITY LOG //

CREATE TABLE activity_log (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       VARCHAR(40) NULL,
  user_name     VARCHAR(120) NULL, 
  action        VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_log_created (created_at)
);
