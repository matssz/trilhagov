import './bootstrap';
import 'bootstrap';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import {
    Activity,
    AlertCircle,
    ArrowDown,
    ArrowLeft,
    ArrowDownCircle,
    ArrowRight,
    ArrowUpRight,
    BadgeCheck,
    Bell,
    BellRing,
    Building2,
    Ban,
    BriefcaseBusiness,
    Bug,
    CalendarClock,
    CalendarCheck2,
    CalendarPlus,
    Calculator,
    Circle,
    ChevronDown,
    ChevronRight,
    CircleCheck,
    CircleCheckBig,
    CircleAlert,
    CircleDot,
    CircleDollarSign,
    CircleX,
    CircleMinus,
    CircleDashed,
    Check,
    CheckCheck,
    Copy,
    CopyPlus,
    CopyX,
    ClipboardCheck,
    ClipboardList,
    ClipboardPen,
    ClipboardPlus,
    Crosshair,
    Construction,
    Database,
    Download,
    Edit3,
    Eye,
    FilePenLine,
    FileCheck2,
    FileClock,
    FileDown,
    FileText,
    FileWarning,
    Fingerprint,
    FileChartColumn,
    Gauge,
    GraduationCap,
    Flag,
    HandHeart,
    HeartPulse,
    HardHat,
    History,
    Info,
    AlarmClock,
    LayoutDashboard,
    Landmark,
    LoaderCircle,
    LogIn,
    ListChecks,
    ListFilter,
    ListPlus,
    Lock,
    LockKeyhole,
    LogOut,
    MailCheck,
    MapPinCheck,
    Menu,
    Moon,
    MousePointerClick,
    Plus,
    Package,
    PackageCheck,
    Paperclip,
    Pencil,
    Play,
    PlayCircle,
    PencilLine,
    RefreshCw,
    Radar,
    Radio,
    Route,
    Rocket,
    Rows3,
    Save,
    ReceiptText,
    Scale,
    ScrollText,
    Search,
    SearchCheck,
    SearchX,
    Settings2,
    ShieldAlert,
    SlidersHorizontal,
    ShieldOff,
    Smartphone,
    Stamp,
    Send,
    TriangleAlert,
    Trash2,
    TimerReset,
    Upload,
    Undo2,
    UserPlus,
    UserCheck,
    UserMinus,
    UserRound,
    UserRoundCheck,
    Users,
    UsersRound,
    Wallet,
    WalletCards,
    Waypoints,
    Webhook,
    X,
    ExternalLink,
    DatabaseZap,
    EyeOff,
    FilterX,
    FileInput,
    FilePlus2,
    FileSpreadsheet,
    GitCompareArrows,
    Globe2,
    Lightbulb,
    Clock3,
    Link2,
    MessageSquare,
    Sheet,
    ShieldCheck,
    ScanSearch,
    Sparkles,
    Archive,
    WandSparkles,
    Zap,
    createIcons,
} from 'lucide';

gsap.registerPlugin(ScrollTrigger);

createIcons({
    icons: {
        Activity,
        AlertCircle,
        ArrowDown,
        ArrowLeft,
        ArrowDownCircle,
        ArrowRight,
        ArrowUpRight,
        ArchiveCheck: Archive,
        BadgeCheck,
        BadgeUser: UserRound,
        Bell,
        BellRing,
        Building2,
        Ban,
        BriefcaseBusiness,
        Bug,
        CalendarClock,
        CalendarCheck2,
        CalendarPlus,
        Calculator,
        Circle,
        ChevronDown,
        ChevronRight,
        CircleCheck,
        CircleCheckBig,
        CircleAlert,
        CircleDot,
        CircleDollarSign,
        CircleX,
        CircleMinus,
        CircleDashed,
        Check,
        CheckCheck,
        Copy,
        CopyPlus,
        CopyX,
        ClipboardCheck,
        ClipboardList,
        ClipboardPen,
        ClipboardPlus,
        Crosshair,
        Construction,
        Database,
        DatabaseLock: Database,
        Download,
        Edit3,
        Eye,
        FilePenLine,
        FileCheck2,
        FileClock,
        FileDown,
        FileText,
        FileWarning,
        Fingerprint,
        FileChartColumn,
        Gauge,
        GraduationCap,
        Flag,
        HandHeart,
        HeartPulse,
        HardHat,
        History,
        Info,
        AlarmClock,
        LayoutDashboard,
        Landmark,
        LoaderCircle,
        LogIn,
        ListChecks,
        ListFilter,
        ListPlus,
        Lock,
        LockKeyhole,
        LogOut,
        MailCheck,
        MapPinCheck,
        Menu,
        Moon,
        MousePointerClick,
        Plus,
        Package,
        PackageCheck,
        Paperclip,
        Pencil,
        Play,
        PlayCircle,
        PencilLine,
        RefreshCw,
        Radar,
        Radio,
        Route,
        Rocket,
        Rows3,
        Save,
        ReceiptText,
        Scale,
        ScrollText,
        Search,
        SearchCheck,
        SearchX,
        Settings2,
        ShieldAlert,
        SlidersHorizontal,
        ShieldOff,
        Smartphone,
        Stamp,
        Send,
        TriangleAlert,
        Trash2,
        TimerReset,
        Upload,
        Undo2,
        UserPlus,
        UserCheck,
        UserMinus,
        UserRound,
        UserRoundCheck,
        Users,
        UsersRound,
        Wallet,
        WalletCards,
        Waypoints,
        Webhook,
        X,
        ExternalLink,
        DatabaseZap,
        EyeOff,
        FilterX,
        FileInput,
        FilePlus2,
        FileSpreadsheet,
        GitCompareArrows,
        Globe2,
        Lightbulb,
        Clock3,
        Link2,
        MessageSquare,
        Sheet,
        ShieldCheck,
        ScanSearch,
        Sparkles,
        Archive,
        WandSparkles,
        Zap,
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

const routeProgress = document.querySelector('[data-route-progress]');

function startRouteProgress() {
    routeProgress?.classList.add('is-loading');
}

function stopRouteProgress() {
    routeProgress?.classList.remove('is-loading');
}

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (!(link instanceof HTMLAnchorElement)
        || event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
        || link.target === '_blank'
        || link.hasAttribute('download')
        || link.origin !== window.location.origin
        || link.hash && link.pathname === window.location.pathname) {
        return;
    }

    startRouteProgress();
});

window.addEventListener('pageshow', stopRouteProgress);

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

const officialAmendment = document.querySelector('[data-official-amendment]');
const officialSources = document.querySelectorAll('[data-official-source]');

function syncOfficialSources() {
    if (!(officialAmendment instanceof HTMLSelectElement)) {
        return;
    }

    officialSources.forEach((source) => {
        if (!(source instanceof HTMLSelectElement)) {
            return;
        }

        Array.from(source.options).forEach((option) => {
            if (option.value === '') {
                return;
            }
            const visible = officialAmendment.value !== '' && option.dataset.amendmentId === officialAmendment.value;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) {
                source.value = '';
            }
        });
    });
}

officialAmendment?.addEventListener('change', syncOfficialSources);
syncOfficialSources();

const normalizeInstitutionText = (value) => String(value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

const fillFieldIfBlank = (field, value) => {
    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) || !value) {
        return false;
    }

    if (field.value.trim() !== '') {
        return false;
    }

    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));

    return true;
};

const formatInstitutionLocation = (option) => {
    const address = option.dataset.address?.trim();
    const city = option.dataset.city?.trim();
    const state = option.dataset.state?.trim();

    if (address && city && state) {
        return `${address} - ${city}/${state}`;
    }

    if (address) {
        return address;
    }

    if (city && state) {
        return `${city}/${state}`;
    }

    return city || '';
};

const institutionalHealthTerms = [
    'saude',
    'ubs',
    'unidade basica',
    'posto de saude',
    'vacina',
    'vacinacao',
    'medicamento',
    'hospital',
    'ambulatorio',
    'enfermagem',
    'odontologico',
    'atencao basica',
];

const looksLikeHealthInstitution = (option) => {
    const text = normalizeInstitutionText([
        option.value,
        option.label,
        option.dataset.department,
        option.dataset.address,
    ].filter(Boolean).join(' '));

    return institutionalHealthTerms.some((term) => text.includes(normalizeInstitutionText(term)));
};

document.querySelectorAll('[data-institution-source]').forEach((source) => {
    if (!(source instanceof HTMLInputElement)) {
        return;
    }

    const listId = source.dataset.institutionSource;
    const list = listId ? document.getElementById(listId) : null;
    const form = source.closest('form');

    if (!(list instanceof HTMLDataListElement) || !(form instanceof HTMLFormElement)) {
        return;
    }

    const findOption = () => Array.from(list.options).find((option) => option.value === source.value.trim());
    const applyInstitution = () => {
        const option = findOption();

        if (!option) {
            return;
        }

        fillFieldIfBlank(form.querySelector('[data-institution-party-target]'), option.dataset.party);
        fillFieldIfBlank(form.querySelector('[data-institution-document-target]'), option.dataset.document);
        fillFieldIfBlank(form.querySelector('[data-institution-location-target]'), formatInstitutionLocation(option));

        const departmentTarget = form.querySelector('[name="responsible_department"]');
        if (source.name !== 'responsible_department') {
            fillFieldIfBlank(departmentTarget, option.dataset.department);
        }

        const healthTarget = form.querySelector('input[name="health_related"]');
        if (healthTarget instanceof HTMLInputElement && looksLikeHealthInstitution(option) && !healthTarget.checked) {
            healthTarget.checked = true;
            healthTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    source.addEventListener('change', applyInstitution);
    source.addEventListener('blur', applyInstitution);
});

const legislativeAutomation = document.querySelector('[data-legislative-automation]');
const legislativeProjection = document.querySelector('[data-legislative-projection]');

if (legislativeAutomation instanceof HTMLElement && legislativeProjection instanceof HTMLElement) {
    const form = legislativeProjection.nextElementSibling instanceof HTMLFormElement
        ? legislativeProjection.nextElementSibling
        : document.querySelector('.legislative-simple-form')?.closest('form');
    const amountInput = form?.querySelector('input[name="estimated_amount"]');
    const healthInput = form?.querySelector('input[name="health_related"]');
    const objectInput = form?.querySelector('[name="object"]');
    const beneficiaryInput = form?.querySelector('[name="beneficiary_name"]');
    const departmentInput = form?.querySelector('[name="responsible_department"]');
    const locationInput = form?.querySelector('[name="beneficiary_location"]');
    const justificationInput = form?.querySelector('[name="justification"]');
    const estimateSourceInput = form?.querySelector('[name="estimate_source"]');
    const expenseDestinationInput = form?.querySelector('[name="expense_destination"]');
    const publicNeedInput = form?.querySelector('[name="public_need"]');
    const targetPopulationInput = form?.querySelector('[name="target_population"]');
    const estimatedQuantityInput = form?.querySelector('[name="estimated_quantity"]');
    const templatePanel = document.querySelector('[data-legislative-templates]');
    const readinessPanel = document.querySelector('[data-legislative-readiness]');
    const readinessMessage = readinessPanel?.querySelector('[data-readiness-message]');
    const readinessItems = readinessPanel?.querySelectorAll('[data-readiness-item]');
    const remainingTarget = legislativeProjection.querySelector('[data-projection-remaining]');
    const messageTarget = legislativeProjection.querySelector('[data-projection-message]');
    const fillAvailable = legislativeProjection.querySelector('[data-fill-available]');
    const fillHealth = legislativeProjection.querySelector('[data-fill-health]');
    const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const parseNumber = (value) => {
        if (value === undefined || value === null || value === '') {
            return null;
        }

        const number = Number(String(value).replace(',', '.'));

        return Number.isFinite(number) ? number : null;
    };
    const remaining = parseNumber(legislativeAutomation.dataset.remaining);
    const minimum = parseNumber(legislativeAutomation.dataset.minimum) ?? 0.01;
    const healthGap = parseNumber(legislativeAutomation.dataset.healthGap) ?? 0;
    const healthTerms = [
        'saude',
        'saúde',
        'ubs',
        'unidade basica',
        'unidade básica',
        'posto de saude',
        'posto de saúde',
        'vacina',
        'vacinacao',
        'vacinação',
        'medicamento',
        'hospital',
        'ambulatorio',
        'ambulatório',
        'enfermagem',
        'odontologico',
        'odontológico',
        'atencao basica',
        'atenção básica',
    ];

    const normalizeText = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

    const fieldValue = (field) => field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement
        ? field.value.trim()
        : '';

    const setIfBlank = (field, value) => {
        if ((field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) && field.value.trim() === '') {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };

    const setSelectIfBlank = (field, value) => {
        if (field instanceof HTMLSelectElement && field.value === '') {
            field.value = value;
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const setTemplateValue = (field, value) => {
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };

    const setTemplateSelectValue = (field, value) => {
        if (field instanceof HTMLSelectElement) {
            field.value = value;
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const templates = {
        'health-equipment': {
            object: 'Aquisição de equipamentos para melhorar o atendimento da unidade municipal de saúde.',
            beneficiary: 'Unidade Municipal de Saúde',
            department: 'Secretaria Municipal de Saúde',
            justification: 'A proposta busca melhorar o atendimento da população, reduzir filas e substituir equipamentos insuficientes ou defasados na rede municipal de saúde.',
            publicNeed: 'Ampliar a capacidade de atendimento da rede municipal de saúde e qualificar o serviço prestado aos moradores.',
            targetPopulation: 'Usuários do SUS atendidos pela unidade municipal',
            quantity: 'Equipamentos permanentes conforme levantamento da secretaria',
            expenseDestination: 'investment',
            health: true,
        },
        'school-equipment': {
            object: 'Aquisição de mobiliário e equipamentos para unidade escolar municipal.',
            beneficiary: 'Escola Municipal',
            department: 'Secretaria Municipal de Educação',
            justification: 'A proposta busca melhorar as condições de ensino, aprendizagem e atendimento dos alunos da rede municipal.',
            publicNeed: 'Modernizar a estrutura da unidade escolar e apoiar atividades pedagógicas essenciais.',
            targetPopulation: 'Alunos, professores e comunidade escolar',
            quantity: 'Mobiliário e equipamentos conforme levantamento da escola',
            expenseDestination: 'investment',
            health: false,
        },
        'street-work': {
            object: 'Execução de obra de infraestrutura urbana para melhoria de via pública municipal.',
            beneficiary: 'Bairro indicado pela comunidade',
            department: 'Secretaria Municipal de Obras',
            justification: 'A proposta busca melhorar a mobilidade, a segurança e o acesso dos moradores aos serviços públicos municipais.',
            publicNeed: 'Atender demanda local por infraestrutura urbana e reduzir problemas de circulação ou acesso no bairro.',
            targetPopulation: 'Moradores e usuários da via pública',
            quantity: 'Trecho ou etapa a definir pela secretaria técnica',
            expenseDestination: 'investment',
            health: false,
        },
    };

    const applyTemplate = (template) => {
        setTemplateValue(objectInput, template.object);
        setTemplateValue(beneficiaryInput, template.beneficiary);
        setTemplateValue(departmentInput, template.department);
        setTemplateValue(justificationInput, template.justification);
        setTemplateValue(publicNeedInput, template.publicNeed);
        setTemplateValue(targetPopulationInput, template.targetPopulation);
        setTemplateValue(estimatedQuantityInput, template.quantity);
        setTemplateValue(estimateSourceInput, 'Estimativa declarada pelo vereador');
        setTemplateSelectValue(expenseDestinationInput, template.expenseDestination);

        if (healthInput instanceof HTMLInputElement) {
            healthInput.checked = Boolean(template.health);
            healthInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        syncAssistedFields();
        updateProjection();
    };

    const detectsHealth = () => {
        const text = normalizeText(`${fieldValue(objectInput)} ${fieldValue(beneficiaryInput)} ${fieldValue(departmentInput)}`);

        return healthTerms.some((term) => text.includes(normalizeText(term)));
    };

    const syncAssistedFields = () => {
        const healthDetected = detectsHealth();

        if (healthDetected && healthInput instanceof HTMLInputElement && !healthInput.checked) {
            healthInput.checked = true;
            healthInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (healthDetected) {
            setIfBlank(departmentInput, 'Secretaria Municipal de Saude');
        }

        if (locationInput instanceof HTMLInputElement && locationInput.value.trim() === '') {
            locationInput.value = locationInput.dataset.defaultLocation ?? '';
        }

        setIfBlank(estimateSourceInput, 'Estimativa declarada pelo vereador');
    };

    const readinessState = () => {
        const amount = parseNumber(amountInput instanceof HTMLInputElement ? amountInput.value : null) ?? 0;
        const after = remaining === null ? null : remaining - amount;

        return {
            object: fieldValue(objectInput).length >= 20,
            amount: amount >= minimum && (after === null || after >= -0.005),
            beneficiary: fieldValue(beneficiaryInput).length >= 3,
            justification: fieldValue(justificationInput).length >= 30,
        };
    };

    const updateReadiness = () => {
        if (!(readinessPanel instanceof HTMLElement) || !readinessItems || !readinessMessage) {
            return;
        }

        const state = readinessState();
        const readyCount = Object.values(state).filter(Boolean).length;
        const allReady = readyCount === Object.keys(state).length;

        readinessPanel.classList.toggle('is-ready', allReady);
        readinessPanel.classList.toggle('is-partial', !allReady && readyCount > 0);
        readinessMessage.textContent = allReady
            ? 'Pronto para salvar como rascunho. Depois, revise e envie para conferencia legislativa.'
            : `${readyCount} de ${Object.keys(state).length} pontos prontos. Complete os itens pendentes para evitar devolucao.`;

        readinessItems.forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const key = item.dataset.readinessItem;
            item.classList.toggle('is-ok', Boolean(key && state[key]));
        });
    };

    const updateProjection = () => {
        if (!(amountInput instanceof HTMLInputElement) || !remainingTarget || !messageTarget) {
            return;
        }

        const amount = parseNumber(amountInput.value) ?? 0;
        const after = remaining === null ? null : remaining - amount;
        const healthChecked = healthInput instanceof HTMLInputElement && healthInput.checked;

        if (after !== null) {
            remainingTarget.textContent = `${money.format(Math.max(0, after))} sobrara na cota`;
        } else {
            remainingTarget.textContent = 'Saldo a configurar';
        }

        legislativeProjection.classList.toggle('is-danger', after !== null && after < -0.005);
        legislativeProjection.classList.toggle('is-warning', after !== null && after >= -0.005 && amount > 0 && amount < minimum);
        legislativeProjection.classList.toggle('is-health', healthChecked);

        if (after !== null && after < -0.005) {
            messageTarget.textContent = `O valor ultrapassa o saldo em ${money.format(Math.abs(after))}. Reduza antes de salvar.`;
        } else if (amount > 0 && amount < minimum) {
            messageTarget.textContent = `A norma municipal exige valor minimo de ${money.format(minimum)}.`;
        } else if (healthGap > 0 && !healthChecked) {
            messageTarget.textContent = `Ainda faltam ${money.format(healthGap)} para a reserva de saude. Marque saude se esta proposta atender essa area.`;
        } else if (healthChecked) {
            messageTarget.textContent = 'Esta proposta entra automaticamente no controle da reserva de saude.';
        } else if (after !== null && amount > 0 && Math.abs(after) <= 0.005) {
            messageTarget.textContent = 'Esta proposta usa todo o saldo disponivel da cota. O valor fica comprometido nesta indicacao ate a analise e reserva do Executivo.';
        } else {
            messageTarget.textContent = 'Valor dentro da cota. Ao salvar, essa quantia fica comprometida nesta proposta e o restante segue disponivel para novas indicacoes.';
        }

        updateReadiness();
    };

    fillAvailable?.addEventListener('click', () => {
        if (amountInput instanceof HTMLInputElement && remaining !== null) {
            amountInput.value = Math.max(0, remaining).toFixed(2);
            amountInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    fillHealth?.addEventListener('click', () => {
        const healthAmount = parseNumber(fillHealth.dataset.healthAmount);
        if (amountInput instanceof HTMLInputElement && healthAmount !== null) {
            amountInput.value = healthAmount.toFixed(2);
        }
        if (healthInput instanceof HTMLInputElement) {
            healthInput.checked = true;
        }
        updateProjection();
    });

    templatePanel?.querySelectorAll('[data-template]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!(button instanceof HTMLElement)) {
                return;
            }

            const template = templates[button.dataset.template];
            if (template) {
                applyTemplate(template);
                templatePanel.querySelectorAll('[data-template]').forEach((item) => {
                    item.classList.toggle('is-selected', item === button);
                    item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
                });
            }
        });
    });

    amountInput?.addEventListener('input', updateProjection);
    healthInput?.addEventListener('change', updateProjection);
    [objectInput, beneficiaryInput, departmentInput, locationInput, justificationInput, estimateSourceInput].forEach((field) => {
        field?.addEventListener('input', () => {
            syncAssistedFields();
            updateProjection();
        });
        field?.addEventListener('blur', () => {
            syncAssistedFields();
            updateProjection();
        });
    });
    syncAssistedFields();
    updateProjection();
}

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

const initProgressiveMotion = () => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduceMotion) {
        return;
    }

    const selectors = [
        '.content-panel',
        '.metric-card',
        '.dashboard-card',
        '.municipal-command-panel',
        '.import-command-panel',
        '.defense-command-panel',
        '.profile-trails-panel',
        '.municipal-parameters-panel',
        '.municipal-health-grid article',
        '.profile-trail-grid article',
        '.parameter-module-grid article',
        '.executive-command-panel',
        '.executive-command-cards article',
        '.executive-flow-lane li',
        '.executive-next-decisions a',
        '.legislative-board-column',
        '.councilor-mandate-panel',
        '.councilor-simple-checklist',
        '.accountability-guide',
        '.accountability-command-card',
        '.accountability-action-card',
    ];

    const targets = Array.from(document.querySelectorAll(selectors.join(',')))
        .filter((element) => element instanceof HTMLElement && element.dataset.motionReveal !== 'ready');

    targets.forEach((element, index) => {
        element.dataset.motionReveal = 'ready';
        element.classList.add('motion-reveal', `motion-delay-${index % 6}`);
    });

    gsap.set(targets, {
        autoAlpha: 0,
        y: 42,
        scale: 0.965,
        filter: 'blur(5px) saturate(0.9)',
    });

    ScrollTrigger.batch(targets, {
        start: 'top 88%',
        once: true,
        onEnter: (batch) => {
            batch.forEach((element) => element.classList.add('motion-visible'));
            gsap.to(batch, {
                autoAlpha: 1,
                y: 0,
                scale: 1,
                filter: 'blur(0px) saturate(1)',
                duration: 0.82,
                stagger: 0.075,
                ease: 'power3.out',
                overwrite: true,
            });
        },
    });
};

const initCommercialNavScroll = (nav) => {
    const threshold = 24;
    let ticking = false;
    let isScrolled = false;

    const applyState = () => {
        const shouldBeScrolled = window.scrollY > threshold;
        if (shouldBeScrolled !== isScrolled) {
            isScrolled = shouldBeScrolled;
            nav.classList.toggle('is-scrolled', isScrolled);
        }
        ticking = false;
    };

    applyState();
    window.addEventListener('scroll', () => {
        if (!ticking) {
            requestAnimationFrame(applyState);
            ticking = true;
        }
    }, { passive: true });
};

const initCommercialShowcase = () => {
    const page = document.querySelector('.commercial-page');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!(page instanceof HTMLElement)) {
        return;
    }

    const nav = page.querySelector('[data-commercial-nav]');
    if (nav instanceof HTMLElement) {
        initCommercialNavScroll(nav);
    }

    if (reduceMotion) {
        return;
    }

    gsap.timeline({ defaults: { ease: 'power3.out' } })
        .from('.commercial-nav-brand, .commercial-nav-links a, .commercial-nav-cta', {
            autoAlpha: 0,
            y: -18,
            stagger: 0.05,
            duration: 0.62,
        })
        .from('.commercial-hero-copy > *', {
            autoAlpha: 0,
            y: 36,
            stagger: 0.095,
            duration: 0.78,
        }, '-=.25')
        .from('.commercial-product-stage', {
            autoAlpha: 0,
            y: 32,
            scale: 0.96,
            duration: 0.9,
        }, '-=.52')
        .from('.commercial-stage-stats article, .commercial-stage-pills span', {
            autoAlpha: 0,
            y: 14,
            stagger: 0.045,
            duration: 0.5,
        }, '-=.45');

    gsap.to('.commercial-product-stage', {
        yPercent: -4,
        ease: 'none',
        force3D: true,
        scrollTrigger: {
            trigger: '.commercial-hero',
            start: 'top top',
            end: 'bottom top',
            scrub: true,
        },
    });

    gsap.utils.toArray('.commercial-pipeline-track article').forEach((card, index) => {
        gsap.fromTo(card, {
            y: 54,
            autoAlpha: 0,
            scale: 0.92,
        }, {
            y: 0,
            autoAlpha: 1,
            scale: 1,
            duration: 0.72,
            ease: 'back.out(1.4)',
            scrollTrigger: {
                trigger: card,
                start: 'top 86%',
                toggleActions: 'play none none reverse',
            },
            delay: (index % 3) * 0.06,
        });
    });

    gsap.utils.toArray('.commercial-showcase-grid article').forEach((card, index) => {
        gsap.fromTo(card, {
            y: 36,
            autoAlpha: 0,
        }, {
            y: 0,
            autoAlpha: 1,
            duration: 0.65,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: card,
                start: 'top 88%',
                toggleActions: 'play none none reverse',
            },
            delay: (index % 3) * 0.06,
        });
    });

    const mapCard = document.querySelector('[data-sp-map]');
    if (mapCard instanceof HTMLElement) {
        gsap.utils.toArray('.sp-map-points circle').forEach((point, index) => {
            gsap.to(point, {
                scale: 1.7,
                transformOrigin: '50% 50%',
                opacity: 0.6,
                duration: 1.05 + (index % 3) * 0.16,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: index * 0.08,
                force3D: true,
            });
        });

        gsap.to('.sp-map-route', {
            strokeDashoffset: -240,
            duration: 6.5,
            repeat: -1,
            ease: 'none',
        });

        gsap.to('.sp-map-shape', {
            scale: 1.008,
            transformOrigin: '50% 50%',
            duration: 5,
            repeat: -1,
            yoyo: true,
            ease: 'sine.inOut',
            force3D: true,
        });
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initProgressiveMotion();
        initCommercialShowcase();
    }, { once: true });
} else {
    initProgressiveMotion();
    initCommercialShowcase();
}
