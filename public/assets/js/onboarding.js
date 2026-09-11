document.addEventListener('DOMContentLoaded', function () {
    const copyButton = document.getElementById('copy-temp-password');
    const copyLabel = document.getElementById('copy-label');
    const copyIcon = document.getElementById('copy-icon');

    if (!copyButton || !copyLabel || !copyIcon) {
        return;
    }

    // We will read the password from a data attribute instead of inline PHP
    const password = copyButton.getAttribute('data-password');

    copyButton.addEventListener('click', async function () {
        if (!password) {
            return;
        }

        try {
            await navigator.clipboard.writeText(password);

            copyLabel.textContent = 'Copied!';
            copyIcon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />`;
            
            copyButton.classList.remove('bg-white', 'text-slate-900');
            copyButton.classList.add('bg-emerald-500', 'text-white');

            setTimeout(function () {
                copyLabel.textContent = 'Copy password';
                copyIcon.innerHTML = `<rect width="13" height="13" x="9" y="9" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1" />`;
                
                copyButton.classList.remove('bg-emerald-500', 'text-white');
                copyButton.classList.add('bg-white', 'text-slate-900');
            }, 2000);

        } catch (error) {
            copyLabel.textContent = 'Copy failed';
            setTimeout(function () {
                copyLabel.textContent = 'Copy password';
            }, 2000);
        }
    });
});