-- database\seed.sql: Default grade bands, subjects, settings, exam presets
-- =====================================================================
-- BmJS Result Management System: SEED DATA
-- Import AFTER schema.sql (phpMyAdmin > Import). Safe to re-run: it never
-- duplicates rows and never overwrites settings you have already changed.
--
-- It does NOT create any users. The first super admin is created by
-- setup_admin.php in the website.
-- =====================================================================

USE bmjs;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Marks columns
-- ---------------------------------------------------------------------
INSERT IGNORE INTO components (id, name, sort_order) VALUES
  (1, 'F.A', 1),
  (2, 'S.A', 2);

-- ---------------------------------------------------------------------
-- 2. Default grading scale and bands (all editable in the website)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO grading_scales (id, name) VALUES (1, 'Default scale');

INSERT IGNORE INTO grade_bands (scale_id, grade_label, min_percent, sort_order) VALUES
  (1, 'A*', 85.00, 1),
  (1, 'A',  75.00, 2),
  (1, 'B',  65.00, 3),
  (1, 'C',  55.00, 4),
  (1, 'D',  50.00, 5),
  (1, 'U',   0.00, 6);

-- ---------------------------------------------------------------------
-- 3. Settings
-- ---------------------------------------------------------------------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('rounding_rule',     'nearest'),
  ('marks_decimals',    '2'),
  ('percent_decimals',  '0'),
  ('school_name',       'Benchmark Junior School G.T. Road Campus'),
  ('school_short_name', 'BmJS - G.T.R.C'),
  ('motto',             'Realising Your Dreams'),
  ('deputy_name',       ''),
  ('comment_max_words', '50'),
  ('student_id_pattern','/^(?=.*\\d)[A-Za-z0-9-]{3,30}$/');

-- ---------------------------------------------------------------------
-- 4. Subjects (8 marks subjects, 6 grade-only subjects)
--    show_on_report is just a starting point; change it per class later.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO subjects (name, short_name, type, counts_toward_total, show_on_report, sort_order) VALUES
  ('English Literature', 'Eng Lit',   'graded',     1, 1,  1),
  ('English Language',   'Eng Lang',  'graded',     1, 1,  2),
  ('Urdu Adab',          'Urdu Adab', 'graded',     1, 1,  3),
  ('Urdu B',             'Urdu B',    'graded',     1, 1,  4),
  ('Mathematics',        'Maths',     'graded',     1, 1,  5),
  ('Science',            'Science',   'graded',     1, 1,  6),
  ('Social Studies',     'SST',       'graded',     1, 1,  7),
  ('Islamiat',           'Islamiat',  'graded',     1, 1,  8),
  ('ICT/S.T.E.A.M',      'ICT',       'grade_only', 0, 1,  9),
  ('P.E',                'P.E',       'grade_only', 0, 1, 10),
  ('Arts',               'Arts',      'grade_only', 0, 0, 11),
  ('Nazra',              'Nazra',     'grade_only', 0, 0, 12),
  ('Reading',            'Reading',   'grade_only', 0, 0, 13),
  ('Public Speaking',    'Pub Spk',   'grade_only', 0, 0, 14);

-- ---------------------------------------------------------------------
-- 5. Exam structure presets A, B, C
--
--    JSON format of 'definition':
--      assessments: list in display order. For each one:
--        code, name, term_group ("mid" or "final"),
--        mode ("entered" = teachers type marks, "calculated" = worked out),
--        calc_method ("sum" or "average", null when entered),
--        components: marks columns, e.g. ["F.A","S.A"] (empty when calculated),
--        max: default maximum per component, applied to every marks subject,
--        sources: codes of the exams a calculated one is built from,
--        has_attendance (1/0), is_term_result (1/0).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO structure_presets (name, description, definition, is_builtin) VALUES
('Preset A: Classes 1 and 2',
 'CP1-3 then Mid Term added up from them; CP4-6 then Final Term added up from them',
 '{"version":1,"assessments":[
  {"code":"CP1","name":"Checkpoint 1","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP2","name":"Checkpoint 2","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP3","name":"Checkpoint 3","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"MT","name":"Mid Term","term_group":"mid","mode":"calculated","calc_method":"sum","components":[],"max":{},"sources":["CP1","CP2","CP3"],"has_attendance":0,"is_term_result":1},
  {"code":"CP4","name":"Checkpoint 4","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP5","name":"Checkpoint 5","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP6","name":"Checkpoint 6","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"FT","name":"Final Term","term_group":"final","mode":"calculated","calc_method":"sum","components":[],"max":{},"sources":["CP4","CP5","CP6"],"has_attendance":0,"is_term_result":1}
 ]}', 1),
('Preset B: Class 3',
 '2 checkpoints then a separate Mid Term exam (S.A, 25 marks); 2 checkpoints then a separate Final Term exam (S.A, 25 marks)',
 '{"version":1,"assessments":[
  {"code":"CP1","name":"Checkpoint 1","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP2","name":"Checkpoint 2","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"MT","name":"Mid Term","term_group":"mid","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":25},"sources":[],"has_attendance":0,"is_term_result":1},
  {"code":"CP3","name":"Checkpoint 3","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP4","name":"Checkpoint 4","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"FT","name":"Final Term","term_group":"final","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":25},"sources":[],"has_attendance":0,"is_term_result":1}
 ]}', 1),
('Preset C: Classes 4 and 5',
 '2 checkpoints then a separate Mid Term exam (S.A, 50 marks); 2 checkpoints then a separate Final Term exam (S.A, 50 marks)',
 '{"version":1,"assessments":[
  {"code":"CP1","name":"Checkpoint 1","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP2","name":"Checkpoint 2","term_group":"mid","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"MT","name":"Mid Term","term_group":"mid","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":50},"sources":[],"has_attendance":0,"is_term_result":1},
  {"code":"CP3","name":"Checkpoint 3","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"CP4","name":"Checkpoint 4","term_group":"final","mode":"entered","calc_method":null,"components":["F.A","S.A"],"max":{"F.A":10,"S.A":15},"sources":[],"has_attendance":1,"is_term_result":0},
  {"code":"FT","name":"Final Term","term_group":"final","mode":"entered","calc_method":null,"components":["S.A"],"max":{"S.A":50},"sources":[],"has_attendance":0,"is_term_result":1}
 ]}', 1);

-- ---------------------------------------------------------------------
-- 6. OPTIONAL starting data: first academic year and classes I to V.
--    Delete this block if you prefer to create them in the website.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO academic_years (name, is_active, grading_scale_id) VALUES ('2026-27', 1, 1);

INSERT IGNORE INTO classes (name, sort_order) VALUES
  ('I',   1),
  ('II',  2),
  ('III', 3),
  ('IV',  4),
  ('V',   5);

-- Link each class to the next one (the last class stays NULL = graduates).
UPDATE classes c
  JOIN classes n ON n.sort_order = c.sort_order + 1
   SET c.next_class_id = n.id
 WHERE c.next_class_id IS NULL;

-- ===================== END OF SEED =====================