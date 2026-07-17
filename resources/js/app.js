import './bootstrap';
import 'bootstrap';
import {
    ArrowLeft,
    BadgeCheck,
    Bell,
    BellRing,
    Building2,
    Ban,
    BriefcaseBusiness,
    CalendarClock,
    ChevronDown,
    ChevronRight,
    CircleCheck,
    CircleDot,
    CircleDollarSign,
    CircleX,
    CircleMinus,
    Check,
    CheckCheck,
    Copy,
    ClipboardCheck,
    ClipboardList,
    Download,
    FileCheck2,
    FileDown,
    FileText,
    FileWarning,
    Gauge,
    History,
    LayoutDashboard,
    Landmark,
    ListChecks,
    ListFilter,
    LogOut,
    Menu,
    Plus,
    Package,
    PackageCheck,
    Paperclip,
    Pencil,
    RefreshCw,
    ReceiptText,
    Scale,
    Search,
    ShieldAlert,
    Smartphone,
    Send,
    TriangleAlert,
    Trash2,
    Upload,
    UserPlus,
    UserRoundCheck,
    Users,
    WalletCards,
    Webhook,
    ExternalLink,
    DatabaseZap,
    EyeOff,
    FilterX,
    FileInput,
    FileSpreadsheet,
    GitCompareArrows,
    Globe2,
    Lightbulb,
    Link2,
    MessageSquare,
    Sheet,
    ShieldCheck,
    ScanSearch,
    Sparkles,
    createIcons,
} from 'lucide';

createIcons({
    icons: {
        ArrowLeft,
        BadgeCheck,
        Bell,
        BellRing,
        Building2,
        Ban,
        BriefcaseBusiness,
        CalendarClock,
        ChevronDown,
        ChevronRight,
        CircleCheck,
        CircleDot,
        CircleDollarSign,
        CircleX,
        CircleMinus,
        Check,
        CheckCheck,
        Copy,
        ClipboardCheck,
        ClipboardList,
        Download,
        FileCheck2,
        FileDown,
        FileText,
        FileWarning,
        Gauge,
        History,
        LayoutDashboard,
        Landmark,
        ListChecks,
        ListFilter,
        LogOut,
        Menu,
        Plus,
        Package,
        PackageCheck,
        Paperclip,
        Pencil,
        RefreshCw,
        ReceiptText,
        Scale,
        Search,
        ShieldAlert,
        Smartphone,
        Send,
        TriangleAlert,
        Trash2,
        Upload,
        UserPlus,
        UserRoundCheck,
        Users,
        WalletCards,
        Webhook,
        ExternalLink,
        DatabaseZap,
        EyeOff,
        FilterX,
        FileInput,
        FileSpreadsheet,
        GitCompareArrows,
        Globe2,
        Lightbulb,
        Link2,
        MessageSquare,
        Sheet,
        ShieldCheck,
        ScanSearch,
        Sparkles,
    },
});

const chartColors = {
    navy: '#123f70',
    blue: '#3976a8',
    green: '#17845b',
    gold: '#d2a62b',
    red: '#c43d3d',
    teal: '#1f7f88',
    gray: '#9aa7b8',
};

const compactCurrency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    notation: 'compact',
    maximumFractionDigits: 1,
});

const analyticsCanvases = document.querySelectorAll('[data-analytics-chart]');

async function renderAnalyticsCharts() {
    if (analyticsCanvases.length === 0) {
        return;
    }

    const { default: Chart } = await import('chart.js/auto');

    analyticsCanvases.forEach((canvas) => {
    const labels = JSON.parse(canvas.dataset.labels ?? '[]');
    const values = JSON.parse(canvas.dataset.values ?? '[]').map(Number);
    const type = canvas.dataset.analyticsChart;

    if (values.length === 0 || values.every((value) => value === 0)) {
        canvas.hidden = true;
        const empty = document.createElement('div');
        empty.className = 'chart-empty';
        empty.textContent = 'Sem dados para o recorte selecionado.';
        canvas.parentElement?.appendChild(empty);

        return;
    }

    const isCurrency = ['financial', 'departments', 'authors'].includes(type);
    const isHorizontal = ['status', 'departments', 'authors'].includes(type);
    const isDonut = type === 'risk';
    const colors = type === 'risk'
        ? [chartColors.green, chartColors.gold, '#e47b36', chartColors.red]
        : type === 'financial'
            ? [chartColors.navy, chartColors.blue, chartColors.gold, chartColors.green]
            : [chartColors.navy, chartColors.teal, chartColors.gold, chartColors.green, chartColors.blue, chartColors.red];

        new Chart(canvas, {
        type: isDonut ? 'doughnut' : 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderColor: isDonut ? '#ffffff' : colors,
                borderWidth: isDonut ? 3 : 0,
                borderRadius: isDonut ? 0 : 5,
                barThickness: type === 'financial' ? 34 : undefined,
            }],
        },
        options: {
            indexAxis: isHorizontal ? 'y' : 'x',
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 450 },
            cutout: isDonut ? '70%' : undefined,
            plugins: {
                legend: {
                    display: isDonut,
                    position: 'bottom',
                    labels: { usePointStyle: true, boxWidth: 8, padding: 16, color: '#536178', font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: (context) => isCurrency
                            ? ` ${compactCurrency.format(Number(context.raw))}`
                            : ` ${context.raw} emenda(s)`,
                    },
                },
            },
            scales: isDonut ? undefined : {
                x: {
                    grid: { display: !isHorizontal, color: '#edf1f6' },
                    border: { display: false },
                    ticks: {
                        color: '#6a7688',
                        font: { size: 11 },
                        callback: isHorizontal
                            ? (value) => isCurrency ? compactCurrency.format(value) : value
                            : (_value, index) => labels[index],
                    },
                },
                y: {
                    beginAtZero: true,
                    grid: { display: isHorizontal, color: '#edf1f6' },
                    border: { display: false },
                    ticks: {
                        color: '#536178',
                        font: { size: 11, weight: 600 },
                        callback: isHorizontal
                            ? (_value, index) => labels[index]
                            : (value) => isCurrency ? compactCurrency.format(value) : value,
                    },
                },
            },
        },
        });
    });
}

renderAnalyticsCharts();

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
