<?php

use App\Http\Controllers\AccountabilityController;
use App\Http\Controllers\AccountabilityDiligenceController;
use App\Http\Controllers\AccountabilityDossierController;
use App\Http\Controllers\AccountabilityRequirementController;
use App\Http\Controllers\AlertCenterController;
use App\Http\Controllers\AmendmentDocumentController;
use App\Http\Controllers\AmendmentExecutionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\ExecutionStageController;
use App\Http\Controllers\FinancialCommitmentController;
use App\Http\Controllers\FinancialPaymentController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MunicipalitySelectionController;
use App\Http\Controllers\MunicipalUserController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\ParliamentaryAmendmentController;
use App\Http\Controllers\RefreshApplicationStateController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/convites/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('/convites/{token}', [InvitationAcceptanceController::class, 'accept'])->name('invitations.accept')->block(10, 10);

Route::middleware('guest')->group(function () {
    Route::get('/cadastro', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/cadastro', [RegisteredUserController::class, 'store'])->block(10, 10);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->block(10, 10);
});

Route::middleware('auth')->group(function () {
    Route::get('/municipios/selecionar', [MunicipalitySelectionController::class, 'index'])->name('municipalities.select');
    Route::post('/municipios/selecionar', [MunicipalitySelectionController::class, 'store'])->name('municipalities.activate')->block(10, 10);
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout')->block(10, 10);
    Route::post('/sistema/atualizar', RefreshApplicationStateController::class)->name('application.refresh')->block(10, 10);
});

Route::middleware(['auth', 'municipality'])->group(function () {
    Route::get('/painel', DashboardController::class)->name('dashboard');
    Route::get('/emendas', [ParliamentaryAmendmentController::class, 'index'])->name('emendas.index');
    Route::get('/alertas', [AlertCenterController::class, 'index'])->name('alerts.index');
    Route::get('/notificacoes', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::patch('/notificacoes/preferencias', [NotificationCenterController::class, 'updatePreferences'])->name('notifications.preferences.update')->block(10, 10);
    Route::patch('/notificacoes/{notification}/ler', [NotificationCenterController::class, 'markAsRead'])->name('notifications.read')->block(10, 10);
    Route::post('/notificacoes/ler-todas', [NotificationCenterController::class, 'markAllAsRead'])->name('notifications.read-all')->block(10, 10);

    Route::middleware('municipality.role:manager,editor')->group(function () {
        Route::post('/alertas/verificar', [AlertCenterController::class, 'process'])->name('alerts.process')->block(10, 10);
        Route::get('/emendas/create', [ParliamentaryAmendmentController::class, 'create'])->name('emendas.create');
        Route::post('/emendas', [ParliamentaryAmendmentController::class, 'store'])->name('emendas.store')->block(10, 10);
    });

    Route::get('/emendas/{emenda}', [ParliamentaryAmendmentController::class, 'show'])->name('emendas.show');
    Route::get('/emendas/{emenda}/execucao', AmendmentExecutionController::class)->name('emendas.execution');
    Route::get('/emendas/{emenda}/prestacao-de-contas', [AccountabilityController::class, 'index'])->name('emendas.accountability');
    Route::get('/emendas/{emenda}/prestacao-de-contas/dossie.pdf', [AccountabilityDossierController::class, 'pdf'])->name('emendas.accountability.dossier.pdf');
    Route::get('/emendas/{emenda}/prestacao-de-contas/pacote.zip', [AccountabilityDossierController::class, 'package'])->name('emendas.accountability.dossier.package');
    Route::get('/emendas/{emenda}/documentos/{documento}/download', [AmendmentDocumentController::class, 'download'])->name('emendas.documents.download');

    Route::middleware('municipality.role:manager')->group(function () {
        Route::patch('/alertas/configuracoes', [AlertCenterController::class, 'updateSettings'])->name('alerts.settings.update')->block(10, 10);
        Route::get('/usuarios', [MunicipalUserController::class, 'index'])->name('users.index');
        Route::post('/usuarios/convites', [MunicipalUserController::class, 'invite'])->name('users.invitations.store')->block(10, 10);
        Route::delete('/usuarios/convites/{invitation}', [MunicipalUserController::class, 'revokeInvitation'])->name('users.invitations.destroy')->block(10, 10);
        Route::patch('/usuarios/{user}/perfil', [MunicipalUserController::class, 'updateRole'])->name('users.role.update')->block(10, 10);
        Route::get('/configuracoes/tipos-documento', [DocumentTypeController::class, 'index'])->name('document-types.index');
        Route::post('/configuracoes/tipos-documento', [DocumentTypeController::class, 'store'])->name('document-types.store')->block(10, 10);
        Route::patch('/configuracoes/tipos-documento/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update')->block(10, 10);
    });

    Route::middleware('municipality.role:manager,editor')->group(function () {
        Route::get('/emendas/{emenda}/edit', [ParliamentaryAmendmentController::class, 'edit'])->name('emendas.edit');
        Route::match(['put', 'patch'], '/emendas/{emenda}', [ParliamentaryAmendmentController::class, 'update'])->name('emendas.update')->block(10, 10);
        Route::post('/emendas/{emenda}/documentos', [AmendmentDocumentController::class, 'store'])->name('emendas.documents.store')->block(10, 10);
        Route::post('/emendas/{emenda}/etapas', [ExecutionStageController::class, 'store'])->name('emendas.stages.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/etapas/{etapa}', [ExecutionStageController::class, 'update'])->name('emendas.stages.update')->block(10, 10);
        Route::post('/emendas/{emenda}/empenhos', [FinancialCommitmentController::class, 'store'])->name('emendas.commitments.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/empenhos/{empenho}/cancelar', [FinancialCommitmentController::class, 'cancel'])->name('emendas.commitments.cancel')->block(10, 10);
        Route::post('/emendas/{emenda}/empenhos/{empenho}/pagamentos', [FinancialPaymentController::class, 'store'])->name('emendas.payments.store')->block(10, 10);
        Route::post('/emendas/{emenda}/prestacao-de-contas', [AccountabilityController::class, 'store'])->name('emendas.accountability.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/prestacao-de-contas', [AccountabilityController::class, 'update'])->name('emendas.accountability.update')->block(10, 10);
        Route::post('/emendas/{emenda}/prestacao-de-contas/requisitos', [AccountabilityRequirementController::class, 'store'])->name('emendas.accountability.requirements.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/prestacao-de-contas/requisitos/{requisito}', [AccountabilityRequirementController::class, 'update'])->name('emendas.accountability.requirements.update')->block(10, 10);
        Route::post('/emendas/{emenda}/prestacao-de-contas/diligencias', [AccountabilityDiligenceController::class, 'store'])->name('emendas.accountability.diligences.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/prestacao-de-contas/diligencias/{diligencia}', [AccountabilityDiligenceController::class, 'update'])->name('emendas.accountability.diligences.update')->block(10, 10);
    });
});
