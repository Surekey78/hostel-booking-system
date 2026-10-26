-- =====================================================================
--  HOSTEL ROOM BOOKING SYSTEM - Database Schema
--  Engine : MySQL / MariaDB  (InnoDB, utf8mb4)
--  Access : PHP mysqli (procedural + prepared statements)
-- =====================================================================
--  Creates the database itself, so a bare import is all you need:
--      mysql -u root -p < schema.sql
--  (On shared hosting where the DB already exists, delete these two
--   statements or keep them - IF NOT EXISTS makes the import idempotent.)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS hostel_booking
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE hostel_booking;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS assignments;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS room_types;
DROP TABLE IF EXISTS hostels;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- users : students + hostel administrators
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role          ENUM('student','admin') NOT NULL DEFAULT 'student',
  student_id    VARCHAR(20)  DEFAULT NULL,          -- matric / registration no.
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  phone         VARCHAR(25)  DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  gender        ENUM('male','female','other') DEFAULT NULL,
  programme     VARCHAR(120) DEFAULT NULL,          -- course of study
  year_level    VARCHAR(20)  DEFAULT NULL,          -- e.g. "1st Year"
  address       VARCHAR(255) DEFAULT NULL,
  emergency_contact_name  VARCHAR(120) DEFAULT NULL,
  emergency_contact_phone VARCHAR(25)  DEFAULT NULL,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_student_id (student_id),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- hostels : accommodation blocks
-- ---------------------------------------------------------------------
CREATE TABLE hostels (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120) NOT NULL,
  block_code   VARCHAR(20)  NOT NULL,               -- e.g. "BLK-A"
  location     VARCHAR(180) DEFAULT NULL,
  description  TEXT,
  facilities   VARCHAR(500) DEFAULT NULL,           -- comma separated
  warden_name  VARCHAR(120) DEFAULT NULL,
  warden_phone VARCHAR(25)  DEFAULT NULL,
  gender_policy ENUM('male','female','mixed') NOT NULL DEFAULT 'mixed',
  image_url    VARCHAR(255) DEFAULT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hostel_block (block_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- room_types : categories that carry price + capacity
-- ---------------------------------------------------------------------
CREATE TABLE room_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,                 -- Single / Double / Quad ...
  capacity    TINYINT UNSIGNED NOT NULL DEFAULT 1,  -- beds per room
  price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,  -- per semester
  facilities  VARCHAR(500) DEFAULT NULL,
  description VARCHAR(500) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- rooms : physical rooms inside a hostel block
-- ---------------------------------------------------------------------
CREATE TABLE rooms (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hostel_id   INT UNSIGNED NOT NULL,
  room_type_id INT UNSIGNED NOT NULL,
  room_number VARCHAR(20) NOT NULL,
  floor       TINYINT NOT NULL DEFAULT 1,
  occupied    TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- beds currently taken
  status      ENUM('available','full','maintenance','blocked') NOT NULL DEFAULT 'available',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hostel_room (hostel_id, room_number),
  KEY idx_rooms_type (room_type_id),
  CONSTRAINT fk_rooms_hostel FOREIGN KEY (hostel_id)   REFERENCES hostels(id)   ON DELETE CASCADE,
  CONSTRAINT fk_rooms_type   FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- bookings : applications submitted by students
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no    VARCHAR(25) NOT NULL,             -- HB-2026-00001
  student_id      INT UNSIGNED NOT NULL,            -- -> users.id
  hostel_id       INT UNSIGNED NOT NULL,
  room_type_id    INT UNSIGNED NOT NULL,
  intake_period   VARCHAR(40) NOT NULL,             -- e.g. "Semester 1, 2026/2027"
  checkin_date    DATE DEFAULT NULL,
  checkout_date   DATE DEFAULT NULL,
  roommates       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  special_request TEXT,
  status          ENUM('pending','approved','rejected','assigned','cancelled','checked_out')
                  NOT NULL DEFAULT 'pending',
  admin_remarks   TEXT,
  processed_by    INT UNSIGNED DEFAULT NULL,        -- -> users.id (admin)
  processed_at    DATETIME DEFAULT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking_ref (reference_no),
  KEY idx_booking_student (student_id),
  KEY idx_booking_status  (status),
  KEY idx_booking_hostel  (hostel_id),
  CONSTRAINT fk_booking_student FOREIGN KEY (student_id)   REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_booking_hostel  FOREIGN KEY (hostel_id)    REFERENCES hostels(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_booking_type    FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_admin   FOREIGN KEY (processed_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- assignments : approved applications converted into room records
-- ---------------------------------------------------------------------
CREATE TABLE assignments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id    INT UNSIGNED NOT NULL,
  room_id       INT UNSIGNED NOT NULL,
  bed_number    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  student_id    INT UNSIGNED NOT NULL,
  assigned_by   INT UNSIGNED DEFAULT NULL,
  status        ENUM('active','checked_out','transferred') NOT NULL DEFAULT 'active',
  checkin_date  DATE DEFAULT NULL,
  checkout_date DATE DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_active_booking (booking_id, status),
  KEY idx_assign_room (room_id),
  KEY idx_assign_student (student_id),
  CONSTRAINT fk_assign_booking FOREIGN KEY (booking_id)  REFERENCES bookings(id)   ON DELETE CASCADE,
  CONSTRAINT fk_assign_room    FOREIGN KEY (room_id)     REFERENCES rooms(id)      ON DELETE RESTRICT,
  CONSTRAINT fk_assign_student FOREIGN KEY (student_id)  REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_assign_admin   FOREIGN KEY (assigned_by) REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- documents : supporting files uploaded against a booking
-- ---------------------------------------------------------------------
CREATE TABLE documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   INT UNSIGNED NOT NULL,
  uploaded_by  INT UNSIGNED NOT NULL,
  doc_type     VARCHAR(60) NOT NULL,                -- ID Card, Guarantor Letter...
  original_name VARCHAR(255) NOT NULL,
  stored_name  VARCHAR(255) NOT NULL,
  mime_type    VARCHAR(120) DEFAULT NULL,
  file_size    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_booking (booking_id),
  CONSTRAINT fk_doc_booking FOREIGN KEY (booking_id)  REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_user    FOREIGN KEY (uploaded_by) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- notifications : in-system status updates
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(150) NOT NULL,
  message    TEXT NOT NULL,
  link       VARCHAR(255) DEFAULT NULL,
  type       ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_read (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Helper views used by the reporting module
-- =====================================================================
DROP VIEW IF EXISTS v_room_vacancy;
CREATE VIEW v_room_vacancy AS
SELECT r.id            AS room_id,
       r.room_number,
       r.floor,
       r.status        AS room_status,
       h.id            AS hostel_id,
       h.name          AS hostel_name,
       h.block_code,
       rt.id           AS room_type_id,
       rt.name         AS room_type_name,
       rt.capacity,
       rt.price,
       r.occupied,
       (rt.capacity - r.occupied) AS vacancy
FROM rooms r
JOIN hostels   h  ON h.id  = r.hostel_id
JOIN room_types rt ON rt.id = r.room_type_id;

DROP VIEW IF EXISTS v_hostel_occupancy;
CREATE VIEW v_hostel_occupancy AS
SELECT h.id,
       h.name,
       h.block_code,
       h.gender_policy,
       COUNT(r.id)                            AS total_rooms,
       COALESCE(SUM(rt.capacity),0)           AS total_beds,
       COALESCE(SUM(r.occupied),0)            AS occupied_beds,
       COALESCE(SUM(rt.capacity - r.occupied),0) AS vacant_beds,
       ROUND(100 * COALESCE(SUM(r.occupied),0) / NULLIF(SUM(rt.capacity),0), 1) AS occupancy_rate
FROM hostels h
LEFT JOIN rooms r      ON r.hostel_id = h.id
LEFT JOIN room_types rt ON rt.id = r.room_type_id
GROUP BY h.id, h.name, h.block_code, h.gender_policy;
