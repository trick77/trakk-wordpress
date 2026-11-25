// "Test connection": asks WordPress to ask Trakk with what the form holds now.
(() => {
    const button = document.getElementById('trakk-test');
    const result = document.getElementById('trakk-test-result');
    if (!button || !result) {
        return;
    }

    const show = (lines) => {
        result.replaceChildren(...lines.map(({ ok, text }) => {
            const box = document.createElement('div');
            const p = document.createElement('p');
            box.className = `notice inline ${ok ? 'notice-success' : 'notice-error'}`;
            p.textContent = text;
            box.append(p);
            return box;
        }));
    };

    button.addEventListener('click', async () => {
        const body = new FormData(button.form);
        // The form's own are for options.php.
        for (const k of ['option_page', '_wpnonce', '_wp_http_referer']) {
            body.delete(k);
        }
        body.set('action', button.dataset.action);
        body.set('nonce', button.dataset.nonce);
        button.disabled = true;
        result.textContent = 'Asking Trakk …';
        try {
            const answer = await (await fetch(window.ajaxurl, { method: 'POST', body })).json();
            show(answer.data);
        } catch {
            show([{ ok: false, text: 'WordPress did not answer. Reload the page and try again.' }]);
        }
        button.disabled = false;
    });
})();
