<?php

namespace App\Services;

class MailDeliveryStatus
{
    /** @return array{mailer: string, from: ?string, status: string, label: string, message: string, icon: string} */
    public function summary(): array
    {
        $mailer = (string) config('mail.default', 'log');
        $from = trim((string) config('mail.from.address', ''));
        $isSimulation = in_array($mailer, ['log', 'array'], true);
        $isReady = ! $isSimulation && $from !== '';

        if ($isReady) {
            return [
                'mailer' => $mailer,
                'from' => $from,
                'status' => 'ready',
                'label' => 'E-mail real ativo',
                'message' => "Avisos externos podem ser enviados pelo canal {$mailer} usando {$from}.",
                'icon' => 'mail-check',
            ];
        }

        if ($isSimulation) {
            return [
                'mailer' => $mailer,
                'from' => $from !== '' ? $from : null,
                'status' => 'simulation',
                'label' => 'Ambiente em modo teste/log',
                'message' => 'Os avisos ficam registrados no sistema, mas nenhum e-mail real sai para vereadores, gestores ou auditores.',
                'icon' => 'mail-warning',
            ];
        }

        return [
            'mailer' => $mailer,
            'from' => null,
            'status' => 'attention',
            'label' => 'E-mail precisa de remetente',
            'message' => 'Configure o remetente institucional para liberar envio externo com rastreabilidade.',
            'icon' => 'mail-x',
        ];
    }
}
