// Initialize shared theme, toast, and confirmation behavior.
(() => {
  const root = document.documentElement;
  const themeButton = document.querySelector('[data-theme-toggle]');

  // Apply and save the selected color theme.
  const setTheme = (theme) => {
    const nextTheme = theme === 'dark' ? 'dark' : 'light';
    root.dataset.theme = nextTheme;
    try {
      localStorage.setItem('bmjs-theme', nextTheme);
    } catch (error) {
      // Keep the selected theme for this page when browser storage is unavailable.
    }
    if (themeButton) {
      themeButton.setAttribute('aria-label', `Switch to ${nextTheme === 'dark' ? 'light' : 'dark'} theme`);
    }
  };

  try {
    const savedTheme = localStorage.getItem('bmjs-theme');
    if (savedTheme) {
      root.dataset.theme = savedTheme === 'dark' ? 'dark' : 'light';
    }
  } catch (error) {
    // Keep the theme supplied by the page when browser storage is unavailable.
  }
  // Keep the theme button label aligned with the theme restored for this page.
  if (themeButton) {
    themeButton.setAttribute(
      'aria-label',
      `Switch to ${root.dataset.theme === 'dark' ? 'light' : 'dark'} theme`
    );
  }

  if (themeButton) {
    // Toggle between the two supported themes when the button is activated.
    themeButton.addEventListener('click', () => {
      setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
    });
  }
  document.querySelectorAll('[data-toast-dismiss]').forEach((button) => {
    // Remove a toast when its dismiss control is clicked.
    button.addEventListener('click', () => button.closest('.toast')?.remove());
  });
  document.querySelectorAll('.toast').forEach((toast) => {
    // Automatically remove each toast after six seconds.
    window.setTimeout(() => toast.remove(), 6000);
  });
  // Show a temporary success toast from the design preview button.
  document.querySelector('[data-preview-toast]')?.addEventListener('click', () => {
    const toast = document.createElement('div');
    toast.className = 'toast toast-success';
    toast.setAttribute('role', 'status');
    const message = document.createElement('span');
    message.textContent = 'Sample toast: Your changes have been saved.';
    const dismiss = document.createElement('button');
    dismiss.className = 'toast-dismiss';
    dismiss.type = 'button';
    dismiss.textContent = '×';
    dismiss.setAttribute('aria-label', 'Dismiss message');
    // Dismiss the preview toast when its close control is clicked.
    dismiss.addEventListener('click', () => toast.remove());
    toast.append(message, dismiss);
    document.querySelector('.content-area, .standalone-page')?.prepend(toast);
    // Automatically remove the preview toast after six seconds.
    window.setTimeout(() => toast.remove(), 6000);
  });
  // Stop a click action when its confirmation is declined.
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
    if (target && !window.confirm(target.dataset.confirm || 'Are you sure?')) {
      event.preventDefault();
    }
  });
  // Stop a form submission when its confirmation is declined.
  document.addEventListener('submit', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
    if (target && !window.confirm(target.dataset.confirm || 'Are you sure?')) {
      event.preventDefault();
    }
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-check-all]') : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    const group = target.getAttribute('data-check-all');
    const checked = !target.dataset.checked || target.dataset.checked === 'false';
    target.dataset.checked = String(checked);
    document.querySelectorAll(`input[data-group="${group}"]`).forEach((checkbox) => {
      checkbox.checked = checked;
    });
  });

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-toggle-row]') : null;
    if (!target) {
      return;
    }
    event.preventDefault();
    const rowId = target.getAttribute('data-toggle-row');
    const columnId = target.getAttribute('data-toggle-col');
    document.querySelectorAll(`input[data-row="${rowId}"]`).forEach((checkbox) => {
      checkbox.checked = !checkbox.checked;
    });
    if (columnId) {
      document.querySelectorAll(`input[data-col="${columnId}"]`).forEach((checkbox) => {
        checkbox.checked = !checkbox.checked;
      });
    }
  });
})();
