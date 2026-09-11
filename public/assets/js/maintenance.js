document.addEventListener('DOMContentLoaded', function () {
    console.log('✅ Maintenance JS file loaded successfully');

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

    // 1. Debug: Check if elements exist
    if (!modal) console.error('❌ Modal element not found!');
    if (!openBtn) console.error('❌ Open Button element not found!');

    // 2. Open Modal Function
    function openModal() {
        console.log('🖱️ Open button clicked');
        if (modal) {
            modal.classList.remove('hidden');
            const categoryInput = document.getElementById('category');
            if (categoryInput) categoryInput.focus();
        }
    }

    // 3. Close Modal Function
    function closeModal() {
        if (modal) {
            modal.classList.add('hidden');
            if (form) form.reset(); // Clear form on close
        }
    }

    // 4. Attach Event Listeners
    if (openBtn) {
        openBtn.addEventListener('click', openModal);
    }

    closeBtns.forEach(btn => {
        if (btn) btn.addEventListener('click', closeModal);
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    // 5. Form Submission Loading State
    if (form && submitBtn) {
        form.addEventListener('submit', function (e) {
            console.log('📤 Form submitted');
            
            // If HTML5 validation fails, stop and don't show spinner
            if (!form.checkValidity()) {
                console.log('⚠️ Form validation failed');
                return;
            }
            
            submitBtn.disabled = true;
            submitLabel.textContent = 'Submitting...';
            if (submitSpinner) submitSpinner.classList.remove('hidden');
        });
    }

    // 6. Auto-open modal if PHP validation failed (red text exists)
    const hasErrors = document.querySelector('.text-red-600') !== null;
    if (hasErrors && modal) {
        console.log('⚠️ Validation errors found, auto-opening modal');
        modal.classList.remove('hidden');
    }
});