(() => {
  if (window.location.hash === '#apply-preset') {
    const presetPanel = document.querySelector('#apply-preset');
    if (presetPanel) presetPanel.open = true;
  }

  document.querySelectorAll('[data-open-structure-check]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = document.querySelector('#structure-check');
      if (panel) {
        panel.open = true;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  });

  const editor = document.querySelector('[data-exam-editor]');
  if (editor) {
    const radios = editor.querySelectorAll('input[name="mode"]');
    const entered = editor.querySelector('[data-entered-panel]');
    const calculated = editor.querySelector('[data-calculated-panel]');
    const warning = editor.querySelector('[data-impact-warning]');
    const sourcePreview = editor.querySelector('[data-source-preview]');
    const updateSources = () => {
      if (!sourcePreview) return;
      const codes = [...editor.querySelectorAll('input[name="source_ids[]"]:checked')]
        .map((input) => input.dataset.sourceCode || '');
      const method = editor.querySelector('[name="calc_method"]')?.value || 'sum';
      const code = editor.querySelector('[name="code"]')?.value
        || editor.querySelector('input[readonly]')?.value
        || 'This exam';
      const joiner = method === 'average' ? ' and ' : ' + ';
      sourcePreview.textContent = codes.length
        ? `${code} = ${method === 'average' ? 'average of ' : ''}${codes.join(joiner)}`
        : 'Choose at least one exam.';
    };
    const updatePanels = () => {
      const mode = editor.querySelector('input[name="mode"]:checked')?.value || 'entered';
      if (entered) entered.hidden = mode !== 'entered';
      if (calculated) calculated.hidden = mode !== 'calculated';
      if (warning) {
        const changed = mode !== warning.dataset.originalMode;
        warning.hidden = !changed;
        const copy = warning.querySelector('[data-impact-copy]');
        if (copy) {
          const count = warning.dataset.markCount;
          copy.textContent = mode === 'calculated'
            ? `${count} marks have been entered. They will be kept, but ignored while this exam is worked out. If you switch back, they count again. `
            : `${count} marks have been entered. They will be kept and count again while teachers enter marks. `;
        }
        const confirm = warning.querySelector('input[name="confirm_impact"]');
        if (confirm && !changed) confirm.checked = false;
      }
    };
    radios.forEach((radio) => radio.addEventListener('change', updatePanels));
    editor.querySelectorAll('input[name="source_ids[]"], [name="calc_method"], [name="code"]')
      .forEach((control) => control.addEventListener('change', updateSources));
    editor.querySelector('[name="code"]')?.addEventListener('input', updateSources);
    updatePanels();
    updateSources();
  }

  document.querySelectorAll('[data-apply-column]').forEach((button) => {
    button.addEventListener('click', () => {
      const value = window.prompt('Enter the maximum marks for every marks subject:');
      if (value === null || value.trim() === '') return;
      if (!window.confirm('Overwrite this maximum for every marks subject?')) return;
      const [examId, columnId] = button.dataset.applyColumn.split(':');
      const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
      const form = document.createElement('form');
      form.method = 'post';
      [['csrf_token', csrf], ['action', 'apply_column'], ['exam_id', examId], ['column_id', columnId], ['value', value]].forEach(([name, fieldValue]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = fieldValue;
        form.append(input);
      });
      document.body.append(form);
      form.submit();
    });
  });
})();
