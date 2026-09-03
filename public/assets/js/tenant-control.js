(() => {
  const toggle = document.getElementById('mobileNavToggle');
  const mobileNav = document.getElementById('mobileNav');
  const desktopMore = document.getElementById('desktopMore');

  if (toggle && mobileNav) {
    const setMenuOpen = (isOpen) => {
      mobileNav.classList.toggle('hidden', !isOpen);
      toggle.setAttribute('aria-expanded', String(isOpen));
    };

    toggle.addEventListener('click', () => {
      setMenuOpen(mobileNav.classList.contains('hidden'));
    });

    mobileNav.addEventListener('click', (event) => {
      const link = event.target instanceof Element
        ? event.target.closest('a')
        : null;

      if (link) {
        setMenuOpen(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !mobileNav.classList.contains('hidden')) {
        setMenuOpen(false);
        toggle.focus({ preventScroll: true });
      }
    });
  }

  if (desktopMore) {
    const summary = desktopMore.querySelector('summary');

    document.addEventListener('click', (event) => {
      if (desktopMore.open && event.target instanceof Node && !desktopMore.contains(event.target)) {
        desktopMore.removeAttribute('open');
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && desktopMore.open) {
        desktopMore.removeAttribute('open');
        summary?.focus({ preventScroll: true });
      }
    });
  }

  const form = document.getElementById('tenantOnboardingForm');
  const submit = document.getElementById('tenantOnboardingSubmit');

  if (form && submit) {
    form.addEventListener('submit', () => {
      if (form.getAttribute('aria-busy') === 'true') {
        return;
      }

      form.setAttribute('aria-busy', 'true');
      submit.disabled = true;
      submit.textContent = submit.dataset.submittingLabel || 'Onboarding…';
    });
  }
})();
