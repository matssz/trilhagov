import './bootstrap';
import 'bootstrap';
import {
    Building2,
    CalendarClock,
    CircleCheck,
    CircleDollarSign,
    Copy,
    Download,
    FileCheck2,
    FileText,
    History,
    LayoutDashboard,
    ListChecks,
    LogOut,
    Menu,
    Plus,
    RefreshCw,
    Search,
    TriangleAlert,
    Trash2,
    Upload,
    UserPlus,
    Users,
    createIcons,
} from 'lucide';

createIcons({
    icons: {
        Building2,
        CalendarClock,
        CircleCheck,
        CircleDollarSign,
        Copy,
        Download,
        FileCheck2,
        FileText,
        History,
        LayoutDashboard,
        ListChecks,
        LogOut,
        Menu,
        Plus,
        RefreshCw,
        Search,
        TriangleAlert,
        Trash2,
        Upload,
        UserPlus,
        Users,
    },
});

const receivedStatuses = [
    'resource_received',
    'executing',
    'accountability_pending',
    'completed',
];

function syncConditionalFields(form) {
    const status = form.querySelector('[name="status"]')?.value;
    const authorshipType = form.querySelector('[name="authorship_type"]')?.value;
    const governmentSphere = form.querySelector('[name="government_sphere"]')?.value;

    form.querySelectorAll('[data-required-for-status]').forEach((field) => {
        field.required = receivedStatuses.includes(status);
    });

    form.querySelectorAll('[data-required-when-completed]').forEach((field) => {
        field.required = status === 'completed';
    });

    form.querySelector('[name="author_party"]')?.toggleAttribute('required', authorshipType === 'individual');
    form.querySelector('[name="transferegov_code"]')?.toggleAttribute('required', governmentSphere === 'federal');
}

document.querySelectorAll('[data-amendment-form]').forEach((form) => {
    syncConditionalFields(form);
    form.addEventListener('change', () => syncConditionalFields(form));
});

const cnpjInput = document.querySelector('#cnpj');

cnpjInput?.addEventListener('input', () => {
    const digits = cnpjInput.value.replace(/\D/g, '').slice(0, 14);
    cnpjInput.value = digits
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
});

const ibgeInput = document.querySelector('#ibge_code');

ibgeInput?.addEventListener('input', () => {
    ibgeInput.value = ibgeInput.value.replace(/\D/g, '').slice(0, 7);
});

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.querySelector(button.dataset.copyTarget);

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        try {
            await navigator.clipboard.writeText(target.value);
        } catch {
            target.select();
            document.execCommand('copy');
        }
        const previousLabel = button.getAttribute('aria-label');
        button.setAttribute('aria-label', 'Link copiado');
        button.setAttribute('title', 'Link copiado');
        button.classList.add('copy-success');

        window.setTimeout(() => {
            button.setAttribute('aria-label', previousLabel ?? 'Copiar link');
            button.setAttribute('title', previousLabel ?? 'Copiar link');
            button.classList.remove('copy-success');
        }, 2000);
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() === 'get') {
        return;
    }

    if (form.dataset.submitting === 'true') {
        event.preventDefault();

        return;
    }

    form.dataset.submitting = 'true';

    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');

        if (button.hasAttribute('data-icon-submit')) {
            button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            button.setAttribute('aria-label', 'Atualizando sistema');
            button.setAttribute('title', 'Atualizando sistema');
        } else {
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processando...';
        }
    });
});
