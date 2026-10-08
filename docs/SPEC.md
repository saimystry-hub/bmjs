# BmJS Result Management System: Specification (v2)

School: Benchmark Junior School, G.T. Road Campus ("BmJS-G.T.R.C", motto "Realising Your Dreams").
This file is the single source of truth. Save it as `docs/SPEC.md`. It replaces every earlier version and prompt.

---

## 0. HOW YOU (THE AI) MUST WORK

1. Build ONE phase at a time (Section 16). I will say "Do Phase N". Do only that phase, then stop and tell me exactly how to test it.
2. Before starting, read this file and `docs/PROGRESS.md`. After finishing, update `docs/PROGRESS.md`.
3. Output COMPLETE files, never snippets or "rest of the code here". List the files you created or changed.
4. SIMPLICITY RULES:
   - Plain PHP 8, MySQL via PDO, HTML, CSS, vanilla JavaScript. No framework, no Composer, no npm, no CDNs. Everything is local and works offline.
   - Choose the simplest solution that meets this spec. Do not add features that are not written here.
   - Keep each file under 300 lines. Put shared logic in `includes/`, shared HTML pieces in `components/`. Comment every function in plain English.
   - Do not "improve" or rewrite earlier phases unless the current phase says so.
5. SECURITY RULES (every phase):
   - PDO prepared statements only. Escape all output with `h()`. CSRF token on every POST form and every API call. `password_hash` / `password_verify` for passwords.
   - Every page starts with `require_once` of `includes/bootstrap.php`, then `require_login()`, then `require_role([...])`. Permissions are enforced on the server, not just hidden in menus.
   - Users never see raw PHP errors: log them and show a friendly message.
6. Every multi-row change runs in one database transaction and writes an `audit_log` entry.
7. Use fake students and fake marks for demo and testing.
8. The existing files `components/header.php`, `components/footer.php` and `img/logo.jfif` were made by me. Adapt them, do not replace them. Use `img/logo.png` (a PNG copy of the logo) for printing if it exists.

---

## 1. WHAT THE SYSTEM DOES

A local website that replaces the school's Excel workbook:

- Admins set up years, classes, sections, subjects, students, teachers and each class's exam structure.
- Teachers enter marks (or grades) for their own subject and section only.
- The system calculates totals, percentages and grades from editable rules.
- Admins print report cards from editable templates, copy the setup to a new academic year, and promote students.

It runs on XAMPP (Apache, PHP, MySQL, phpMyAdmin) on a school server on the school network.

---

## 2. ROLES

| Role | Can do |
|---|---|
| **super_admin** | Everything: users, grading, settings, report templates, backups, audit log, year rollover, reopen locked work, undo promotion. |
| **admin** | Years, classes, sections, subjects, class subjects, students (and CSV import), teacher assignments, exam structure, max marks, attendance, open/close/lock exams, completeness, generate and print reports, bulk operations, promotion. Cannot manage users, grading, settings, templates or backups. |
| **teacher** | Enter marks, grades and comments ONLY for assigned subject and section, ONLY while the exam is open. Nothing else. Optional per-user toggle: the class teacher (HRT) may enter attendance for their own section. |

---

## 3. ACCESS

Anyone on the school network can open the website and see the login page. There are NO IP, device or "school PC only" restrictions. Security comes from login, roles, login rate limiting and the server-side checks in Section 0. Do not create any access-guard code.

---

## 4. FOLDER STRUCTURE (already created as placeholders; fill in phase by phase)

```
bmjs/
├── index.php  login.php  logout.php  change_password.php  dashboard.php  setup_admin.php
├── .htaccess                         # Options -Indexes
├── components/                       # reusable HTML pieces (markup only)
│   header.php  footer.php  sidebar.php  topbar.php  flash.php  modal.php
│   pagination.php  empty_state.php  grade_badge.php  progress_ring.php  wizard_steps.php
│   scope_picker.php                  # NEW: choose class / sections for bulk actions (Section 9.6)
├── config/        config.sample.php  config.php          (+ .htaccess deny)
├── includes/      PHP logic only, no HTML                (+ .htaccess deny)
│   bootstrap.php  db.php  helpers.php  csrf.php  auth.php  audit.php  validators.php
│   calc.php  structure.php  class_subjects.php  csv.php  merge.php  promotion.php  rollover.php
│   bulk.php                          # NEW: shared bulk helpers (scope, preview, run, audit)
├── admin/
│   ├── students/   index.php  edit.php  import.php  bulk.php
│   ├── classes/    years.php  index.php  sections.php  subjects.php  class_subjects.php
│   ├── exams/      structure.php  max_marks.php  states.php  attendance.php  completeness.php
│   ├── teachers/   assignments.php  bulk_assign.php
│   ├── year_end/   rollover.php  promotion.php
│   └── system/     (super_admin) users.php  user_import.php  grading.php  settings.php
│                   report_templates.php  template_edit.php  merge_fields.php  backup.php  audit_log.php
├── teacher/       my_classes.php  enter_marks.php  enter_grades.php  review_submit.php
├── api/           save_mark.php  save_grade.php  save_comment.php  preview_grading.php
├── reports/       generate.php  print.php  class_sheet.php  export_csv.php
├── css/           style.css  print.css
├── js/            app.js  marks_entry.js  structure_editor.js  template_editor.js
├── img/           logo.jfif (and logo.png)
├── fonts/  uploads/ (PHP execution disabled)
├── database/      schema.sql  seed.sql  migrations/  backups/   (+ .htaccess deny)
├── tests/         test_calc.php  test_structure.php  test_logic.php  test_permissions.php
└── docs/          SPEC.md  PROGRESS.md  CHANGELOG.md  README.md  admin_guide.md  teacher_guide.md
                   sample_students.csv  sample_teachers.csv
```

**Page rules**
- Every page begins with `require_once __DIR__ . '/<relative path>/includes/bootstrap.php';`, which loads config, database, helpers, auth and the session, and defines `BASE_PATH` and `BASE_URL`. Use `BASE_URL` for every link, CSS, JS and image URL so pages in sub-folders work.
- Then `require_login()` and `require_role([...])`, then `components/header.php` ... `components/footer.php`.
- Folders `config/`, `includes/`, `database/`, `docs/`, `tests/` contain `.htaccess` with `Require all denied`. `uploads/.htaccess` disables PHP execution.

---

## 5. DATABASE (use exactly these names; InnoDB, utf8mb4)

**People and school structure**
- `users(id, username UNIQUE, password_hash, full_name, role ENUM('super_admin','admin','teacher'), is_active, must_change_password, hrt_can_enter_attendance, created_at)`
- `academic_years(id, name, is_active, grading_scale_id)`: only one active year.
- `classes(id, name, sort_order, next_class_id NULL)`: NULL means last class (graduates).
- `sections(id, academic_year_id, class_id, name, hrt_user_id NULL)`
- `students(id, bmjs_id UNIQUE, full_name, dob NULL, section_id NULL, status ENUM('active','sor','freeze','left'), created_at)`: `section_id` is the CURRENT section.
- `student_enrollments(id, student_id, academic_year_id, section_id)`: UNIQUE(student_id, academic_year_id). History of which section a student was in each year. Old-year reports read class and section from here.
- `student_year_outcome(id, student_id, academic_year_id, outcome ENUM('promote','hold_back','graduate'), note)`: UNIQUE(student_id, academic_year_id). No row means the default.

**Subjects**
- `subjects(id, name, short_name, type ENUM('graded','grade_only'), counts_toward_total, show_on_report, sort_order, is_active)`: `graded` = marks subject; `grade_only` = letter grade only.
- `class_subjects(id, academic_year_id, class_id, subject_id, type_override ENUM('graded','grade_only') NULL, counts_toward_total, show_on_report, sort_order, is_active)`: UNIQUE(year, class, subject). Effective type = COALESCE(type_override, subjects.type).

**Exam structure**
- `components(id, name, sort_order)`: seeded: F.A, S.A.
- `assessments(id, academic_year_id, class_id, code, name, term_group ENUM('mid','final'), sort_order, mode ENUM('entered','calculated'), calc_method ENUM('sum','average') NULL, state ENUM('draft','open','locked'), has_attendance, is_term_result)`: UNIQUE(year, class, code).
- `assessment_components(assessment_id, component_id)`
- `assessment_sources(assessment_id, source_assessment_id)`: for calculated assessments.
- `max_marks(id, class_id, subject_id, assessment_id, component_id, max_value DECIMAL(6,2))`: UNIQUE on the four ids.

**Teachers**
- `teacher_assignments(id, user_id, section_id, subject_id)`: UNIQUE(section_id, subject_id).

**Results**
- `marks(id, student_id, subject_id, assessment_id, component_id, value DECIMAL(6,2) NULL, status ENUM('entered','absent','exempt'), entered_by, updated_at)`: UNIQUE on first four ids. No row means NOT ENTERED (never the same as 0).
- `grade_entries(id, student_id, subject_id, assessment_id, grade VARCHAR(5))`: UNIQUE on first three.
- `comments(id, student_id, subject_id NULL, term_group ENUM('mid','final'), text, updated_by)`: NULL subject = HRT comment. UNIQUE(student, subject, term_group).
- `attendance(id, student_id, assessment_id, present, total_days)`: UNIQUE(student, assessment).
- `submissions(id, section_id, subject_id, assessment_id, submitted_by, submitted_at)`

**Grading and settings**
- `grading_scales(id, name, created_at)`, `grade_bands(id, scale_id, grade_label, min_percent DECIMAL(5,2), sort_order)`
- `settings(setting_key PRIMARY KEY, setting_value)`: keys: `rounding_rule` (nearest/none/down), `marks_decimals`, `percent_decimals`, `school_name`, `motto`, `deputy_name`, `comment_max_words`, `student_id_pattern`.

**Reports**
- `report_templates(id, name, report_type ENUM('assessment','mid','final'), class_id NULL, html_body LONGTEXT, version, is_default, created_by, created_at)`: `class_id` NULL = all classes.
- `report_log(id, user_id, report_type, assessment_id NULL, section_id NULL, student_id NULL, template_id, created_at)`

**Promotion**
- `promotion_batches(id, user_id, source_year_id, target_year_id, created_at, undone_at NULL)`
- `promotion_batch_rows(id, batch_id, student_id, outcome, from_section_id, to_section_id NULL, old_student_section_id, old_student_status, created_enrollment_id NULL, created_section_id NULL)`

**Logs**
- `audit_log(id, user_id, action, table_name, record_id, old_value TEXT, new_value TEXT, ip, created_at)`
- `login_attempts(id, ip, username, attempted_at)`

---

## 6. HOW THE EXAM STRUCTURE WORKS

Each class in each academic year has its own list of **assessments**. An assessment is one of two modes:

- **entered**: teachers type marks. TMO (Total Marks Obtained) = sum of its components (for example F.A + S.A). Decimals allowed (13.25).
- **calculated**: derived from other assessments of the same class and year. `sum` = add their TMOs (max = sum of their maxes); `average` = average of TMOs. It is computed when read, never stored.

Switching an assessment between modes is allowed from the admin screen. Marks already entered are KEPT but ignored while it is calculated, and reappear if switched back. Show an impact warning and confirm.

A calculated assessment may only use sources from the same class and year, never itself, never a loop (A uses B, B uses A). If any source is missing for a student, the result is **"Incomplete"** (never 0, never U).

Each assessment belongs to a **term group**: `mid` or `final`. Comments and the HRT comment are per term group. `is_term_result = 1` marks the headline assessment of a term (the Mid Term or Final Term itself). `has_attendance = 1` marks assessments that carry attendance.

### Starter presets (seeded; applied to a class from the admin screen)
- **Preset A (Classes 1 and 2):** CP1, CP2, CP3 entered (F.A max 10 + S.A max 15, TMO 25 each, term group mid). MT (Mid Term) calculated = sum CP1+CP2+CP3 (max 75), term result. CP4, CP5, CP6 entered (term group final). FT (Final Term) calculated = sum CP4+CP5+CP6 (max 75), term result.
- **Preset B (Class 3):** CP1, CP2 entered (F.A + S.A). MT entered as a SEPARATE exam: component S.A only, max 25. CP3, CP4 entered. FT entered, S.A only, max 25.
- **Preset C (Classes 4 and 5):** CP1, CP2 entered (F.A + S.A). MT entered, S.A only, max 50. CP3, CP4 entered. FT entered, S.A only, max 50.
- Assumption: CP maxes for presets B and C are F.A 10 and S.A 15 (editable). Attendance is on for the CPs only (editable).
- Admins can also save any class's structure as a preset, copy it to other classes, and add extra calculated assessments (for example "Overall = average of MT and FT") without code changes.

---

## 7. SUBJECTS, CLASS SUBJECTS AND GRADE-ONLY RULES

- Seed subjects. Marks subjects: English Literature, English Language, Urdu Adab, Urdu B, Mathematics, Science, Social Studies, Islamiat. Grade-only subjects: ICT/S.T.E.A.M, Arts, P.E, Nazra, Reading, Public Speaking.
- Each class has only the subjects chosen for it in `class_subjects` (managed in `admin/classes/class_subjects.php`). Removing a subject that has data is blocked; offer "disable".
- Everything that lists subjects for a class (assignments, entry, completeness, reports, max marks, exports) must use `class_subjects` where `is_active = 1`.
- **Grade-only subjects** never show F.A, S.A, TMO, max or percent anywhere. Teachers pick a letter grade per assessment (big buttons from the live grade bands) and write a comment. For calculated assessments the grade is entered by hand. They are excluded from grand totals and "marks entered" counts.
- `max_marks` ignores grade-only subjects.

---

## 8. CALCULATION RULES (all in `includes/calc.php`, nothing hard-coded)

- `percent = TMO / max x 100`.
- Grade: apply the rounding setting to the percent (`nearest` rounds half up to a whole number, so 84.5 becomes 85; `none`; `down`), then take the first grade band, ordered from highest minimum, whose `min_percent` is at or below it.
- Default bands: A* >= 85, A >= 75, B >= 65, C >= 55, D >= 50, U below 50. Bands are editable.
- `evaluate_assessment(student, subject, assessment)` returns tmo, max, percent, grade or "Incomplete". It handles entered and calculated assessments (recursive, with a loop guard).
- Absent counts as 0 for that component (shown as "Abs"). Exempt shows "Exempt" and is left out of totals.
- Grand total for an assessment = sum of TMO over marks subjects with `counts_toward_total = 1` (for example 8 subjects x 25 = 200), with its own percent and grade. Incomplete if any subject is incomplete.
- Attendance: present and total working days are entered; absent = total - present; present cannot exceed total. A term's attendance = sum over that term group's assessments with `has_attendance = 1`.
- **Each academic year has its own grading scale** (`academic_years.grading_scale_id`). Results are always graded with the scale of the year being viewed, so editing grading later never changes old years. Editing bands in `admin/system/grading.php` works on the selected year's scale, shows an impact preview ("12 students would change grade") before saving, and is audit-logged.
- Ignore any "*" in old Excel S.A cells (meaning unknown).

---

## 9. FEATURES

### 9.1 Users and login
Anyone on the network reaches the login page. Login, logout, forced password change on first login, login rate limiting (5 failures in 10 minutes blocks for 10 minutes), 30-minute idle timeout, secure session cookies. `setup_admin.php` creates the first super_admin once and then refuses to run again.

### 9.2 Students
Student list (`admin/students/index.php`) with search (name or BMJS ID), filters (class, section, status), add and edit, status (active, sor, freeze, left). SOR, freeze and left students stay in the database but are hidden from marks entry and printing by default. BMJS ID format like `BmSS-JS-22-1418`, unique, validated by `student_id_pattern`.

**CSV import** (`admin/students/import.php`), as a wizard (Upload, Check, Confirm):
- Accept `.csv` up to 5 MB, detect the delimiter (comma, semicolon, tab), strip a UTF-8 BOM, keep Urdu names intact.
- Smart headers (case-insensitive aliases): id / bmjs id / bmss id / admission # -> bmjs_id; name / student name -> full_name; class / grade -> class; section / sec -> section; dob; status. If a required header is not recognised, show a column-mapping screen.
- Class matching is tolerant: "1", "I", "One", "Class 1", "Grade 1" are the same class.
- Preview first (nothing saved), row by row: NEW, UPDATE, SKIP, ERROR with a plain reason (bad ID, duplicate in file, bad date, unknown class or section). Options (off by default): create missing classes/sections, update existing students, allow moving existing students between sections. Download the error rows as CSV. Confirm to import valid rows in one transaction. Provide a sample CSV download.

### 9.3 Teacher marks entry (the most important screen)
- `my_classes.php`: cards for each assignment showing subject, section, the open exam and a progress ring ("14 of 22 done") with a Continue button.
- `enter_marks.php`: ONE student at a time: name, BMJS ID, initials avatar, big number inputs for each component of that exam with the max shown, and live TMO, percent and grade badge. Show that student's other exams for the same subject for context. Buttons: Previous, **Save & Next** (Enter key), Skip, Absent, Exempt. Side list of students with done/pending status. **Autosave** on every change (`api/save_mark.php`) with a "Saved" indicator. A "Table mode" tab shows the whole class in one grid.
- Server checks on every save: the teacher is assigned, the exam is open, the value is between 0 and max with at most 2 decimals, the student is active.
- `enter_grades.php`: the same one-student flow for grade-only subjects (big grade buttons) plus a comment box with a live word count. Marks subjects never appear there.
- `review_submit.php`: a table of everything entered, missing students highlighted, then Submit (read-only for the teacher afterwards; admin and super_admin can reopen).
- Teachers only see exams in `entered` mode; calculated ones are read-only context. A class-3 Mid Term shows only "S.A /25".

### 9.4 Completeness and checks
`admin/exams/completeness.php` and the admin home: for each section, subject and exam show "17/22 entered" in green (complete), amber (some missing) or red (none), with the missing student names. The home also flags: classes with no subjects, marks subjects with no max marks, subjects with no teacher in a section, sections with no students. Report generation is blocked when data is missing, with the exact list; admins may "print anyway".

### 9.5 Report cards and mail merge
- Templates are HTML stored in `report_templates`, edited in the browser by the super_admin (`admin/system/template_edit.php`: textarea, buttons that insert fields, live preview with a real student). They can be set per class and per type: `assessment` (one chosen exam), `mid`, `final`. One default per type.
- Syntax: `{{student.name}}`, loops `{% for s in subjects %}...{% endfor %}`, conditions `{% if x %}...{% endif %}`.
- The "main assessment" of a report is the chosen exam (type `assessment`) or the `is_term_result` exam of that term group.
- Fields:
  - `student.name`, `student.bmjs_id`, `student.dob`, `class`, `section`, `year`, `school.name`, `school.motto`
  - `report.title`, `issue_date`, `deputy_name`, `class_teacher_name`
  - subjects loop (marks subjects): `s.name`, `s.fa`, `s.sa`, `s.tmo`, `s.max`, `s.percent`, `s.grade`, `s.comment`, plus per-exam values by code such as `s.CP1.tmo`, `s.MT.percent`, `s.MT.grade`
  - grade-only loop: `g.name`, `g.grade`, `g.comment`
  - `grand_total.tmo`, `.max`, `.percent`, `.grade` (and by code, e.g. `grand_total.CP1.tmo`)
  - `attendance.present`, `.absent`, `.total`
  - `hrt_comment`, `promoted_to`, `promotion_outcome`
  - grading scale loop: `b.label`, `b.range` (generated from the live bands, never typed)
- `merge.php` lists unknown fields and suggests the closest known field (for example `studnt.name` -> `student.name`). `merge_fields.php` lists every field with an example. Using `tmo`/`max`/`percent` inside a grade-only loop is an error with a clear message.
- In the report table, "Obtained" = the student's score and "Total" = the maximum. (The old PDF had these swapped.)
- Starter template reproduces the old "CHECKPOINT-01 EVALUATION REPORT": logo, school name and motto, session text, name, grade/section, admission number, subject table (Sr #, Subject, Obtained, Total, %, Grade), ICT and P.E as grade only, grand total, grading-scale box, attendance box, class teacher and deputy directress signature lines, date. The Final Term template adds "Promoted to: Class X".
- Printing: `print.php` renders one A4 page per student using `print.css`; the user prints or saves as PDF from the browser. Every generation is written to `report_log`. A whole section prints in one job.
- `class_sheet.php`: all students x all subjects for one exam, with filters; `export_csv.php` downloads it.
- Report generation and the class sheet accept a year selector (admins) so old years stay viewable; non-active years show a "Viewing 2026-27 (read-only)" banner.

### 9.6 Bulk operations: SELECT, THEN APPLY
"Bulk" means: I select a whole **class** (all its sections), one or more **sections**, or several classes, and then apply a teacher, subjects, a structure or a setting to everything I selected in one go. Never one record at a time.

**The pattern (identical everywhere):**
1. **Choose the target.** The reusable `components/scope_picker.php` lets me pick the academic year (default: active) and then tick a class (meaning all its sections), several classes, or specific sections. It shows live counts ("Class 2: 3 sections, 61 students").
2. **Choose what to apply** (teacher, subjects, structure, setting).
3. **Preview**: a table of exactly what will be added, changed or skipped, with counts and conflicts.
4. **Confirm**: runs in one database transaction, writes one audit entry. Nothing is deleted in bulk (unassign, disable or set a status instead).
Shared helpers (scope resolution, preview building, running, auditing) live in `includes/bulk.php`.

**Bulk actions**
- **Teachers** (`admin/teachers/bulk_assign.php`):
  - *Assign a teacher to a class or section:* target = class or sections; tick the subjects (default: all of that class's subjects); pick the teacher. Creates the assignment for every section x subject in the target.
  - Conflict policy when a section+subject already has another teacher: "Replace" or "Skip" (a default plus a per-row override in the preview).
  - *Replace teacher A with B* across the target (or everywhere).
  - *Copy assignments* from one section to others, or from the previous year.
  - *Remove assignments* for ticked classes, sections or subjects.
  - A secondary grid (sections as rows, subjects as columns, a teacher dropdown in each cell) for fine-tuning.
- **Subjects** (`admin/classes/class_subjects.php`):
  - *Add subjects to a class or several classes:* target = classes; tick subjects; apply.
  - *Copy one class's subject list to other classes.*
  - *Remove or disable subjects* from the target (blocked where data exists).
  - *Set the subject type* (marks or grade-only) for the target.
- **Exam setup** (`admin/exams/structure.php`, `states.php`, `max_marks.php`): apply a structure preset, or copy one class's structure, to the target classes (refused if marks exist); open, close or lock exams for the target; copy max marks from one class to the target; set total working days for the target.
- **Students** (`admin/students/bulk.php`): target = a section (or filtered list, with "select all matching"); move all or ticked students to another section; change status; set the class teacher (HRT) of the target sections.
- **Users** (`admin/system/user_import.php`, super_admin only): import teachers from CSV with generated temporary passwords and a printable credentials sheet shown once; bulk disable/enable; bulk reset passwords. Never touches the logged-in user or creates admins.
- Undo is only offered for promotion (Section 9.8); everything else uses preview and confirm.

### 9.7 Academic year rollover (`admin/year_end/rollover.php`, super_admin)
Copies configuration from a source year into a NEW (or empty) year. The new year starts inactive. Presented as a wizard.
- Checklist (all ticked by default), each with a preview count: sections (option: keep HRT), subjects per class, exam structures (all exams set to `draft`), max marks, teacher assignments (re-pointed to the new sections; option: only if the teacher is still active), grading scale (a copy of the source year's scale).
- NEVER copied: marks, grades, comments, attendance, submissions, audit log, report history.
- One transaction, one audit entry. Refuses if the target already has configuration unless "merge" is ticked (existing items are skipped, never overwritten). "Reset this year's configuration" is allowed only while the new year has no marks, grades, comments or attendance.
- After success: buttons "Promote students", "Review structure", "Review subjects", "Activate this year". Activating makes it the only active year.

### 9.8 Promotion (`admin/year_end/promotion.php`, admin and super_admin)
One-click promotion of students to the next class, same section name, in the next academic year (Class 1 Mars -> Class 2 Mars).
- Next class comes from `classes.next_class_id` (filled from `sort_order`; editable in `admin/classes/index.php`; NULL = graduating).
- The page lists classes and sections with student count, "Final Term report generated", and buttons: [Promote] per section, [Promote whole class], [Promote all ready sections]. A section is READY once its Final Term report appears in `report_log` (admin-only "Promote anyway"). Warn (do not block) if exams are not locked or students are Incomplete.
- Clicking a button opens a confirmation panel with counts (promoted, held back, graduating, skipped, blocked), the target class and section, and a student list where I can change each student's outcome (Promote, Hold back, Graduate) before confirming. Outcomes are stored in `student_year_outcome`, so they can also be set before printing report cards.
- Target section = same-named section of the next class in the next year. If missing: BLOCKED with an optional "create missing sections" checkbox (default off). Hold back = same class, same section name, next year. Students with status sor, freeze or left are skipped (optional checkbox to include freeze).
- Running it creates `student_enrollments` rows for the target year and updates `students.section_id`. Marks, grades, comments and old enrollments are never changed. It is idempotent (a double-click or refresh never promotes anyone twice).
- Undo is allowed while none of those students has data in the target year (the super_admin may reopen after 7 days). If the next year does not exist yet, show a link to the rollover wizard.
- `{{promoted_to}}` works BEFORE promotion: next class for promote, same class for hold back, "Graduated" for graduate.
- This is the only promotion tool.

### 9.9 Audit log and backup
- `admin/system/audit_log.php`: search by user, action, date; paginated. Log logins, failures, every create/update/delete, and every bulk or rollover action.
- `admin/system/backup.php` (super_admin): "Backup now" runs `mysqldump` into `database/backups/`, lists and downloads backups. Provide a `backup.bat` for a daily Windows Task Scheduler job.

---

## 10. EASY TO USE AND GOOD LOOKING (this matters as much as the features)

**Simplicity principles**
- A school office admin with no IT background should manage everything with a one-page guide. Every task takes the fewest clicks possible and uses everyday words. Never remove a function to simplify a screen; move rarely used options under "More options".
- **Admin home (`dashboard.php`)** answers "What do you want to do?" with large task cards: Add or import students, Set up a class, Check marks progress, Print report cards, Manage teachers, Start a new year, Promote students. Below: a "Needs attention" list (missing marks, classes with no subjects, subjects with no teacher), each item with a fix link. A "Setup checklist" with ticks (year, classes, sections, subjects, class subjects, exam structure, max marks, teachers, students) shows until setup is done.
- **Short menu (admin):** Home, Students, Classes and Subjects, Exams and Marks, Teachers, Reports, Year-end. Super admin gets one extra group, "System". These match the folders `admin/students`, `classes`, `exams`, `teachers`, `year_end`, `system`. No third menu level.
- Each page has ONE primary action (a large button). Forms show only the essential fields (about 5 to 6); everything else sits under a collapsed "More options". Good defaults so most fields can stay untouched.
- Guided wizards (with "Step 2 of 4", a Back button, a summary before the final confirm) for: class setup, new academic year, promotion, CSV import.
- Plain words: "Teachers enter marks" / "Added up from other exams" instead of entered/calculated; "Class subjects"; one-line explanation under each page title; "?" tips beside confusing fields; the same wording everywhere.
- Risky actions show a confirmation stating exactly what changes and how many records. Harmless actions never interrupt. Messages are short toasts in plain language with the fix suggested.
- Every list page has the same layout: title, short description, search, filters, table, primary action at the top right. Empty screens say what to do next with a button. Remember the last-used class, section and year filters.

**Exam structure screen (`admin/exams/structure.php`) must be especially easy**
- One card per exam in order, grouped under "Mid Term" and "Final Term", each saying in plain words what it is: "Mid Term: added up from CP1 + CP2 + CP3 (max 75)" or "Mid Term: separate exam, S.A only, max 50".
- One toggle per exam to switch type with a one-line explanation of what changes. Max marks editable here with "apply to all subjects" and "copy from another class".
- A live preview: "Teachers will see: CP1 (F.A /10, S.A /15)" and "Report card will show: CP1, CP2, CP3, Mid Term".
- Buttons: Apply ready-made structure, Copy to other classes, Save as my preset, Reset; a "Check this structure" button listing problems in plain language.

**Visual design:** modern, clean, calm and professional. School colours (deep red and navy, a touch of gold), lots of white space, rounded cards, soft shadows. Desktop first (1366x768 and up).

Define these tokens once in `css/style.css` and use only them:
```css
:root{
  --red:#9B1C2E; --red-dark:#7A1624; --navy:#1B2A4E; --navy-soft:#2D3F6E; --gold:#C9A24B;
  --bg:#F4F6FA; --surface:#FFFFFF; --text:#1F2937; --muted:#6B7280; --border:#E5E7EB;
  --success:#15803D; --warning:#B45309; --danger:#B91C1C; --info:#1D4ED8;
  --radius:12px; --shadow:0 2px 10px rgba(27,42,78,.08);
  --font:"Inter","Segoe UI",system-ui,sans-serif;
}
[data-theme="dark"]{ --bg:#0F172A; --surface:#1E293B; --text:#E5E7EB; --muted:#94A3B8; --border:#334155; }
```
Grade badge colours: A* green, A teal, B blue, C amber, D orange, U red, Incomplete grey. Colour is never the only signal (always show the text too).

**Layout:** fixed left sidebar (navy) with the school logo, simple inline-SVG icons (no icon libraries), the menu for the user's role, and the user name with logout at the bottom. A top bar shows the page title, the active academic year and a light/dark toggle. Content sits in white cards on a light grey background. Tables: sticky header, zebra rows, hover highlight, search, pagination. Forms: labels above inputs, inline validation in plain language, disabled buttons while saving, a simple modal for confirmations, toasts for results.

**Key screens**
- **Login:** split layout, logo and motto on a navy panel, a clean form card.
- **Admin home:** task cards, "Needs attention", setup checklist, a red/amber/green completeness grid.
- **Teacher home:** friendly greeting, assignment cards with progress rings and a large Continue button.
- **Marks entry (the hero screen):** a centered card with a large student header (initials avatar, name, ID), big number boxes with the max in the corner, a live grade badge, a progress bar across the top ("Student 7 of 22"), a "Saved" tick, large Previous / Save & Next buttons, a side list of students with done/pending dots, and small keyboard hints (Enter = next).
- **Report card print:** crisp A4 layout, school colour border, logo, tidy table, page break after each student.

**Quality bar:** consistent spacing and font sizes, readable contrast, visible focus outlines, fully keyboard friendly, right-to-left rendering for Urdu text (`dir="auto"` on name fields), no layout shifts, friendly error pages (403, 404, 500). Fonts are bundled locally in `fonts/` (system fonts as fallback).

---

## 11. VALIDATION (summary)
Marks numeric, 0 to the component max, up to 2 decimals. Present <= total days. BMJS ID unique and valid. One section per student per year. Only assigned teachers enter marks, only in open exams. Double-click protection on saves and bulk actions. Unsaved-changes warning on the entry screen.

## 12. SETTINGS (super_admin, `admin/system/settings.php`)
School name, motto, deputy name, rounding rule, marks decimals, percent decimals, comment max words, student ID pattern.

## 13. ACCEPTANCE TESTS (automate in `tests/` where practical)
1. Eight marks subjects, one exam, TMOs 25, 23.5, 21.25, 22, 25, 24.75, 23.5, 21.25 (each max 25): total 186.25/200 = 93% = A*. Subject percents 100, 94, 85, 88, 100, 99, 94, 85.
2. Setting A* to 90 turns the two 85% subjects and the 88% subject into A, and the preview shows this before saving.
3. Preset A: CP1=25, CP2=22, CP3=25 gives MT = 72/75 = 96% = A*. With CP2 missing, MT = Incomplete.
4. Preset A: switch MT to entered (S.A max 75) and enter 60: 80% = A and CP changes do not affect it. Switch back: the CP sum returns and the entered 60 is still in the database.
5. Preset B: MT entered 20/25 = 80% = A. Preset C: MT entered 45/50 = 90% = A*.
6. Changing CP1's max in Preset A updates MT's max.
7. Loops and cross-class sources are rejected. A new calculated exam "Overall" works without code.
8. A mark of 11 in F.A (max 10) is rejected.
9. A grade-only subject shows no F.A/S.A or max anywhere and is excluded from the grand total (still 200 for 8 subjects).
10. ICT is added to Classes 1 to 3 by selecting those three classes once, and appears nowhere else.
11. Teacher cannot open other subjects, other sections, locked exams or any admin page (also by editing the URL or posting to `api/`).
12. Bulk teacher assignment: select Class 2 (3 sections), tick Mathematics and Science, choose a teacher: the preview shows 6 assignments; a section+subject that already has another teacher is shown as a conflict; confirm creates exactly the chosen rows; one audit entry is written. Selecting only the section "Mars" assigns just that section.
13. CSV import: "Class 3" and "III" match the same class; a bad row is reported; duplicates in the file are caught.
14. Rollover copies configuration with counts matching the preview, exams are `draft`, no marks exist, running it twice is refused.
15. Promotion: Class 1 Mars -> Class 2 Mars next year; held-back students stay; the last class graduates; a double-click promotes nobody twice; old-year report cards still show the old class and section; `{{promoted_to}}` is correct before and after.
16. Editing the grading scale later does not change an old year's grades.

## 14. DEMO DATA (`seed.sql` or a demo script)
Active year "2026-27"; classes I to V with the `next_class_id` chain; two sections each; about 20 fake students per section; the 14 subjects with class subjects assigned; presets A, B, C applied by class; default grading bands and settings; a few teacher accounts with assignments.

## 15. DEFINITION OF DONE FOR EVERY PHASE
Files filled in, the "Done when" checks pass, no PHP warnings, no console errors, security rules followed, `docs/PROGRESS.md` updated.

---

## 16. BUILD PHASES

Build in this order. Do not skip ahead.

**Phase 0: Skeleton and design system.** `config`, `bootstrap.php`, `db.php`, `helpers.php`, adapt the existing `header.php` and `footer.php`, add `sidebar`, `topbar`, `flash`, full `css/style.css` with the tokens, `install_check.php`. *Done when:* the page shows the logo and "Database connected" in the new look, light and dark.

**Phase 1: Database.** `schema.sql` (all tables, keys, foreign keys), `seed.sql` (components, subjects, default grading scale and bands, settings, presets A/B/C). *Done when:* both import cleanly in phpMyAdmin.

**Phase 2: Login and roles.** Section 9.1, `csrf.php`, `auth.php`, `audit.php`, menu per role, 403 page. *Done when:* the super admin can be created, log in and out, a wrong password fails, a teacher sees a 403 on an admin page.

**Phase 3: School setup.** `years`, `classes` (with next class), `sections`, `subjects`, `class_subjects` including the select-then-apply tools for subjects (Sections 7 and 9.6). *Done when:* ICT can be given to Classes 1 to 3 in one action.

**Phase 4: Users and teacher assignments.** `system/users.php`, `teachers/assignments.php`. *Done when:* a teacher is assigned a subject in a section, and only subjects the class has are offered.

**Phase 5: Students and CSV import.** Section 9.2, `csv.php`, students pages, enrollment rows. *Done when:* `sample_students.csv` imports with preview, bad rows are reported, SOR students are hidden by default.

**Phase 6: Exam structure.** Section 6: `structure.php`, `exams/structure.php`, `states.php`, `max_marks.php`. *Done when:* applying Preset A to a class and switching MT to entered both work, and loops are rejected.

**Phase 7: Calculation and grading.** Section 8: `calc.php`, tests 1 to 8, `system/grading.php` with impact preview. *Done when:* `php tests/test_calc.php` and `php tests/test_structure.php` print PASS everywhere.

**Phase 8: Teacher marks entry.** Section 9.3: `my_classes.php`, `enter_marks.php`, `marks_entry.js`, `api/save_mark.php`, table mode. *Done when:* a teacher walks through a class entering marks, 11 for F.A is rejected, other teachers' URLs and locked exams are refused.

**Phase 9: Grades, comments, attendance, submit.** `enter_grades.php`, the two other API files, `review_submit.php`, `exams/attendance.php`. *Done when:* grade-only subjects show no F.A/S.A, submitted work is read-only for the teacher.

**Phase 10: Admin home and completeness.** Section 9.4 and the task-card home in Section 10. *Done when:* the red/amber/green grid matches what was entered and missing names are listed.

**Phase 11: Reports.** Section 9.5: `merge.php`, template pages, `generate.php`, `print.php`, `print.css`, `class_sheet.php`, `export_csv.php`, starter templates. *Done when:* the printed card matches acceptance test 1, a misspelled field shows a suggestion, and the page prints one A4 sheet per student.

**Phase 12: Audit log and backup.** Section 9.9. *Done when:* a backup file restores in phpMyAdmin.

**Phase 13: Bulk operations.** Section 9.6: `scope_picker.php`, `includes/bulk.php`, `teachers/bulk_assign.php`, student, exam and user bulk tools. *Done when:* acceptance test 12 passes.

**Phase 14: Year rollover and promotion.** Sections 9.7 and 9.8. *Done when:* acceptance tests 14 to 16 pass.

**Phase 15: Hardening and guides.** Review every file against the security rules, confirm `display_errors` is off in the sample config, run `tests/test_permissions.php`, write `admin_guide.md` and `teacher_guide.md` (one page each, plain language). *Done when:* the full acceptance list passes.

---

## 17. OPEN ITEMS (the AI must NOT guess; ask me)
- What the "*" in old S.A cells means (ignored for now).
- Exact CP maxes for Classes 3 to 5 (assumed 10 and 15).
- Whether a Class 3 to 5 term result is the exam alone (assumed yes; an "Overall" calculated exam can combine more).
- Which classes show Reading and Public Speaking on report cards (controlled by `show_on_report`).

---

## docs/PROGRESS.md (create in Phase 0 and keep updated)
```
# Progress
- [ ] Phase 0  - [ ] Phase 1  - [ ] Phase 2  - [ ] Phase 3  - [ ] Phase 4
- [ ] Phase 5  - [ ] Phase 6  - [ ] Phase 7  - [ ] Phase 8  - [ ] Phase 9
- [ ] Phase 10 - [ ] Phase 11 - [ ] Phase 12 - [ ] Phase 13 - [ ] Phase 14
- [ ] Phase 15
## Notes / known issues
```

## KICK-OFF MESSAGE (type this into the AI for each phase)
```
Read docs/SPEC.md and docs/PROGRESS.md. Do Phase N only (Section 16), following Sections 0 to 15.
Give complete files, then tell me how to test it, then stop.
```

## 5. DATABASE: ALREADY CREATED (do not rebuild)

The database is ALREADY DESIGNED AND CREATED. `database/schema.sql` and `database/seed.sql` are final, were tested on MariaDB 10.11, and have been imported into phpMyAdmin (database name `bmjs`, 30 tables).

RULES FOR THE AI:
- Do NOT create, drop or alter tables. Do NOT rewrite or "improve" schema.sql or seed.sql. Read schema.sql to get exact table and column names before writing any query.
- If a database change is truly needed, STOP and ask me. If I agree, put it in a numbered file `database/migrations/NNN_description.sql` (never edit schema.sql after real data exists).
- Never invent columns or tables. Use only what is in schema.sql.

Differences from the table list below (schema.sql wins):
- NEW table `structure_presets(id, name, description, definition LONGTEXT JSON, is_builtin, created_by, created_at)`. Presets A, B, C are stored here (JSON format is explained in a comment in seed.sql). "Save as my preset" adds a row with is_builtin = 0.
- `comments` also has `academic_year_id` (always set it) and a generated column `subject_key` (NEVER write to it). HRT comment = subject_id NULL.
- `academic_years` has a generated column `active_marker` (never write to it). The database allows only ONE active year. To switch: in one transaction, first set the old year's is_active = 0, THEN set the new one to 1. A single UPDATE that does both fails.
- `academic_years.grading_scale_id` is required. Rollover copies the source year's scale into a new `grading_scales` row and its `grade_bands`.
- `marks`, `grade_entries`, `attendance`, `comments` also have `updated_at`, and the first three have `entered_by`/`updated_by`; fill them.
- Settings keys: rounding_rule, marks_decimals, percent_decimals, school_name, school_short_name, motto, deputy_name, comment_max_words, student_id_pattern (a PHP regex WITH delimiters, e.g. /^(?=.*\d)[A-Za-z0-9-]{3,30}$/).
- seed.sql already created: components F.A and S.A, the default grade bands, settings, the 14 subjects, presets A/B/C, the year 2026-27 (active), and classes I to V with next_class_id set. It creates NO users: the first super admin is made by setup_admin.php. Demo students, sections and teachers are NOT in seed.sql.

What the database ALREADY enforces (the app must still show friendly messages): unique BMJS ID, one active year, one teacher per section+subject, one HRT comment per student/year/term, present <= total days, no negative marks or max marks, a calculated assessment needs a calc_method, an assessment cannot be its own source, no deleting exams that have marks or sections that have students.

What the database CANNOT enforce (the app MUST check): a mark is not above its component's maximum; calculated exams only use sources from the same class and year and never form a loop; a teacher only enters marks for their own assignments and only in open exams; grade-only subjects never get marks or max marks.