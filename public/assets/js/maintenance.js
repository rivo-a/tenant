document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('newRequestModal');
    const openBtn = document.getElementById('openModalBtn');
    const closeBtns = [
        document.getElementById('closeModalBtn'),
        document.getElementById('cancelModalBtn'),
        document.getElementById('modalBackdrop')
    ];
    const form = document.getElementById('maintenanceForm');
    const submitBtn = document.getElementById('submitBtn');
    const submitLabel = document.getElementById('submitLabel');
    const submitSpinner = document.getElementById('submitSpinner');

    // Check if there are PHP validation errors, if so, open modal automatically
    const hasErrors = document.querySelector('.text-red-600') !== null;
    if (hasErrors) {
        modal.classList.remove('hidden');
    }

    function openModal() {
        modal.classList.remove('hidden');
        // Focus trap could be added here for full a11y
        document.getElementById('category').focus();
    }

    function closeModal() {
        modal.classList.add('hidden');
        form.reset(); // Clear form on close
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    closeBtns.forEach(btn => {
        if (btn) btn.addEventListener('click', closeModal);
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    // Form submission loading state
    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            // Basic client-side validation check before showing spinner
            if (!form.checkValidity()) return;
            
            submitBtn.disabled = true;
            submitLabel.textContent = 'Submitting...';
            submitSpinner.classList.remove('hidden');
        });
    }
});