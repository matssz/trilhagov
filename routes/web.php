<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\MunicipalitySelectionController;
use App\Http\Controllers\MunicipalUserController;
use App\Http\Controllers\ParliamentaryAmendmentController;
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
});

Route::middleware(['auth', 'municipality'])->group(function () {
    Route::get('/painel', DashboardController::class)->name('dashboard');
    Route::get('/emendas', [ParliamentaryAmendmentController::class, 'index'])->name('emendas.index');

    Route::middleware('municipality.role:manager,editor')->group(function () {
        Route::get('/emendas/create', [ParliamentaryAmendmentController::class, 'create'])->name('emendas.create');
        Route::post('/emendas', [ParliamentaryAmendmentController::class, 'store'])->name('emendas.store')->block(10, 10);
    });

    Route::get('/emendas/{emenda}', [ParliamentaryAmendmentController::class, 'show'])->name('emendas.show');

    Route::middleware('municipality.role:manager')->group(function () {
        Route::get('/usuarios', [MunicipalUserController::class, 'index'])->name('users.index');
        Route::post('/usuarios/convites', [MunicipalUserController::class, 'invite'])->name('users.invitations.store')->block(10, 10);
        Route::delete('/usuarios/convites/{invitation}', [MunicipalUserController::class, 'revokeInvitation'])->name('users.invitations.destroy')->block(10, 10);
        Route::patch('/usuarios/{user}/perfil', [MunicipalUserController::class, 'updateRole'])->name('users.role.update')->block(10, 10);
    });

    Route::middleware('municipality.role:manager,editor')->group(function () {
        Route::get('/emendas/{emenda}/edit', [ParliamentaryAmendmentController::class, 'edit'])->name('emendas.edit');
        Route::match(['put', 'patch'], '/emendas/{emenda}', [ParliamentaryAmendmentController::class, 'update'])->name('emendas.update')->block(10, 10);
    });
});
