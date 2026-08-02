<?php

namespace App\Http\Controllers;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityPrivacyController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var Municipality $municipality */
        $municipality = $request->attributes->get('active_municipality');
        $users = $municipality->users()->get();
        $invitations = $municipality->invitations()->latest()->get();

        $checks = [
            [
                'status' => 'ok',
                'title' => 'Banco protegido pelo backend',
                'description' => 'A conexao com Supabase/Postgres fica no Laravel. O navegador nao recebe senha de banco nem chave administrativa.',
            ],
            [
                'status' => 'ok',
                'title' => 'Dados escopados por municipio',
                'description' => 'Rotas autenticadas exigem municipio ativo e papel permitido antes de abrir informacoes operacionais.',
            ],
            [
                'status' => 'ok',
                'title' => 'Convites nao usam token salvo em texto puro',
                'description' => 'O convite publico valida o token pelo hash SHA-256 e bloqueia tentativas repetidas.',
            ],
            [
                'status' => $municipality->transparency_enabled ? 'attention' : 'ok',
                'title' => 'Portal publico de transparencia',
                'description' => $municipality->transparency_enabled
                    ? 'O portal publico esta ativo. Revise se somente dados publicaveis aparecem para consulta externa.'
                    : 'O portal publico esta desligado. Dados externos nao ficam expostos por transparencia.',
            ],
            [
                'status' => config('app.debug') ? 'critical' : 'ok',
                'title' => 'Modo debug em producao',
                'description' => config('app.debug')
                    ? 'APP_DEBUG esta ativo. Em producao, desligue para nao revelar stack trace ou detalhes internos.'
                    : 'APP_DEBUG esta desligado, evitando exposicao de erros tecnicos para usuarios.',
            ],
            [
                'status' => 'planned',
                'title' => 'MFA e politica formal de retencao',
                'description' => 'Proximo endurecimento: dois fatores para gestor, politica de retencao/descarte e registro de incidente LGPD.',
            ],
        ];

        return view('security-privacy.index', [
            'municipality' => $municipality,
            'checks' => $checks,
            'roleCounts' => $users->countBy(fn (User $user) => $user->pivot->role)->all(),
            'invitations' => [
                'pending' => $invitations->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
                'accepted' => $invitations->whereNotNull('accepted_at')->count(),
                'expired' => $invitations->whereNull('accepted_at')->where('expires_at', '<=', now())->count(),
            ],
        ]);
    }
}
