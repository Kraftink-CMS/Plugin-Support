document.querySelectorAll('[data-support-apply-template]').forEach((button) =>
    button.addEventListener('click', () => {
        const form = button.closest('form');
        const field = form?.querySelector('textarea[name="body"]');
        const option = form?.querySelector('[data-support-template]')?.selectedOptions[0];
        if (!field || !option?.dataset.body) {
            return;
        }
        const editor = window.tinymce?.get(field.id);
        if ((editor?.getContent() || field.value).trim() && !window.confirm(button.dataset.confirmReplace)) {
            return;
        }
        if (editor) {
            editor.setContent(option.dataset.body);
        }
        field.value = option.dataset.body;
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }),
);

document.querySelectorAll('[data-support-draft]').forEach((form) => {
    const field = form.querySelector('textarea[name="body"]');
    const subject = form.querySelector('[name="subject"]');
    const internal = form.querySelector('[name="internal"]');
    const status = form.querySelector('[data-support-draft-status]');
    const discard = form.querySelector('[data-support-draft-discard]');
    const discardButton = form.querySelector('[data-support-draft-discard-button]');
    if ((!field && !subject) || !status) {
        return;
    }
    const fields = [...form.querySelectorAll('[data-support-draft-field]')];
    const read = () =>
        JSON.stringify({
            subject: subject?.value ?? '',
            body: field ? (window.tinymce?.get(field.id)?.getContent() ?? field.value) : '',
            internal: internal?.checked ?? false,
            custom_fields: Object.fromEntries(
                fields.map((customField) => [
                    customField.dataset.supportDraftField,
                    customField.type === 'checkbox' ? (customField.checked ? '1' : '0') : customField.value,
                ]),
            ),
        });
    const request = async (address, method, payload) => {
        const url = new URL(address, window.location.href);
        if (url.origin !== window.location.origin) {
            throw new Error('Invalid draft endpoint');
        }
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 20000);
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                },
                body: JSON.stringify(payload),
                signal: abort.signal,
            });
            const envelope = await response.json();
            if (!response.ok || envelope.success !== true) {
                throw new Error('Draft unavailable');
            }
        } finally {
            window.clearTimeout(timeout);
        }
    };
    let previous = read();
    let pending = null;
    let submitting = false;
    let discarding = false;
    const timer = window.setInterval(() => {
        if (pending || submitting || discarding || document.hidden) {
            return;
        }
        const current = read();
        if (current === previous) {
            return;
        }
        pending = (async () => {
            try {
                await request(form.dataset.supportDraftUrl, 'POST', {
                    context: form.dataset.supportDraft,
                    ...JSON.parse(current),
                });
                previous = current;
                if (discard) {
                    discard.hidden = false;
                }
                status.textContent = form.dataset.saved;
                status.className = 'small text-body-secondary';
            } catch {
                status.textContent = form.dataset.failed;
                status.className = 'small text-danger';
            } finally {
                pending = null;
            }
        })();
    }, 5000);
    discardButton?.addEventListener('click', async () => {
        if (submitting || discarding) {
            return;
        }
        discarding = true;
        discardButton.disabled = true;
        try {
            if (pending) {
                await pending;
            }
            await request(form.dataset.supportDiscardUrl, 'DELETE', {
                context: form.dataset.supportDraft,
            });
            if (subject) {
                subject.value = '';
            }
            if (field) {
                field.value = '';
                window.tinymce?.get(field.id)?.setContent('');
            }
            if (internal) {
                internal.checked = false;
            }
            for (const customField of fields) {
                if (customField.type === 'checkbox') {
                    customField.checked = false;
                } else {
                    customField.value = '';
                }
            }
            previous = read();
            discard.hidden = true;
            discard.open = false;
            status.textContent = form.dataset.discarded;
            status.className = 'small text-body-secondary';
        } catch {
            status.textContent = form.dataset.failed;
            status.className = 'small text-danger';
        } finally {
            discarding = false;
            discardButton.disabled = false;
        }
    });
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented || !form.checkValidity()) {
            return;
        }
        if (discarding) {
            event.preventDefault();
            return;
        }
        submitting = true;
        if (pending) {
            event.preventDefault();
            const submitter = event.submitter;
            pending.then(() => {
                submitting = false;
                form.requestSubmit(submitter ?? undefined);
            });
        } else {
            queueMicrotask(() => {
                if (event.defaultPrevented) {
                    submitting = form.getAttribute('aria-busy') === 'true';
                } else {
                    window.clearInterval(timer);
                }
            });
        }
    });
    form.addEventListener('ajax:success', () => window.clearInterval(timer));
    form.addEventListener('ajax:error', () => {
        submitting = false;
    });
    window.addEventListener('pagehide', () => window.clearInterval(timer), {
        once: true,
    });
});
