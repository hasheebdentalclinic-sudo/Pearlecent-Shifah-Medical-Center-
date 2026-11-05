// Pearlecent admin: small conveniences. Everything still works without JavaScript.
(() => {
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.dataset.confirm)) e.preventDefault();
  });
  document.querySelectorAll('select[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.requestSubmit ? s.form.requestSubmit() : s.form.submit()));
  document.querySelectorAll('[data-drop]').forEach((zone) => {
    const input = zone.querySelector('input[type=file]');
    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) return;
      let img = zone.querySelector('img');
      if (!img) { img = document.createElement('img'); img.alt = ''; zone.appendChild(img); }
      const ph = zone.querySelector('.drop__ph'); if (ph) ph.remove();
      img.src = URL.createObjectURL(file);
      zone.classList.add('is-new');
    });
  });
  document.querySelectorAll('form').forEach((f) => f.addEventListener('submit', () => {
    const b = f.querySelector('button[type=submit]');
    if (b && f.enctype === 'multipart/form-data') { b.disabled = true; b.textContent = 'Saving…'; }
  }));
})();
