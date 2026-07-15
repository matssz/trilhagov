import './bootstrap';
import 'bootstrap';
import {
    Building2,
    CalendarClock,
    CircleCheck,
    CircleDollarSign,
    FileText,
    LayoutDashboard,
    LogOut,
    Menu,
    Plus,
    Search,
    TriangleAlert,
    createIcons,
} from 'lucide';

createIcons({
    icons: {
        Building2,
        CalendarClock,
        CircleCheck,
        CircleDollarSign,
        FileText,
        LayoutDashboard,
        LogOut,
        Menu,
        Plus,
        Search,
        TriangleAlert,
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
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processando...';
    });
});
