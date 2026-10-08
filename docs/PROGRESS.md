# Progress
- [x] Phase 0 - Skeleton and design system
- [x] Phase 1 - Database (created and imported manually)
- [x] Phase 2 - Login and roles
- [x] Phase 3 - School setup
- [x] Phase 4 - Users and teacher assignments
- [x] Phase 5 - Students and CSV import
- [x] Phase 6 - Exam structure (built; Phase 6 and logic tests pass)
- [ ] Phase 7 - Calculation and grading (built; waiting for user testing)
- [ ] Phase 8 - Teacher marks entry
- [ ] Phase 9 - Grades, comments, attendance and submit
- [ ] Phase 10 - Admin home and completeness
- [ ] Phase 11 - Reports
- [ ] Phase 12 - Audit log and backup
- [ ] Phase 13 - Year-end tools
- [ ] Phase 14 - Security and polishing
- [ ] Phase 15 - Final verification

## Notes / known issues
- Fixed: admin password reset no longer asks for a current password.
- Phase 6 is built and waiting for user testing.

## Phase 6 checklist
- [x] 1. Pure functions in `includes/structure.php` and `tests/test_structure.php`
- [x] 2. `includes/structure_edit.php`
- [x] 3. `includes/structure_apply.php`
- [x] 4. `includes/max_marks.php`
- [x] 5. Exam structure page, JavaScript and related styling
- [x] 6. Maximum marks page
- [x] 7. Open and close exams page
- [x] 8. Dashboard checklist and CSS
- [x] 9. Progress and changelog updated

## Phase 7 checklist
- [x] 1. Pure calculation functions in `includes/calc.php` and 20 checks in `tests/test_calc.php`
- [x] 2. Set-based database loader in `includes/calc_load.php`
- [x] 3. Year grading reads, impact preview and saving in `includes/grading.php`
- [x] 4. Super-admin JSON preview endpoint in `api/preview_grading.php`
- [x] 5. Grade bands page, results check, JavaScript and styles
- [x] 6. Command-line test marks tool in `tests/seed_test_marks.php`
- [x] 7. Progress and changelog updated
