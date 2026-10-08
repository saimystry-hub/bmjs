<?php
$classQuery = db()->prepare(
    'SELECT DISTINCT c.id, c.name FROM classes c
     JOIN sections sec ON sec.class_id = c.id AND sec.academic_year_id = :year
     JOIN student_enrollments se ON se.section_id = sec.id AND se.academic_year_id = sec.academic_year_id
     ORDER BY c.sort_order, c.id'
);
$classQuery->execute([':year' => $yearId]);
$checkClasses = $classQuery->fetchAll();
$checkClassId = (int) ($_GET['check_class_id'] ?? ($checkClasses[0]['id'] ?? 0));
$checkClassName = '';
foreach ($checkClasses as $class) if ((int) $class['id'] === $checkClassId) $checkClassName = (string) $class['name'];
$examQuery = db()->prepare('SELECT id, code, name FROM assessments WHERE academic_year_id = :year AND class_id = :class ORDER BY sort_order, id');
$examQuery->execute([':year' => $yearId, ':class' => $checkClassId]);
$checkExams = $examQuery->fetchAll();
$checkExamCode = (string) ($_GET['check_exam'] ?? ($checkExams[0]['code'] ?? ''));
$checkExamValid = false;
foreach ($checkExams as $exam) if ((string) $exam['code'] === $checkExamCode) $checkExamValid = true;
if (!$checkExamValid && $checkExams !== []) $checkExamCode = (string) $checkExams[0]['code'];
?>
<section class="card"><h2 class="card-title">Check results</h2><form method="get" class="filter-grid"><input type="hidden" name="year_id" value="<?= h((string) $yearId) ?>"><label class="form-field compact">Class<select name="check_class_id"><?php foreach ($checkClasses as $class): ?><option value="<?= h((string) $class['id']) ?>" <?= (int) $class['id'] === $checkClassId ? 'selected' : '' ?>><?= h($class['name']) ?></option><?php endforeach; ?></select></label><label class="form-field compact">Exam<select name="check_exam"><?php foreach ($checkExams as $exam): ?><option value="<?= h($exam['code']) ?>" <?= $exam['code'] === $checkExamCode ? 'selected' : '' ?>><?= h($exam['code'] . ' — ' . $exam['name']) ?></option><?php endforeach; ?></select></label><button class="button button-secondary">Show</button></form>
<?php if ($checkClasses === []): ?><p class="empty-state">No students are enrolled in this academic year yet.</p>
<?php elseif ($checkExams === []): ?><p class="empty-state">This class has no exams yet.</p>
<?php else:
    $studentQuery = db()->prepare('SELECT st.id, st.full_name FROM student_enrollments se JOIN students st ON st.id = se.student_id JOIN sections sec ON sec.id = se.section_id WHERE se.academic_year_id = :year AND sec.class_id = :class ORDER BY st.id LIMIT 10');
    $studentQuery->execute([':year' => $yearId, ':class' => $checkClassId]);
    $checkStudents = $studentQuery->fetchAll();
    $checkStudentIds = array_map('intval', array_column($checkStudents, 'id'));
    $checkResults = $checkStudentIds === [] ? [] : calc_for_students($yearId, $checkClassId, $checkStudentIds);
    $checkData = $checkStudentIds === [] ? [] : load_calc_data($yearId, $checkClassId, $checkStudentIds);
    $hasMarks = false;
    foreach ($checkData['marks'] ?? [] as $bySubject) foreach ($bySubject as $byExam) foreach ($byExam[$checkExamCode] ?? [] as $mark) if (($mark['status'] ?? '') !== 'entered' || $mark['value'] !== null) $hasMarks = true;
    $marksSubjects = array_filter($checkData['subjects'] ?? [], static fn(array $subject): bool => $subject['kind'] === 'marks');
    if (!$hasMarks): ?>
<p class="empty-state">No marks have been entered yet. To try this, run <code>tests\seed_test_marks.php</code>.</p>
<?php elseif ($checkStudents === []): ?><p class="empty-state">No students are enrolled in this class yet.</p>
<?php else: ?>
<div class="table-wrap grading-results-wrap"><table class="grading-results-table"><thead><tr><th>Student</th><?php foreach ($marksSubjects as $subject): ?><th><?= h($subject['name']) ?></th><?php endforeach; ?><th>Grand total</th></tr></thead><tbody>
<?php foreach ($checkStudents as $student): $studentId = (int) $student['id']; $calculated = $checkResults[$studentId] ?? ['subjects' => [], 'grand' => []]; ?><tr><th><?= h($student['full_name']) ?></th>
<?php foreach ($marksSubjects as $subjectId => $subject): $result = $calculated['subjects'][$subjectId][$checkExamCode] ?? ['status' => 'incomplete']; ?><td><?php if ($result['status'] === 'ok'): ?><?= h(fmt_marks($result['tmo'], (int) $checkData['marks_decimals'])) ?> / <?= h(fmt_marks($result['max'], (int) $checkData['marks_decimals'])) ?>, <?= h(fmt_percent($result['percent'], (int) $checkData['percent_decimals'])) ?>%, <?= h((string) $result['grade']) ?><?= !empty($result['absent']) ? ' (Abs)' : '' ?><?php else: ?><?= h(result_grade_text($result)) ?><?php endif; ?></td><?php endforeach; ?>
<?php $grand = $calculated['grand'][$checkExamCode] ?? ['status' => 'na']; ?><td><?php if ($grand['status'] === 'ok'): ?><?= h(fmt_marks($grand['tmo'], (int) $checkData['marks_decimals'])) ?> / <?= h(fmt_marks($grand['max'], (int) $checkData['marks_decimals'])) ?>, <?= h(fmt_percent($grand['percent'], (int) $checkData['percent_decimals'])) ?>%, <?= h((string) $grand['grade']) ?><?php else: ?><?= h(result_grade_text($grand)) ?><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><p class="muted">Showing up to 10 students in <?= h($checkClassName) ?>.</p>
<?php endif; endif; ?></section>
