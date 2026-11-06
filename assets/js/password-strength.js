(function () {
    function initializePasswordForms() {
        document.querySelectorAll('[data-password-form]').forEach(function (form) {
            if (form.dataset.passwordInitialized === 'true') return;
            form.dataset.passwordInitialized = 'true';

            const password = form.querySelector('[data-password-new]');
            const confirmation = form.querySelector('[data-password-confirm]');
            const button = form.querySelector('[data-password-submit]');
            const strength = form.querySelector('[data-password-strength]');
            const match = form.querySelector('[data-password-match]');
            const rules = Array.from(form.querySelectorAll('[data-password-rule]'));

            if (!password) return;

            function updateIndicator() {
                const value = password.value;
                const results = {
                    length: value.length >= 8,
                    uppercase: /[A-Z]/.test(value),
                    lowercase: /[a-z]/.test(value),
                    number: /[0-9]/.test(value),
                    special: /[^A-Za-z0-9]/.test(value)
                };
                const passed = Object.values(results).filter(Boolean).length;

                rules.forEach(function (item) {
                    const valid = Boolean(results[item.dataset.passwordRule]);
                    const icon = item.querySelector('[data-password-rule-icon]');
                    item.classList.toggle('text-emerald-700', valid);
                    item.classList.toggle('text-slate-500', !valid);
                    if (icon) {
                        icon.textContent = valid ? '\u25CF' : '\u25CB';
                        icon.classList.toggle('text-emerald-600', valid);
                        icon.classList.toggle('text-slate-400', !valid);
                    }
                });

                if (strength) {
                    const state = passed <= 2 ? 'Weak' : (passed < 5 ? 'Moderate' : 'Strong');
                    strength.textContent = value === '' ? 'Not set' : state;
                    strength.className = 'text-sm font-semibold ' + (state === 'Strong' ? 'text-emerald-700' : state === 'Moderate' ? 'text-amber-700' : 'text-rose-700');
                }

                const passwordsMatch = confirmation && confirmation.value !== '' && value === confirmation.value;
                if (match) {
                    match.textContent = confirmation && confirmation.value === '' ? '' : (passwordsMatch ? 'Passwords match.' : 'Passwords do not match.');
                    match.className = 'text-sm ' + (passwordsMatch ? 'text-emerald-700' : 'text-rose-700');
                }

                if (button) button.disabled = !Object.values(results).every(Boolean) || !passwordsMatch;
            }

            password.addEventListener('input', updateIndicator);
            if (confirmation) confirmation.addEventListener('input', updateIndicator);
            form.addEventListener('submit', function (event) {
                updateIndicator();
                if (button && button.disabled) event.preventDefault();
            });
            updateIndicator();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePasswordForms);
    } else {
        initializePasswordForms();
    }
}());
