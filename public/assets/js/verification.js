document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('toggle-code-btn');
    const masked = document.getElementById('daily-code-masked');
    const real = document.getElementById('daily-code-real');

    if (!toggleBtn || !masked || !real) {
        return;
    }

    toggleBtn.addEventListener('click', function () {
        const isHidden = real.hidden;

        real.hidden = !isHidden;
        masked.hidden = isHidden;

        if (isHidden) {
            this.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg"
                     class="h-4 w-4"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3.98 8.223A10.477 10.477 0 001.934 12
                             C3.226 16.338 7.243 19 12 19
                             c1.5 0 2.91-.29 4.2-.81M6.228 6.228
                             A10.45 10.45 0 0112 5
                             c4.757 0 8.774 2.662 10.066 7
                             a10.523 10.523 0 01-4.132 5.411
                             M6.228 6.228L3 3m3.228 3.228L21 21
                             M12 9a3 3 0 103 3"/>
                </svg>
                <span>Hide code</span>
            `;

            this.setAttribute('aria-label', 'Hide verification code');
        } else {
            this.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg"
                     class="h-4 w-4"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.458 12C3.732 7.943 7.523 5 12 5
                             c4.478 0 8.268 2.943 9.542 7
                             -1.274 4.057-5.064 7-9.542 7
                             -4.477 0-8.268-2.943-9.542-7z"/>
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>Show code</span>
            `;

            this.setAttribute('aria-label', 'Show verification code');
        }
    });
});