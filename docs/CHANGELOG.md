# docs\CHANGELOG.md\n- Fixed the super-admin user-management bug: user creation and password resets no longer request a current password, reset actions prompt once with a confirmation, self-reset is blocked, and password payloads are never logged.
- Added Phase 6 exam structures, presets, calculated maxima, maximum-mark entry/copy tools, and safe exam-state controls.
- Completed the Phase 6 follow-up checks: preset maximums are validated, maximum-mark completion counts only required cells, and state and preset changes keep their audit entries in the same transaction.
- Restored Phase 7 calculations, grading rules and previews, a read-only results check, and a command-line test-data tool; the workspace did not contain `docs/PHASE_7.md`, so the supplied Phase 7 instructions were used.
