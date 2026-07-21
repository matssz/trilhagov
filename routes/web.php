<?php

use App\Http\Controllers\AccountabilityController;
use App\Http\Controllers\AccountabilityDiligenceController;
use App\Http\Controllers\AccountabilityDossierController;
use App\Http\Controllers\AccountabilityRequirementController;
use App\Http\Controllers\AlertCenterController;
use App\Http\Controllers\AmendmentComplianceController;
use App\Http\Controllers\AmendmentDocumentController;
use App\Http\Controllers\AmendmentExecutionController;
use App\Http\Controllers\AmendmentRemappingController;
use App\Http\Controllers\AudespHomologationController;
use App\Http\Controllers\AudespRegistrationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\ExecutionStageController;
use App\Http\Controllers\ExternalIntegrationController;
use App\Http\Controllers\FinancialCommitmentController;
use App\Http\Controllers\FinancialLiquidationController;
use App\Http\Controllers\FinancialPaymentController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MunicipalAdmissibilityReviewController;
use App\Http\Controllers\MunicipalAuditPlanController;
use App\Http\Controllers\MunicipalAuditProgramController;
use App\Http\Controllers\MunicipalGovernanceReportController;
use App\Http\Controllers\MunicipalInternalControlController;
use App\Http\Controllers\MunicipalitySelectionController;
use App\Http\Controllers\MunicipalRegulatoryProfileController;
use App\Http\Controllers\MunicipalReportDispatchController;
use App\Http\Controllers\MunicipalUserController;
use App\Http\Controllers\MunicipalWorkPlanController;
use App\Http\Controllers\MunicipalWorkPlanPdfController;
use App\Http\Controllers\MunicipalWorkPlanStageController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\ParliamentaryAmendmentController;
use App\Http\Controllers\PublicTransparencyController;
use App\Http\Controllers\RefreshApplicationStateController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SpreadsheetImportController;
use App\Http\Controllers\TechnicalDiligenceController;
use App\Http\Controllers\TechnicalImpedimentController;
use App\Http\Controllers\TransparencySettingsController;
use App\Http\Controllers\WorkCenterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/convites/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('/convites/{token}', [InvitationAcceptanceController::class, 'accept'])->name('invitations.accept')->block(10, 10);
Route::get('/transparencia/{municipality:transparency_slug}', [PublicTransparencyController::class, 'show'])->name('transparency.show');
Route::get('/transparencia/{municipality:transparency_slug}/emendas.csv', [PublicTransparencyController::class, 'export'])->name('transparency.export');
Route::get('/transparencia/{municipality:transparency_slug}/emendas/{emenda}', [PublicTransparencyController::class, 'detail'])->name('transparency.detail');

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
    Route::get('/relatorios/emendas.csv', ReportExportController::class)->name('reports.export');
    Route::get('/relatorios/governanca', [MunicipalGovernanceReportController::class, 'index'])->name('governance-reports.index');
    Route::get('/relatorios/governanca/{report}', [MunicipalGovernanceReportController::class, 'show'])->name('governance-reports.show');
    Route::get('/relatorios/governanca/{report}/documento.pdf', [MunicipalGovernanceReportController::class, 'pdf'])->name('governance-reports.pdf');
    Route::get('/relatorios/governanca/{report}/dados.csv', [MunicipalGovernanceReportController::class, 'csv'])->name('governance-reports.csv');
    Route::get('/relatorios/governanca/{report}/remessas', [MunicipalReportDispatchController::class, 'index'])->name('report-dispatches.index');
    Route::get('/controle-interno/plano-anual', [MunicipalAuditPlanController::class, 'index'])->name('audit-plans.index');
    Route::get('/controle-interno/plano-anual/{plan}', [MunicipalAuditPlanController::class, 'show'])->name('audit-plans.show');
    Route::get('/controle-interno/plano-anual/{plan}/documento.pdf', [MunicipalAuditPlanController::class, 'pdf'])->name('audit-plans.pdf');
    Route::get('/controle-interno/programas/{program}', [MunicipalAuditProgramController::class, 'show'])->name('audit-programs.show');
    Route::get('/controle-interno/programas/{program}/documento.pdf', [MunicipalAuditProgramController::class, 'pdf'])->name('audit-programs.pdf');
    Route::get('/controle-interno/programas/evidencias/{evidence}', [MunicipalAuditProgramController::class, 'evidence'])->name('audit-program-evidences.download');
    Route::get('/relatorios/remessas/{dispatch}', [MunicipalReportDispatchController::class, 'show'])->name('report-dispatches.show');
    Route::get('/relatorios/remessas/{dispatch}/recibo.pdf', [MunicipalReportDispatchController::class, 'receipt'])->name('report-dispatches.receipt');
    Route::get('/relatorios/remessas/{dispatch}/eventos/{event}/comprovante', [MunicipalReportDispatchController::class, 'evidence'])->name('report-dispatches.evidence');
    Route::get('/integracoes', [ExternalIntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/trabalho', [WorkCenterController::class, 'index'])->name('work-center.index');
    Route::get('/emendas', [ParliamentaryAmendmentController::class, 'index'])->name('emendas.index');
    Route::get('/alertas', [AlertCenterController::class, 'index'])->name('alerts.index');
    Route::get('/audesp/homologacoes', [AudespHomologationController::class, 'index'])->name('audesp-homologations.index');
    Route::get('/audesp/homologacoes/{batch}', [AudespHomologationController::class, 'show'])->name('audesp-homologations.show');
    Route::get('/audesp/homologacoes/{batch}/arquivo', [AudespHomologationController::class, 'source'])->name('audesp-homologations.source');
    Route::get('/audesp/homologacoes/{batch}/conferencia.csv', [AudespHomologationController::class, 'report'])->name('audesp-homologations.report');
    Route::get('/audesp/homologacoes/{batch}/eventos/{event}/evidencia', [AudespHomologationController::class, 'evidence'])->name('audesp-homologations.evidence');
    Route::get('/configuracoes/normas-municipais', [MunicipalRegulatoryProfileController::class, 'index'])->name('municipal-rules.index');
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
    Route::get('/emendas/{emenda}/plano-de-trabalho', [MunicipalWorkPlanController::class, 'index'])->name('emendas.work-plan');
    Route::get('/emendas/{emenda}/plano-de-trabalho.pdf', MunicipalWorkPlanPdfController::class)->name('emendas.work-plan.pdf');
    Route::get('/emendas/{emenda}/impedimentos', [TechnicalImpedimentController::class, 'index'])->name('emendas.impediments');
    Route::get('/emendas/{emenda}/conformidade-tcesp', [AmendmentComplianceController::class, 'index'])->name('emendas.compliance');
    Route::get('/emendas/{emenda}/controle-interno', [MunicipalInternalControlController::class, 'index'])->name('emendas.internal-control');
    Route::get('/controle-interno/pareceres/{review}/documento.pdf', [MunicipalInternalControlController::class, 'pdf'])->name('internal-control-reviews.pdf');
    Route::get('/controle-interno/pareceres/{review}/evidencia', [MunicipalInternalControlController::class, 'reviewEvidence'])->name('internal-control-reviews.evidence');
    Route::get('/controle-interno/eventos/{event}/evidencia', [MunicipalInternalControlController::class, 'actionEvidence'])->name('internal-control-actions.evidence');
    Route::get('/emendas/{emenda}/execucao', AmendmentExecutionController::class)->name('emendas.execution');
    Route::get('/emendas/{emenda}/audesp', [AudespRegistrationController::class, 'index'])->name('emendas.audesp');
    Route::get('/emendas/{emenda}/audesp/diagnostico.csv', [AudespRegistrationController::class, 'diagnostic'])->name('emendas.audesp.diagnostic');
    Route::get('/emendas/{emenda}/prestacao-de-contas', [AccountabilityController::class, 'index'])->name('emendas.accountability');
    Route::get('/emendas/{emenda}/prestacao-de-contas/dossie.pdf', [AccountabilityDossierController::class, 'pdf'])->name('emendas.accountability.dossier.pdf');
    Route::get('/emendas/{emenda}/prestacao-de-contas/pacote.zip', [AccountabilityDossierController::class, 'package'])->name('emendas.accountability.dossier.package');
    Route::get('/emendas/{emenda}/documentos/{documento}/download', [AmendmentDocumentController::class, 'download'])->name('emendas.documents.download');

    Route::middleware('municipality.role:manager')->group(function () {
        Route::post('/relatorios/governanca/{report}/emitir', [MunicipalGovernanceReportController::class, 'issue'])->name('governance-reports.issue')->block(10, 10);
        Route::post('/relatorios/remessas/{dispatch}/cancelar', [MunicipalReportDispatchController::class, 'cancel'])->name('report-dispatches.cancel')->block(10, 10);
        Route::post('/emendas/{emenda}/plano-de-trabalho/parecer', [MunicipalAdmissibilityReviewController::class, 'store'])->name('emendas.work-plan.review')->block(10, 10);
        Route::patch('/transparencia/configuracao', TransparencySettingsController::class)->name('transparency.settings.update')->block(10, 10);
        Route::patch('/alertas/configuracoes', [AlertCenterController::class, 'updateSettings'])->name('alerts.settings.update')->block(10, 10);
        Route::post('/configuracoes/normas-municipais', [MunicipalRegulatoryProfileController::class, 'store'])->name('municipal-rules.store')->block(10, 10);
        Route::patch('/configuracoes/normas-municipais/{profile}', [MunicipalRegulatoryProfileController::class, 'update'])->name('municipal-rules.update')->block(10, 10);
        Route::post('/configuracoes/normas-municipais/{profile}/instrumentos', [MunicipalRegulatoryProfileController::class, 'addInstrument'])->name('municipal-rules.instruments.store')->block(10, 10);
        Route::delete('/configuracoes/normas-municipais/{profile}/instrumentos/{instrument}', [MunicipalRegulatoryProfileController::class, 'removeInstrument'])->name('municipal-rules.instruments.destroy')->block(10, 10);
        Route::post('/configuracoes/normas-municipais/{profile}/ativar', [MunicipalRegulatoryProfileController::class, 'activate'])->name('municipal-rules.activate')->block(10, 10);
        Route::post('/configuracoes/normas-municipais/{profile}/revisar', [MunicipalRegulatoryProfileController::class, 'revise'])->name('municipal-rules.revise')->block(10, 10);
        Route::get('/usuarios', [MunicipalUserController::class, 'index'])->name('users.index');
        Route::post('/usuarios/convites', [MunicipalUserController::class, 'invite'])->name('users.invitations.store')->block(10, 10);
        Route::delete('/usuarios/convites/{invitation}', [MunicipalUserController::class, 'revokeInvitation'])->name('users.invitations.destroy')->block(10, 10);
        Route::patch('/usuarios/{user}/perfil', [MunicipalUserController::class, 'updateRole'])->name('users.role.update')->block(10, 10);
        Route::get('/configuracoes/tipos-documento', [DocumentTypeController::class, 'index'])->name('document-types.index');
        Route::post('/configuracoes/tipos-documento', [DocumentTypeController::class, 'store'])->name('document-types.store')->block(10, 10);
        Route::patch('/configuracoes/tipos-documento/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update')->block(10, 10);
    });

    Route::middleware('municipality.role:manager,auditor')->group(function () {
        Route::post('/controle-interno/plano-anual', [MunicipalAuditPlanController::class, 'store'])->name('audit-plans.store')->block(10, 10);
        Route::patch('/controle-interno/plano-anual/{plan}', [MunicipalAuditPlanController::class, 'update'])->name('audit-plans.update')->block(10, 10);
        Route::post('/controle-interno/plano-anual/{plan}/itens', [MunicipalAuditPlanController::class, 'addItem'])->name('audit-plan-items.store')->block(10, 10);
        Route::patch('/controle-interno/plano-anual/itens/{item}', [MunicipalAuditPlanController::class, 'updateItem'])->name('audit-plan-items.update')->block(10, 10);
        Route::delete('/controle-interno/plano-anual/itens/{item}', [MunicipalAuditPlanController::class, 'removeItem'])->name('audit-plan-items.destroy')->block(10, 10);
        Route::post('/controle-interno/plano-anual/{plan}/emitir', [MunicipalAuditPlanController::class, 'issue'])->name('audit-plans.issue')->block(10, 10);
        Route::patch('/controle-interno/plano-anual/itens/{item}/andamento', [MunicipalAuditPlanController::class, 'progress'])->name('audit-plan-items.progress')->block(10, 10);
        Route::post('/controle-interno/plano-anual/itens/{item}/programa', [MunicipalAuditProgramController::class, 'store'])->name('audit-programs.store')->block(10, 10);
        Route::patch('/controle-interno/programas/{program}', [MunicipalAuditProgramController::class, 'update'])->name('audit-programs.update')->block(10, 10);
        Route::post('/controle-interno/programas/{program}/procedimentos', [MunicipalAuditProgramController::class, 'storeProcedure'])->name('audit-procedures.store')->block(10, 10);
        Route::patch('/controle-interno/programas/procedimentos/{procedure}', [MunicipalAuditProgramController::class, 'updateProcedure'])->name('audit-procedures.update')->block(10, 10);
        Route::delete('/controle-interno/programas/procedimentos/{procedure}', [MunicipalAuditProgramController::class, 'destroyProcedure'])->name('audit-procedures.destroy')->block(10, 10);
        Route::post('/controle-interno/programas/procedimentos/{procedure}/evidencias', [MunicipalAuditProgramController::class, 'storeEvidence'])->name('audit-program-evidences.store')->block(20, 10);
        Route::post('/controle-interno/programas/{program}/achados', [MunicipalAuditProgramController::class, 'storeFinding'])->name('audit-findings.store')->block(10, 10);
        Route::patch('/controle-interno/programas/achados/{finding}', [MunicipalAuditProgramController::class, 'updateFinding'])->name('audit-findings.update')->block(10, 10);
        Route::delete('/controle-interno/programas/achados/{finding}', [MunicipalAuditProgramController::class, 'destroyFinding'])->name('audit-findings.destroy')->block(10, 10);
        Route::post('/controle-interno/programas/{program}/revisao', [MunicipalAuditProgramController::class, 'submit'])->name('audit-programs.submit')->block(10, 10);
        Route::post('/controle-interno/programas/{program}/decisao', [MunicipalAuditProgramController::class, 'review'])->name('audit-programs.review')->block(10, 10);
        Route::post('/controle-interno/programas/{program}/concluir', [MunicipalAuditProgramController::class, 'conclude'])->name('audit-programs.conclude')->block(10, 10);
        Route::post('/emendas/{emenda}/controle-interno/pareceres', [MunicipalInternalControlController::class, 'store'])->name('internal-control-reviews.store')->block(20, 10);
        Route::post('/controle-interno/providencias/{action}/decidir', [MunicipalInternalControlController::class, 'decide'])->name('internal-control-actions.decide')->block(20, 10);
    });

    Route::middleware('municipality.role:manager,editor')->group(function () {
        Route::post('/controle-interno/providencias/{action}/responder', [MunicipalInternalControlController::class, 'respond'])->name('internal-control-actions.respond')->block(20, 10);
        Route::post('/relatorios/governanca', [MunicipalGovernanceReportController::class, 'store'])->name('governance-reports.store')->block(10, 10);
        Route::patch('/relatorios/governanca/{report}/atualizar', [MunicipalGovernanceReportController::class, 'refresh'])->name('governance-reports.refresh')->block(10, 10);
        Route::post('/relatorios/governanca/{report}/remessas', [MunicipalReportDispatchController::class, 'store'])->name('report-dispatches.store')->block(10, 10);
        Route::post('/relatorios/remessas/{dispatch}/enviar', [MunicipalReportDispatchController::class, 'send'])->name('report-dispatches.send')->block(20, 10);
        Route::post('/relatorios/remessas/{dispatch}/retorno', [MunicipalReportDispatchController::class, 'recordReturn'])->name('report-dispatches.return')->block(20, 10);
        Route::post('/audesp/homologacoes', [AudespHomologationController::class, 'store'])->name('audesp-homologations.store')->block(20, 10);
        Route::post('/audesp/homologacoes/{batch}/reconferir', [AudespHomologationController::class, 'recheck'])->name('audesp-homologations.recheck')->block(20, 10);
        Route::post('/audesp/homologacoes/{batch}/transmissao', [AudespHomologationController::class, 'recordSubmission'])->name('audesp-homologations.submission')->block(20, 10);
        Route::post('/audesp/homologacoes/{batch}/retorno', [AudespHomologationController::class, 'recordReturn'])->name('audesp-homologations.return')->block(20, 10);
        Route::get('/importacoes/planilhas', [SpreadsheetImportController::class, 'index'])->name('spreadsheet-imports.index');
        Route::get('/importacoes/planilhas/modelo.csv', [SpreadsheetImportController::class, 'template'])->name('spreadsheet-imports.template');
        Route::post('/importacoes/planilhas/pre-visualizar', [SpreadsheetImportController::class, 'preview'])->name('spreadsheet-imports.preview')->block(20, 20);
        Route::get('/importacoes/planilhas/{batch}', [SpreadsheetImportController::class, 'show'])->name('spreadsheet-imports.show');
        Route::post('/importacoes/planilhas/{batch}/confirmar', [SpreadsheetImportController::class, 'confirm'])->name('spreadsheet-imports.confirm')->block(20, 20);
        Route::post('/integracoes/transferegov/sincronizar', [ExternalIntegrationController::class, 'sync'])->name('integrations.sync')->block(20, 20);
        Route::post('/trabalho/atualizar', [WorkCenterController::class, 'synchronize'])->name('work-center.sync')->block(20, 20);
        Route::patch('/trabalho/acoes/{item}', [WorkCenterController::class, 'update'])->name('work-center.items.update')->block(10, 10);
        Route::post('/integracoes/candidatos/{candidate}/conciliar-financeiro', [ExternalIntegrationController::class, 'reconcileFinancial'])->name('integrations.candidates.financial')->block(20, 20);
        Route::patch('/integracoes/candidatos/{candidate}/vincular', [ExternalIntegrationController::class, 'link'])->name('integrations.candidates.link')->block(10, 10);
        Route::patch('/integracoes/candidatos/{candidate}/aplicar', [ExternalIntegrationController::class, 'apply'])->name('integrations.candidates.apply')->block(10, 10);
        Route::post('/integracoes/candidatos/{candidate}/importar', [ExternalIntegrationController::class, 'import'])->name('integrations.candidates.import')->block(10, 10);
        Route::patch('/integracoes/candidatos/{candidate}/ignorar', [ExternalIntegrationController::class, 'ignore'])->name('integrations.candidates.ignore')->block(10, 10);
        Route::get('/emendas/{emenda}/edit', [ParliamentaryAmendmentController::class, 'edit'])->name('emendas.edit');
        Route::match(['put', 'patch'], '/emendas/{emenda}', [ParliamentaryAmendmentController::class, 'update'])->name('emendas.update')->block(10, 10);
        Route::post('/emendas/{emenda}/documentos', [AmendmentDocumentController::class, 'store'])->name('emendas.documents.store')->block(10, 10);
        Route::post('/emendas/{emenda}/plano-de-trabalho', [MunicipalWorkPlanController::class, 'store'])->name('emendas.work-plan.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/plano-de-trabalho', [MunicipalWorkPlanController::class, 'update'])->name('emendas.work-plan.update')->block(10, 10);
        Route::post('/emendas/{emenda}/plano-de-trabalho/enviar', [MunicipalWorkPlanController::class, 'submit'])->name('emendas.work-plan.submit')->block(10, 10);
        Route::post('/emendas/{emenda}/plano-de-trabalho/etapas', [MunicipalWorkPlanStageController::class, 'store'])->name('emendas.work-plan.stages.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/plano-de-trabalho/etapas/{etapa}', [MunicipalWorkPlanStageController::class, 'update'])->name('emendas.work-plan.stages.update')->block(10, 10);
        Route::delete('/emendas/{emenda}/plano-de-trabalho/etapas/{etapa}', [MunicipalWorkPlanStageController::class, 'destroy'])->name('emendas.work-plan.stages.destroy')->block(10, 10);
        Route::post('/emendas/{emenda}/impedimentos', [TechnicalImpedimentController::class, 'store'])->name('emendas.impediments.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/impedimentos/{impedimento}', [TechnicalImpedimentController::class, 'update'])->name('emendas.impediments.update')->block(10, 10);
        Route::post('/emendas/{emenda}/impedimentos/{impedimento}/diligencias', [TechnicalDiligenceController::class, 'store'])->name('emendas.impediments.diligences.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/impedimentos/{impedimento}/diligencias/{diligencia}', [TechnicalDiligenceController::class, 'update'])->name('emendas.impediments.diligences.update')->block(10, 10);
        Route::post('/emendas/{emenda}/impedimentos/{impedimento}/remanejamentos', [AmendmentRemappingController::class, 'store'])->name('emendas.impediments.remappings.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/impedimentos/{impedimento}/remanejamentos/{remanejamento}', [AmendmentRemappingController::class, 'update'])->name('emendas.impediments.remappings.update')->block(10, 10);
        Route::post('/emendas/{emenda}/impedimentos/{impedimento}/remanejamentos/{remanejamento}/enviar', [AmendmentRemappingController::class, 'submit'])->name('emendas.impediments.remappings.submit')->block(10, 10);
        Route::patch('/emendas/{emenda}/conformidade-tcesp/{regra}', [AmendmentComplianceController::class, 'update'])->name('emendas.compliance.update')->block(10, 10);
        Route::post('/emendas/{emenda}/etapas', [ExecutionStageController::class, 'store'])->name('emendas.stages.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/etapas/{etapa}', [ExecutionStageController::class, 'update'])->name('emendas.stages.update')->block(10, 10);
        Route::post('/emendas/{emenda}/empenhos', [FinancialCommitmentController::class, 'store'])->name('emendas.commitments.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/empenhos/{empenho}/cancelar', [FinancialCommitmentController::class, 'cancel'])->name('emendas.commitments.cancel')->block(10, 10);
        Route::post('/emendas/{emenda}/empenhos/{empenho}/liquidacoes', [FinancialLiquidationController::class, 'store'])->name('emendas.liquidations.store')->block(10, 10);
        Route::post('/emendas/{emenda}/empenhos/{empenho}/pagamentos', [FinancialPaymentController::class, 'store'])->name('emendas.payments.store')->block(10, 10);
        Route::put('/emendas/{emenda}/audesp', [AudespRegistrationController::class, 'update'])->name('emendas.audesp.update')->block(10, 10);
        Route::post('/emendas/{emenda}/audesp/previa', [AudespRegistrationController::class, 'preview'])->name('emendas.audesp.preview')->block(10, 10);
        Route::post('/emendas/{emenda}/prestacao-de-contas', [AccountabilityController::class, 'store'])->name('emendas.accountability.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/prestacao-de-contas', [AccountabilityController::class, 'update'])->name('emendas.accountability.update')->block(10, 10);
        Route::post('/emendas/{emenda}/prestacao-de-contas/requisitos', [AccountabilityRequirementController::class, 'store'])->name('emendas.accountability.requirements.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/prestacao-de-contas/requisitos/{requisito}', [AccountabilityRequirementController::class, 'update'])->name('emendas.accountability.requirements.update')->block(10, 10);
        Route::post('/emendas/{emenda}/prestacao-de-contas/diligencias', [AccountabilityDiligenceController::class, 'store'])->name('emendas.accountability.diligences.store')->block(10, 10);
        Route::patch('/emendas/{emenda}/prestacao-de-contas/diligencias/{diligencia}', [AccountabilityDiligenceController::class, 'update'])->name('emendas.accountability.diligences.update')->block(10, 10);
    });

    Route::middleware('municipality.role:manager')->group(function () {
        Route::patch('/emendas/{emenda}/impedimentos/{impedimento}/remanejamentos/{remanejamento}/decidir', [AmendmentRemappingController::class, 'decide'])->name('emendas.impediments.remappings.decide')->block(10, 10);
    });
});
