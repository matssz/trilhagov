<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FormSubmission
{
    public function issue(Request $request, string $scope): string
    {
        $token = (string) Str::uuid();
        $tokens = $request->session()->get('form_submission_tokens', []);
        $tokens[$scope][$token] = now()->timestamp;

        foreach ($tokens as $tokenScope => $scopeTokens) {
            $tokens[$tokenScope] = array_filter(
                $scopeTokens,
                fn (int $issuedAt) => $issuedAt >= now()->subHours(2)->timestamp,
            );
        }

        $request->session()->put('form_submission_tokens', $tokens);

        return $token;
    }

    public function consume(Request $request, string $scope): bool
    {
        $token = (string) $request->input('_submission_token');
        $tokens = $request->session()->get('form_submission_tokens', []);

        if ($token === '' || ! isset($tokens[$scope][$token])) {
            return false;
        }

        unset($tokens[$scope][$token]);
        $request->session()->put('form_submission_tokens', $tokens);

        return true;
    }
}
