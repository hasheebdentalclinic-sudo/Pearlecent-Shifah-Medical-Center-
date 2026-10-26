// Pearlecent site script. Everything is an enhancement: pages read and book without it.
(() => {
  const WA = '971553396152';
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const y = document.querySelector('[data-year]');
  if (y) y.textContent = new Date().getFullYear();

  // header shadow once the page scrolls; mobile menu
  const hdr = document.querySelector('[data-hdr]');
  const onScroll = () => hdr && hdr.classList.toggle('is-stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true }); onScroll();
  const burger = document.querySelector('[data-burger]');
  const menu = document.getElementById('menu');
  if (burger && menu) {
    const set = (open) => { burger.setAttribute('aria-expanded', open); menu.classList.toggle('is-open', open); };
    burger.addEventListener('click', () => set(burger.getAttribute('aria-expanded') !== 'true'));
    menu.addEventListener('click', (e) => { if (e.target.closest('a')) set(false); });
    addEventListener('keydown', (e) => { if (e.key === 'Escape') set(false); });
  }

  // reveal once on entry
  const reveal = $$('[data-reveal]');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } }), { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
    reveal.forEach((el) => io.observe(el));
    addEventListener('load', () => setTimeout(() => reveal.forEach((el) => { const r = el.getBoundingClientRect(); if (r.top < innerHeight && r.bottom > 0) el.classList.add('is-in'); }), 2500));
  } else reveal.forEach((el) => el.classList.add('is-in'));

  // numbers count up when they come into view
  const counts = $$('[data-count]');
  if (!reduce && 'IntersectionObserver' in window) {
    const fmt = (v, src) => src.includes(',') ? Math.round(v).toLocaleString('en-US') : (src.includes('.') ? v.toFixed(1) : String(Math.round(v)));
    const io = new IntersectionObserver((es) => es.forEach((e) => {
      if (!e.isIntersecting) return; io.unobserve(e.target);
      const el = e.target, src = el.textContent.trim(), end = parseFloat(el.dataset.count.replace(/,/g, ''));
      const start = src === '2018' ? 1990 : 0, t0 = performance.now(), dur = 1400;
      const tick = (t) => { const k = Math.min(1, (t - t0) / dur), ease = 1 - Math.pow(1 - k, 4); el.textContent = fmt(start + (end - start) * ease, src); if (k < 1) requestAnimationFrame(tick); else el.textContent = src; };
      requestAnimationFrame(tick);
    }), { threshold: 0.6 });
    counts.forEach((c) => io.observe(c));
  }

  // the tooth leans gently towards the pointer
  if (!reduce && matchMedia('(pointer: fine)').matches) {
    $$('[data-tilt-el]').forEach((el) => {
      const host = el.closest('.hero2, .pearl') || el.parentElement;
      host.addEventListener('pointermove', (e) => {
        const r = host.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - 0.5, yy = (e.clientY - r.top) / r.height - 0.5;
        el.style.transform = `perspective(900px) rotateY(${x * 14}deg) rotateX(${-yy * 10}deg) translate(${x * 10}px, ${yy * 8}px)`;
      });
      host.addEventListener('pointerleave', () => { el.style.transform = ''; });
      el.style.transition = 'transform 500ms cubic-bezier(0.22, 1, 0.36, 1)';
    });
  }

  // the WhatsApp booking form
  $$('[data-composer]').forEach((form) => {
    const days = form.querySelector('[data-days]');
    const morning = form.querySelector('option[data-morning]');
    const timeSel = form.querySelector('select[name="time"]');
    const fmt = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'Asia/Dubai' });
    const wk = new Intl.DateTimeFormat('en-GB', { weekday: 'long', timeZone: 'Asia/Dubai' });
    const now = Date.now();
    for (let i = 0; i < 7; i++) {
      const d = new Date(now + i * 864e5);
      const label = i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : fmt.format(d);
      const o = document.createElement('option');
      o.value = (i < 2 ? label + ', ' : '') + fmt.format(d);
      o.textContent = i < 2 ? label + ' (' + fmt.format(d) + ')' : label;
      o.dataset.friday = wk.format(d) === 'Friday' ? '1' : '';
      days.appendChild(o);
    }
    const preview = form.querySelector('[data-preview]');
    const hidden = form.querySelector('input[name="text"]');
    const build = () => {
      const f = new FormData(form);
      const day = days.selectedOptions[0];
      if (morning) {
        const fri = day && day.dataset.friday === '1';
        morning.disabled = fri;
        if (fri && morning.selected) timeSel.value = '';
      }
      const t = f.getAll('treatment').filter(Boolean);
      const lines = ['Hello Pearlecent, I would like to book an appointment.'];
      const name = (f.get('name') || '').toString().trim();
      if (name) lines.push('Name: ' + name);
      const mob = (f.get('mobile') || '').toString().trim();
      if (mob) lines.push('Mobile: ' + mob);
      if (t.length) lines.push('Treatment: ' + t.join(', '));
      lines.push('Dentist: ' + (f.get('dentist') || 'Any dentist'));
      lines.push('Day: ' + (f.get('day') || 'Any day'));
      lines.push('Time: ' + (f.get('time') || 'Any time'));
      const msg = lines.join('\n');
      hidden.value = msg;
      if (preview) { preview.textContent = msg; }
      return msg;
    };
    form.addEventListener('change', build);
    form.addEventListener('input', build);
    form.addEventListener('submit', (ev) => { ev.preventDefault(); location.href = 'https://wa.me/' + WA + '?text=' + encodeURIComponent(build()); });
    build();
  });

  // the reviews slider
  $$('[data-carousel]').forEach((c) => {
    const slides = $$('[data-slide]', c);
    const label = c.querySelector('[data-count-label]');
    const bar = c.querySelector('[data-bar]');
    let i = 0, timer = null;
    const go = (n) => {
      const prev = slides[i];
      prev.classList.remove('is-on'); prev.classList.add('is-out');
      setTimeout(() => prev.classList.remove('is-out'), 700);
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('is-on');
      label.textContent = (i + 1) + ' / ' + slides.length;
      run();
    };
    const run = () => { if (!bar || reduce) return; bar.classList.remove('is-run'); void bar.offsetWidth; if (timer) bar.classList.add('is-run'); };
    const start = () => { if (reduce) return; stop(); timer = setInterval(() => go(i + 1), 6500); run(); };
    const stop = () => { clearInterval(timer); timer = null; if (bar) bar.classList.remove('is-run'); };
    c.querySelector('[data-next]').addEventListener('click', () => { go(i + 1); start(); });
    c.querySelector('[data-prev]').addEventListener('click', () => { go(i - 1); start(); });
    c.addEventListener('mouseenter', stop); c.addEventListener('mouseleave', start);
    c.addEventListener('focusin', stop);
    let x0 = null;
    c.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    c.addEventListener('touchend', (e) => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) { go(i + (dx < 0 ? 1 : -1)); start(); } x0 = null; });
    if ('IntersectionObserver' in window) new IntersectionObserver((es) => es.forEach((e) => (e.isIntersecting ? start() : stop()))).observe(c);
    else start();
  });

  // treatments page: highlight the group in view
  const jumps = $$('.jump__a');
  if (jumps.length && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((es) => es.forEach((e) => {
      if (!e.isIntersecting) return;
      jumps.forEach((a) => { const on = a.getAttribute('href') === '#' + e.target.id; a.classList.toggle('is-on', on); if (on) a.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }); });
    }), { rootMargin: '-45% 0px -50% 0px' });
    $$('.tsec').forEach((s) => io.observe(s));
  }

  // hide the floating WhatsApp button while the booking form is on screen
  const fl = document.querySelector('[data-wa-float]');
  const book = document.getElementById('book');
  if (fl && book && 'IntersectionObserver' in window) {
    new IntersectionObserver((es) => es.forEach((e) => fl.classList.toggle('is-hidden', e.isIntersecting)), { threshold: 0.1 }).observe(book);
  }
})();

// keep the giant hero headline on one line at any width
(() => {
  const h = document.querySelector('.hero2__h');
  if (!h) return;
  const fit = () => {
    if (innerWidth < 768) { h.style.fontSize = ''; return; }
    h.style.fontSize = '';
    const avail = h.clientWidth, need = [...h.children].reduce((s, c) => s + c.getBoundingClientRect().width, 0) * 1.06;
    if (need > avail) h.style.fontSize = (parseFloat(getComputedStyle(h).fontSize) * avail / need) + 'px';
  };
  (document.fonts ? document.fonts.ready : Promise.resolve()).then(fit);
  addEventListener('resize', fit);
})();

// branded pickers: replace the phone's grey system list with a pink sheet
(() => {
  const sels = [...document.querySelectorAll('.sel select')];
  if (!sels.length) return;
  const TICK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  let open = null;
  const close = () => {
    if (!open) return;
    const { sheet, btn } = open; open = null;
    sheet.classList.remove('is-open'); btn.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('pk-lock');
    setTimeout(() => sheet.remove(), 260);
    btn.focus({ preventScroll: true });
  };
  sels.forEach((sel) => {
    const wrap = sel.closest('.sel');
    const label = wrap.closest('.fld')?.querySelector('.fld__l')?.textContent || '';
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'field pk-btn'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    const val = document.createElement('span'); val.className = 'pk-val'; btn.appendChild(val);
    const sync = () => { const o = sel.selectedOptions[0]; val.textContent = o ? o.textContent : ''; btn.classList.toggle('is-empty', !sel.value); };
    sel.classList.add('pk-native'); sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');
    wrap.insertBefore(btn, sel); sync();
    sel.addEventListener('change', sync);
    btn.addEventListener('click', () => {
      close();
      const sheet = document.createElement('div');
      sheet.className = 'pk';
      sheet.innerHTML = `<div class="pk__scrim" data-x></div><div class="pk__panel" role="dialog" aria-label="${label}"><div class="pk__head"><span class="pk__grab"></span><b>${label}</b><button type="button" class="pk__x" data-x aria-label="Close">×</button></div><ul class="pk__list" role="listbox"></ul></div>`;
      const list = sheet.querySelector('.pk__list');
      [...sel.options].forEach((o, i) => {
        const li = document.createElement('li');
        li.setAttribute('role', 'option'); li.tabIndex = o.disabled ? -1 : 0;
        li.className = 'pk__opt' + (o.selected ? ' is-on' : '') + (o.disabled ? ' is-off' : '') + (o.value === '' ? ' is-any' : '');
        li.setAttribute('aria-selected', o.selected); li.innerHTML = `<span></span><i>${TICK}</i>`;
        li.firstChild.textContent = o.textContent + (o.disabled ? ' · closed on Friday' : '');
        const pick = () => { if (o.disabled) return; sel.selectedIndex = i; sel.dispatchEvent(new Event('change', { bubbles: true })); close(); };
        li.addEventListener('click', pick);
        li.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } if (e.key === 'ArrowDown') li.nextElementSibling?.focus(); if (e.key === 'ArrowUp') li.previousElementSibling?.focus(); });
        list.appendChild(li);
      });
      sheet.addEventListener('click', (e) => { if (e.target.closest('[data-x]')) close(); });
      document.body.appendChild(sheet);
      if (innerWidth >= 768) {
        const r = btn.getBoundingClientRect(), panel = sheet.querySelector('.pk__panel');
        panel.style.left = r.left + 'px'; panel.style.width = Math.max(r.width, 280) + 'px';
        const below = innerHeight - r.bottom - 16;
        if (below > 260) { panel.style.top = (r.bottom + 6) + 'px'; panel.style.maxHeight = below + 'px'; }
        else { panel.style.bottom = (innerHeight - r.top + 6) + 'px'; panel.style.maxHeight = (r.top - 16) + 'px'; }
      } else document.documentElement.classList.add('pk-lock');
      open = { sheet, btn };
      btn.setAttribute('aria-expanded', 'true');
      requestAnimationFrame(() => { sheet.classList.add('is-open'); (list.querySelector('.is-on') || list.firstChild).focus({ preventScroll: true }); list.querySelector('.is-on')?.scrollIntoView({ block: 'center' }); });
    });
  });
  addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  addEventListener('resize', close);
  addEventListener('scroll', () => { if (open && innerWidth >= 768) close(); }, { passive: true });
})();
