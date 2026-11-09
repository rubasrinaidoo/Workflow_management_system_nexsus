document.querySelectorAll('[data-toggle-password]').forEach((button) => {

    const field = document.getElementById(
        button.dataset.togglePassword
    );

    if (!field) return;

    button.hidden = false;

    button.addEventListener('click', () => {

        const showing = field.type === 'password';

        field.type = showing ? 'text' : 'password';

        button.setAttribute(
            'aria-pressed',
            String(showing)
        );

        button.setAttribute(
            'aria-label',
            showing ? 'Hide password' : 'Show password'
        );

        if (!button.querySelector('svg')) {
            button.textContent = showing ? 'Hide' : 'Show';
        }
    });
});
