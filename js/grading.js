(() => {
  const form = document.querySelector('[data-grading-page]');
  if (!form) return;

  const rows = form.querySelector('[data-band-rows]');
  // Read the current grade rows and rounding choice from the form.
  const labels = () => [...form.querySelectorAll('[name="band_label[]"]')].map((field) => field.value.trim());
  const minimums = () => [...form.querySelectorAll('[name="band_min[]"]')].map((field) => field.value.trim());
  const rounding = () => form.querySelector('[name="rounding"]:checked')?.value || 'nearest';
  const cents = (value) => /^\d+(?:\.\d{1,2})?$/.test(value) ? Math.round(Number(value) * 100) : null;
  const bands = () => labels().map((label, index) => ({ label, min: minimums()[index] === '' ? NaN : Number(minimums()[index]) }));

  // Calculate the grade in the browser with the same integer formulas as PHP.
  const gradeFor = (tmoC, maxC, currentBands, rule) => {
    if (maxC <= 0) return null;
    let whole = null;
    if (rule === 'nearest') whole = Math.floor((2 * tmoC * 100 + maxC) / (2 * maxC));
    if (rule === 'down') whole = Math.floor((tmoC * 100) / maxC);
    const ordered = [...currentBands].sort((a, b) => b.min - a.min);
    const matched = ordered.find((band) => rule === 'none'
      ? tmoC * 10000 >= Math.round(band.min * 100) * maxC
      : whole * 100 >= Math.round(band.min * 100));
    return matched?.label || null;
  };

  // Update the local example as grade rows, rounding, or example marks change.
  const updateTry = () => {
    const percentField = form.querySelector('[data-try-percent]');
    const marksField = form.querySelector('[data-try-marks]');
    const maxField = form.querySelector('[data-try-max]');
    const percentText = percentField.value.trim();
    let tmoC = null;
    let maxC = null;
    if (percentText !== '') {
      tmoC = cents(percentText);
      maxC = 10000;
    } else if (marksField.value.trim() !== '' && maxField.value.trim() !== '') {
      tmoC = cents(marksField.value.trim());
      maxC = cents(maxField.value.trim());
    }
    const result = form.querySelector('[data-try-result]');
    if (tmoC === null || maxC === null || maxC <= 0) {
      result.textContent = '';
      return;
    }
    result.textContent = 'Grade: ' + (gradeFor(tmoC, maxC, bands(), rounding()) || 'No grade');
  };

  // Add a blank grade row while preserving the current form without saving it.
  const addBand = () => {
    const row = document.createElement('tr');
    row.innerHTML = '<td><input name="band_label[]" maxlength="10" aria-label="Grade label"></td><td><input name="band_min[]" inputmode="decimal" aria-label="Minimum percentage"></td><td><button type="button" class="button button-small button-secondary" data-remove-band>Remove</button></td>';
    rows.append(row);
  };

  form.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-band]')) addBand();
    const remove = event.target.closest('[data-remove-band]');
    if (remove) remove.closest('tr').remove();
    updateTry();
  });
  form.addEventListener('input', updateTry);
  form.addEventListener('change', updateTry);

  // Ask the protected preview endpoint for a quiet impact estimate while typing.
  let previewTimer = 0;
  const previewLine = form.querySelector('[data-live-impact]');
  const requestImpact = async () => {
    const rule = rounding();
    const currentBands = bands();
    if (!currentBands.length || currentBands.some((band) => !band.label || !Number.isFinite(band.min))) {
      previewLine.textContent = '';
      return;
    }
    const token = form.querySelector('[name="csrf_token"]')?.value || '';
    previewLine.textContent = 'Checking the possible changes...';
    try {
      const response = await fetch(form.dataset.previewUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ year_id: Number(form.dataset.year), bands: currentBands, rounding: rule }),
      });
      const body = await response.json();
      if (!response.ok || !body.ok) {
        previewLine.textContent = body.errors?.[0] || 'The possible changes could not be checked.';
        return;
      }
      previewLine.textContent = 'Would change ' + body.impact.results_changed + ' results.';
    } catch {
      previewLine.textContent = 'The possible changes could not be checked right now.';
    }
  };
  form.addEventListener('input', () => {
    window.clearTimeout(previewTimer);
    previewTimer = window.setTimeout(requestImpact, 600);
  });
  updateTry();
})();
