-- database\schema.sql: All tables
-- =====================================================================
-- BmJS Result Management System: DATABASE SCHEMA
-- Benchmark Junior School, G.T. Road Campus
--
-- HOW TO USE
--   1. Start Apache and MySQL in XAMPP.
--   2. Open http://localhost/phpmyadmin, click "Import", choose this file, click Go.
--   3. Then import seed.sql the same way.
--
-- SAFE TO RE-RUN: every table uses CREATE TABLE IF NOT EXISTS, nothing is dropped.
-- To start again from zero, drop the "bmjs" database in phpMyAdmin and re-import.
-- Later changes go in database/migrations/ as numbered files, never by editing this file
-- after real data exists.
--
-- Needs MariaDB 10.4+ or MySQL 8.0.16+ (XAMPP 8.x includes a suitable MariaDB).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS bmjs
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bmjs;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. GRADING (created first because academic_years points to it)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grading_scales (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grade_bands (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scale_id     INT UNSIGNED NOT NULL,
  grade_label  VARCHAR(10) NOT NULL,
  min_percent  DECIMAL(5,2) NOT NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_band_label (scale_id, grade_label),
  UNIQUE KEY uq_band_min (scale_id, min_percent),
  CONSTRAINT fk_band_scale FOREIGN KEY (scale_id) REFERENCES grading_scales (id) ON DELETE CASCADE,
  CONSTRAINT ck_band_min CHECK (min_percent >= 0 AND min_percent <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. USERS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username                  VARCHAR(60) NOT NULL,
  password_hash             VARCHAR(255) NOT NULL,
  full_name                 VARCHAR(120) NOT NULL,
  role                      ENUM('super_admin','admin','teacher') NOT NULL,
  is_active                 TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password      TINYINT(1) NOT NULL DEFAULT 1,
  hrt_can_enter_attendance  TINYINT(1) NOT NULL DEFAULT 0,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_username (username),
  KEY idx_user_role (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. ACADEMIC YEARS, CLASSES, SECTIONS
-- ---------------------------------------------------------------------
-- active_marker lets the database itself guarantee that only ONE year is active.
-- To switch the active year: first set the old year's is_active = 0, then set the new
-- one to 1 (two statements inside one transaction).
CREATE TABLE IF NOT EXISTS academic_years (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(20) NOT NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 0,
  grading_scale_id   INT UNSIGNED NOT NULL,
  active_marker      TINYINT(1) GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN 1 ELSE NULL END) STORED,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_year_name (name),
  UNIQUE KEY uq_one_active_year (active_marker),
  CONSTRAINT fk_year_scale FOREIGN KEY (grading_scale_id) REFERENCES grading_scales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(30) NOT NULL,
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_class_id  INT UNSIGNED NULL,          -- NULL = last class (students graduate)
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_name (name),
  KEY idx_class_sort (sort_order),
  CONSTRAINT fk_class_next FOREIGN KEY (next_class_id) REFERENCES classes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sections (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id  INT UNSIGNED NOT NULL,
  class_id          INT UNSIGNED NOT NULL,
  name              VARCHAR(30) NOT NULL,
  hrt_user_id       INT UNSIGNED NULL,       -- class teacher
  PRIMARY KEY (id),
  UNIQUE KEY uq_section (academic_year_id, class_id, name),
  KEY idx_section_class (class_id),
  KEY idx_section_hrt (hrt_user_id),
  CONSTRAINT fk_section_year  FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_section_class FOREIGN KEY (class_id) REFERENCES classes (id),
  CONSTRAINT fk_section_hrt   FOREIGN KEY (hrt_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bmjs_id     VARCHAR(40) NOT NULL,          -- like BmSS-JS-22-1418
  full_name   VARCHAR(150) NOT NULL,
  dob         DATE NULL,
  section_id  INT UNSIGNED NULL,             -- CURRENT section
  status      ENUM('active','sor','freeze','left') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_bmjs (bmjs_id),
  KEY idx_student_section (section_id, status),
  KEY idx_student_name (full_name),
  CONSTRAINT fk_student_section FOREIGN KEY (section_id) REFERENCES sections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which section each student was in during each academic year (history).
CREATE TABLE IF NOT EXISTS student_enrollments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id        INT UNSIGNED NOT NULL,
  academic_year_id  INT UNSIGNED NOT NULL,
  section_id        INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment (student_id, academic_year_id),
  KEY idx_enroll_section (academic_year_id, section_id),
  CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_enroll_year    FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_enroll_section FOREIGN KEY (section_id) REFERENCES sections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planned promotion decision per student per year. No row = default
-- (promote, or graduate if the class has no next class).
CREATE TABLE IF NOT EXISTS student_year_outcome (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id        INT UNSIGNED NOT NULL,
  academic_year_id  INT UNSIGNED NOT NULL,
  outcome           ENUM('promote','hold_back','graduate') NOT NULL,
  note              VARCHAR(255) NULL,
  set_by            INT UNSIGNED NULL,
  set_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outcome (student_id, academic_year_id),
  CONSTRAINT fk_outcome_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_outcome_year    FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_outcome_user    FOREIGN KEY (set_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. SUBJECTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subjects (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(80) NOT NULL,
  short_name           VARCHAR(20) NOT NULL,
  type                 ENUM('graded','grade_only') NOT NULL DEFAULT 'graded',  -- graded = marks subject
  counts_toward_total  TINYINT(1) NOT NULL DEFAULT 1,
  show_on_report       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subject_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which subjects each class has, per academic year.
-- Effective type = COALESCE(type_override, subjects.type)
CREATE TABLE IF NOT EXISTS class_subjects (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id     INT UNSIGNED NOT NULL,
  class_id             INT UNSIGNED NOT NULL,
  subject_id           INT UNSIGNED NOT NULL,
  type_override        ENUM('graded','grade_only') NULL,
  counts_toward_total  TINYINT(1) NOT NULL DEFAULT 1,
  show_on_report       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_class_subject (academic_year_id, class_id, subject_id),
  KEY idx_cs_subject (subject_id),
  CONSTRAINT fk_cs_year    FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_cs_class   FOREIGN KEY (class_id) REFERENCES classes (id),
  CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id) REFERENCES subjects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. EXAM STRUCTURE
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS components (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(30) NOT NULL,           -- F.A, S.A
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_component_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per exam (CP1, MT, FT ...) for one class in one year.
-- mode 'entered'    = teachers type the marks.
-- mode 'calculated' = worked out from other assessments (see assessment_sources).
CREATE TABLE IF NOT EXISTS assessments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id  INT UNSIGNED NOT NULL,
  class_id          INT UNSIGNED NOT NULL,
  code              VARCHAR(20) NOT NULL,     -- CP1, CP2, MT, FT, ...
  name              VARCHAR(80) NOT NULL,
  term_group        ENUM('mid','final') NOT NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  mode              ENUM('entered','calculated') NOT NULL DEFAULT 'entered',
  calc_method       ENUM('sum','average') NULL,
  state             ENUM('draft','open','locked') NOT NULL DEFAULT 'draft',
  has_attendance    TINYINT(1) NOT NULL DEFAULT 0,
  is_term_result    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assessment_code (academic_year_id, class_id, code),
  KEY idx_assessment_order (academic_year_id, class_id, sort_order),
  KEY idx_assessment_class (class_id),
  CONSTRAINT fk_assess_year  FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_assess_class FOREIGN KEY (class_id) REFERENCES classes (id),
  CONSTRAINT ck_assess_calc CHECK (mode = 'entered' OR calc_method IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which marks columns (F.A, S.A) an entered assessment has.
CREATE TABLE IF NOT EXISTS assessment_components (
  assessment_id  INT UNSIGNED NOT NULL,
  component_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (assessment_id, component_id),
  CONSTRAINT fk_ac_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE,
  CONSTRAINT fk_ac_component  FOREIGN KEY (component_id)  REFERENCES components (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which assessments a calculated assessment is built from (same class and year only:
-- the application must check this and also reject loops).
CREATE TABLE IF NOT EXISTS assessment_sources (
  assessment_id         INT UNSIGNED NOT NULL,
  source_assessment_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (assessment_id, source_assessment_id),
  KEY idx_as_source (source_assessment_id),
  CONSTRAINT fk_as_assessment FOREIGN KEY (assessment_id)        REFERENCES assessments (id) ON DELETE CASCADE,
  CONSTRAINT fk_as_source     FOREIGN KEY (source_assessment_id) REFERENCES assessments (id) ON DELETE CASCADE,
  CONSTRAINT ck_as_not_self CHECK (assessment_id <> source_assessment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maximum marks per class, subject, assessment and component.
-- Grade-only subjects never get rows here.
CREATE TABLE IF NOT EXISTS max_marks (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  class_id       INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  assessment_id  INT UNSIGNED NOT NULL,
  component_id   INT UNSIGNED NOT NULL,
  max_value      DECIMAL(6,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_max_marks (class_id, subject_id, assessment_id, component_id),
  KEY idx_mm_assessment (assessment_id),
  CONSTRAINT fk_mm_class      FOREIGN KEY (class_id)      REFERENCES classes (id),
  CONSTRAINT fk_mm_subject    FOREIGN KEY (subject_id)    REFERENCES subjects (id),
  CONSTRAINT fk_mm_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id) ON DELETE CASCADE,
  CONSTRAINT fk_mm_component  FOREIGN KEY (component_id)  REFERENCES components (id),
  CONSTRAINT ck_mm_value CHECK (max_value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ready-made structures (presets A, B, C and the admin's own saved ones).
-- 'definition' holds JSON; see seed.sql for the format.
CREATE TABLE IF NOT EXISTS structure_presets (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  definition   LONGTEXT NOT NULL,
  is_builtin   TINYINT(1) NOT NULL DEFAULT 0,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_preset_name (name),
  CONSTRAINT fk_preset_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. TEACHER ASSIGNMENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS teacher_assignments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  section_id  INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment (section_id, subject_id),   -- one teacher per section + subject
  KEY idx_ta_user (user_id),
  KEY idx_ta_subject (subject_id),
  CONSTRAINT fk_ta_user    FOREIGN KEY (user_id)    REFERENCES users (id),
  CONSTRAINT fk_ta_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. RESULTS
-- ---------------------------------------------------------------------
-- No row = NOT ENTERED (never the same as 0).
CREATE TABLE IF NOT EXISTS marks (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id     INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  assessment_id  INT UNSIGNED NOT NULL,
  component_id   INT UNSIGNED NOT NULL,
  value          DECIMAL(6,2) NULL,
  status         ENUM('entered','absent','exempt') NOT NULL DEFAULT 'entered',
  entered_by     INT UNSIGNED NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mark (student_id, subject_id, assessment_id, component_id),
  KEY idx_mark_assessment (assessment_id, subject_id),
  CONSTRAINT fk_mark_student    FOREIGN KEY (student_id)    REFERENCES students (id),
  CONSTRAINT fk_mark_subject    FOREIGN KEY (subject_id)    REFERENCES subjects (id),
  CONSTRAINT fk_mark_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id),
  CONSTRAINT fk_mark_component  FOREIGN KEY (component_id)  REFERENCES components (id),
  CONSTRAINT fk_mark_user       FOREIGN KEY (entered_by)    REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT ck_mark_value CHECK (value IS NULL OR value >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Letter grades for grade-only subjects, per assessment.
CREATE TABLE IF NOT EXISTS grade_entries (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id     INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  assessment_id  INT UNSIGNED NOT NULL,
  grade          VARCHAR(5) NOT NULL,
  entered_by     INT UNSIGNED NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade_entry (student_id, subject_id, assessment_id),
  KEY idx_ge_assessment (assessment_id, subject_id),
  CONSTRAINT fk_ge_student    FOREIGN KEY (student_id)    REFERENCES students (id),
  CONSTRAINT fk_ge_subject    FOREIGN KEY (subject_id)    REFERENCES subjects (id),
  CONSTRAINT fk_ge_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id),
  CONSTRAINT fk_ge_user       FOREIGN KEY (entered_by)    REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comments: subject_id NULL = the class teacher's (HRT) overall comment.
-- academic_year_id is included so a student can have comments in every year.
-- subject_key is a helper so that "one HRT comment per student per term" is enforced
-- (a plain UNIQUE index would allow several NULLs). Never write to subject_key.
CREATE TABLE IF NOT EXISTS comments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id        INT UNSIGNED NOT NULL,
  academic_year_id  INT UNSIGNED NOT NULL,
  subject_id        INT UNSIGNED NULL,
  term_group        ENUM('mid','final') NOT NULL,
  text              TEXT NOT NULL,
  updated_by        INT UNSIGNED NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  subject_key       INT UNSIGNED GENERATED ALWAYS AS (IFNULL(subject_id, 0)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_comment (student_id, academic_year_id, subject_key, term_group),
  CONSTRAINT fk_comment_student FOREIGN KEY (student_id)       REFERENCES students (id),
  CONSTRAINT fk_comment_year    FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_comment_subject FOREIGN KEY (subject_id)       REFERENCES subjects (id),
  CONSTRAINT fk_comment_user    FOREIGN KEY (updated_by)       REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id     INT UNSIGNED NOT NULL,
  assessment_id  INT UNSIGNED NOT NULL,
  present        SMALLINT UNSIGNED NOT NULL,
  total_days     SMALLINT UNSIGNED NOT NULL,
  updated_by     INT UNSIGNED NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance (student_id, assessment_id),
  KEY idx_att_assessment (assessment_id),
  CONSTRAINT fk_att_student    FOREIGN KEY (student_id)    REFERENCES students (id),
  CONSTRAINT fk_att_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id),
  CONSTRAINT fk_att_user       FOREIGN KEY (updated_by)    REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT ck_att_present CHECK (present <= total_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A teacher pressing "Submit" for one section + subject + assessment.
CREATE TABLE IF NOT EXISTS submissions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_id     INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  assessment_id  INT UNSIGNED NOT NULL,
  submitted_by   INT UNSIGNED NULL,
  submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_submission (section_id, subject_id, assessment_id),
  CONSTRAINT fk_sub_section    FOREIGN KEY (section_id)    REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_subject    FOREIGN KEY (subject_id)    REFERENCES subjects (id),
  CONSTRAINT fk_sub_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id),
  CONSTRAINT fk_sub_user       FOREIGN KEY (submitted_by)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. SETTINGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key    VARCHAR(60) NOT NULL,
  setting_value  TEXT NOT NULL,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. REPORT CARDS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS report_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  report_type ENUM('assessment','mid','final') NOT NULL,
  class_id    INT UNSIGNED NULL,               -- NULL = all classes
  html_body   LONGTEXT NOT NULL,
  version     INT UNSIGNED NOT NULL DEFAULT 1,
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tpl_lookup (report_type, class_id, is_default),
  CONSTRAINT fk_tpl_class FOREIGN KEY (class_id)   REFERENCES classes (id),
  CONSTRAINT fk_tpl_user  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every report generation. Promotion uses this to know a section's Final Term
-- reports have been generated.
CREATE TABLE IF NOT EXISTS report_log (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NULL,
  report_type    ENUM('assessment','mid','final') NOT NULL,
  assessment_id  INT UNSIGNED NULL,
  section_id     INT UNSIGNED NULL,
  student_id     INT UNSIGNED NULL,
  template_id    INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rl_section (section_id, report_type),
  KEY idx_rl_created (created_at),
  CONSTRAINT fk_rl_user       FOREIGN KEY (user_id)       REFERENCES users (id)             ON DELETE SET NULL,
  CONSTRAINT fk_rl_assessment FOREIGN KEY (assessment_id) REFERENCES assessments (id)       ON DELETE SET NULL,
  CONSTRAINT fk_rl_section    FOREIGN KEY (section_id)    REFERENCES sections (id)          ON DELETE SET NULL,
  CONSTRAINT fk_rl_student    FOREIGN KEY (student_id)    REFERENCES students (id)          ON DELETE SET NULL,
  CONSTRAINT fk_rl_template   FOREIGN KEY (template_id)   REFERENCES report_templates (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. PROMOTION
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promotion_batches (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NULL,
  source_year_id   INT UNSIGNED NOT NULL,
  target_year_id   INT UNSIGNED NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_at        DATETIME NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_pb_user   FOREIGN KEY (user_id)        REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_pb_source FOREIGN KEY (source_year_id) REFERENCES academic_years (id),
  CONSTRAINT fk_pb_target FOREIGN KEY (target_year_id) REFERENCES academic_years (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything needed to undo a promotion. The old_* / created_* columns are plain
-- numbers (no foreign keys) on purpose: after an undo the rows they point to are gone.
CREATE TABLE IF NOT EXISTS promotion_batch_rows (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id                INT UNSIGNED NOT NULL,
  student_id              INT UNSIGNED NOT NULL,
  outcome                 ENUM('promote','hold_back','graduate') NOT NULL,
  from_section_id         INT UNSIGNED NULL,
  to_section_id           INT UNSIGNED NULL,
  old_student_section_id  INT UNSIGNED NULL,
  old_student_status      ENUM('active','sor','freeze','left') NULL,
  created_enrollment_id   INT UNSIGNED NULL,
  created_section_id      INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_pbr_batch (batch_id),
  KEY idx_pbr_student (student_id),
  CONSTRAINT fk_pbr_batch   FOREIGN KEY (batch_id)   REFERENCES promotion_batches (id) ON DELETE CASCADE,
  CONSTRAINT fk_pbr_student FOREIGN KEY (student_id) REFERENCES students (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. LOGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  table_name  VARCHAR(80) NULL,
  record_id   INT UNSIGNED NULL,               -- NULL for bulk actions
  old_value   TEXT NULL,
  new_value   TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_record (table_name, record_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip            VARCHAR(45) NOT NULL,
  username      VARCHAR(60) NOT NULL,
  attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_ip (ip, attempted_at),
  KEY idx_la_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== END OF SCHEMA =====================