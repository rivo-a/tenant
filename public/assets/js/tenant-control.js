(() => {
  const toggle = document.getElementById('mobileNavToggle');
  const mobileNav = document.getElementById('mobileNav');

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
