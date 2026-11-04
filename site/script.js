/* Buildless presentation. All demo interactions are local; no AI calls are made. */
(() => {
  'use strict';
  const languageKey = 'betternotebook-site-language';
  const languageToggle = document.querySelector('#language-toggle');
  let savedLanguage;
  try { savedLanguage = localStorage.getItem(languageKey); } catch { /* Storage may be disabled. */ }
  const browserLanguage = navigator.languages?.[0] || navigator.language || '';
  let language = ['tr', 'en'].includes(savedLanguage)
    ? savedLanguage
    : /^tr(?:-|$)/i.test(browserLanguage) ? 'tr' : 'en';
  const translate = source => language === 'en' && Object.hasOwn(englishCopy, source) ? englishCopy[source] : source;
  const textNodes = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const source = node.nodeValue.trim();
    if (Object.hasOwn(englishCopy, source)) textNodes.push({node, original: node.nodeValue, source});
  }
  const attributes = [...document.querySelectorAll('[aria-label], img[alt], meta[name="description"]')]
    .map(element => {
      const name = element.hasAttribute('aria-label') ? 'aria-label' : element.hasAttribute('alt') ? 'alt' : 'content';
      return {element, name, source: element.getAttribute(name)};
    });
  const originalTitle = document.title;

  function applyLanguage() {
    document.documentElement.lang = language;
    document.title = translate(originalTitle);
    for (const {node, original, source} of textNodes) {
      const translation = translate(source);
      node.nodeValue = translation === '.' ? '.' : original.replace(source, () => translation);
    }
    for (const {element, name, source} of attributes) element.setAttribute(name, translate(source));
    languageToggle.textContent = language === 'tr' ? 'EN' : 'TR';
    languageToggle.lang = language === 'tr' ? 'en' : 'tr';
    languageToggle.setAttribute('aria-label', language === 'tr' ? 'Switch to English' : 'Türkçeye geç');
    languageToggle.hidden = false;
  }

  document.querySelector('#year').textContent = new Date().getFullYear();
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const shell = document.querySelector('.product-shell');
  const tabs = [...document.querySelectorAll('.demo-tabs [data-demo]')];
  const addButton = document.querySelector('#add-answer');
  const toast = document.querySelector('#toast');
  let toastTimer;
  let added = false;
  const notify = (message) => {
    clearTimeout(toastTimer);
    toast.querySelector('span').textContent = translate(message);
    toast.classList.add('visible');
    toastTimer = setTimeout(() => toast.classList.remove('visible'), 3200);
  };
  const setScene = scene => {
    if (!['read', 'ask', 'write'].includes(scene)) return;
    shell.dataset.scene = scene;
    tabs.forEach(tab => {
      const selected = tab.dataset.demo === scene;
      tab.classList.toggle('selected', selected);
      tab.setAttribute('aria-pressed', String(selected));
    });
    if (scene === 'write') added = true;
    addButton.querySelector('span').textContent = translate(added ? 'Notuna eklendi' : 'Notuma ekle');
    addButton.querySelector('use').setAttribute('href', added ? '#i-check' : '#i-plus');
    addButton.setAttribute('aria-label', translate(added ? 'Örnek yanıta not panelinde bak' : 'Örnek AI yanıtını notuma ekle'));
  };
  document.querySelectorAll('[data-demo]').forEach(button => button.addEventListener('click', () => setScene(button.dataset.demo)));
  document.querySelectorAll('[data-demo-jump]').forEach(button => button.addEventListener('click', () => {
    setScene(button.dataset.demoJump);
    document.querySelector('#urun-demosu').scrollIntoView({ behavior: reducedMotion ? 'instant' : 'smooth', block: 'start' });
  }));
  addButton.addEventListener('click', () => {
    setScene('write');
    notify('Yanıt örnek notuna eklendi.');
  });
  document.querySelector('#demo-question').addEventListener('click', () => {
    setScene('ask');
    notify('Bu bir ürün önizlemesi. Gelişmeleri GitHub’dan takip edebilirsin.');
  });
  tabs.forEach((tab, index) => tab.addEventListener('keydown', event => {
    let next;
    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
    if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    if (next !== undefined) { event.preventDefault(); tabs[next].focus(); setScene(tabs[next].dataset.demo); }
  }));
  const menuButton = document.querySelector('.menu-toggle');
  const nav = document.querySelector('#navigation');
  function closeMenu() { nav.classList.remove('open'); menuButton.setAttribute('aria-expanded', 'false'); menuButton.setAttribute('aria-label', translate('Menüyü aç')); }
  menuButton.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') !== 'true';
    nav.classList.toggle('open', open);
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', translate(open ? 'Menüyü kapat' : 'Menüyü aç'));
  });
  document.addEventListener('click', event => { if (!nav.contains(event.target) && !menuButton.contains(event.target)) closeMenu(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && nav.classList.contains('open')) { closeMenu(); menuButton.focus(); } });
  nav.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
  languageToggle.addEventListener('click', () => {
    language = language === 'tr' ? 'en' : 'tr';
    try { localStorage.setItem(languageKey, language); } catch { /* Switching still works in this tab. */ }
    applyLanguage();
    closeMenu();
    setScene(shell.dataset.scene);
    toast.classList.remove('visible');
  });
  applyLanguage();
  const dialog = document.querySelector('#cloud-dialog');
  document.querySelector('#cloud-info').addEventListener('click', () => dialog.showModal());
  dialog.querySelectorAll('.dialog-close').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => {
    const box = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
  });
  if ('IntersectionObserver' in window) {
    if (!reducedMotion) {
      document.body.classList.add('motion-ready');
      const revealObserver = new IntersectionObserver(entries => entries.forEach(entry => {
        if (entry.isIntersecting) { entry.target.classList.add('visible'); revealObserver.unobserve(entry.target); }
      }), { threshold: 0.08, rootMargin: '0px 0px -15px 0px' });
      document.querySelectorAll('.reveal').forEach(element => revealObserver.observe(element));
    }
    const sectionObserver = new IntersectionObserver(entries => entries.forEach(entry => {
      if (entry.isIntersecting) nav.querySelectorAll('a').forEach(link => {
        const selected = link.getAttribute('href') === '#' + entry.target.id;
        link.classList.toggle('active', selected);
        if (selected) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current');
      });
    }), { rootMargin: '-10% 0px -60% 0px', threshold: 0 });
    ['deneyim', 'ozellikler', 'sana-gore'].forEach(id => sectionObserver.observe(document.getElementById(id)));
  }
})();
