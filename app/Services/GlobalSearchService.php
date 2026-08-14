<?php

namespace App\Services;

use App\Models\AmendmentDocument;
use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\MunicipalOfficialDocument;
use App\Models\ParliamentaryAmendment;
use App\Models\SupportOccurrence;
use App\Models\User;
use Illuminate\Support\Str;

class GlobalSearchService
{
    /** @return array{query: string, total: int, groups: array<string, array{label: string, icon: string, results: array<int, array<string, mixed>>}>} */
    public function search(Municipality $municipality, User $user, ?string $term): array
    {
        $query = trim((string) $term);

        if (mb_strlen($query) < 2) {
            return [
                'query' => $query,
                'total' => 0,
                'groups' => $this->emptyGroups($this->roleFor($municipality, $user)),
            ];
        }

        $role = $this->roleFor($municipality, $user);
        $groups = [];

        if (in_array($role, [User::ROLE_MANAGER, User::ROLE_EDITOR, User::ROLE_VIEWER, User::ROLE_AUDITOR], true)) {
            $groups['amendments'] = [
                'label' => 'Emendas',
                'icon' => 'file-text',
                'results' => $this->amendments($municipality, $query),
            ];
            $groups['documents'] = [
                'label' => 'Documentos',
                'icon' => 'paperclip',
                'results' => $this->documents($municipality, $query),
            ];
            $groups['official_documents'] = [
                'label' => 'Comunicações oficiais',
                'icon' => 'send',
                'results' => $this->officialDocuments($municipality, $query),
            ];
        }

        if (in_array($role, [User::ROLE_MANAGER, User::ROLE_EDITOR, User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER], true)) {
            $groups['legislative'] = [
                'label' => 'Portal Legislativo',
                'icon' => 'landmark',
                'results' => $this->legislativeProposals($municipality, $user, $role, $query),
            ];
        }

        if ($role === User::ROLE_MANAGER) {
            $groups['occurrences'] = [
                'label' => 'Ocorrências',
                'icon' => 'bug',
                'results' => $this->occurrences($municipality, $query),
            ];
        }

        $total = collect($groups)->sum(fn (array $group): int => count($group['results']));

        return compact('query', 'total', 'groups');
    }

    /** @return array<string, array{label: string, icon: string, results: array<int, array<string, mixed>>}> */
    private function emptyGroups(?string $role): array
    {
        if (in_array($role, [User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER], true)) {
            return ['legislative' => ['label' => 'Portal Legislativo', 'icon' => 'landmark', 'results' => []]];
        }

        return [
            'amendments' => ['label' => 'Emendas', 'icon' => 'file-text', 'results' => []],
            'documents' => ['label' => 'Documentos', 'icon' => 'paperclip', 'results' => []],
            'official_documents' => ['label' => 'Comunicações oficiais', 'icon' => 'send', 'results' => []],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function amendments(Municipality $municipality, string $query): array
    {
        return $municipality->amendments()
            ->with('responsibleUser:id,name')
            ->where(function ($builder) use ($query) {
                $like = $this->like($query);
                $operator = $this->operator();
                $builder->where('reference', $operator, $like)
                    ->orWhere('author_name', $operator, $like)
                    ->orWhere('author_party', $operator, $like)
                    ->orWhere('object', $operator, $like)
                    ->orWhere('responsible_department', $operator, $like)
                    ->orWhere('beneficiary_location', $operator, $like)
                    ->orWhere('administrative_process', $operator, $like)
                    ->orWhere('transferegov_code', $operator, $like);
            })
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (ParliamentaryAmendment $amendment) => [
                'title' => $amendment->reference.' · '.$amendment->object,
                'subtitle' => $amendment->author_name.' / '.$amendment->responsible_department,
                'meta' => $amendment->statusLabel().' · R$ '.number_format((float) $amendment->expected_amount, 2, ',', '.'),
                'url' => route('emendas.show', $amendment),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function legislativeProposals(Municipality $municipality, User $user, ?string $role, string $query): array
    {
        return $municipality->legislativeProposals()
            ->when($role === User::ROLE_COUNCILOR, fn ($builder) => $builder->where('submitted_by', $user->id))
            ->where(function ($builder) use ($query) {
                $like = $this->like($query);
                $operator = $this->operator();
                $builder->where('reference', $operator, $like)
                    ->orWhere('author_name', $operator, $like)
                    ->orWhere('author_party', $operator, $like)
                    ->orWhere('object', $operator, $like)
                    ->orWhere('beneficiary_name', $operator, $like)
                    ->orWhere('beneficiary_location', $operator, $like)
                    ->orWhere('responsible_department', $operator, $like)
                    ->orWhere('protocol_number', $operator, $like)
                    ->orWhere('executive_process_number', $operator, $like)
                    ->orWhere('budget_reservation_number', $operator, $like);
            })
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (LegislativeProposal $proposal) => [
                'title' => $proposal->reference.' · '.$proposal->object,
                'subtitle' => $proposal->author_name.' / '.$proposal->beneficiary_name,
                'meta' => $proposal->statusLabel().' · R$ '.number_format((float) $proposal->estimated_amount, 2, ',', '.'),
                'url' => route('legislative.show', $proposal),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function documents(Municipality $municipality, string $query): array
    {
        return AmendmentDocument::query()
            ->with('amendment:id,reference,object')
            ->where('municipality_id', $municipality->id)
            ->where(function ($builder) use ($query) {
                $like = $this->like($query);
                $operator = $this->operator();
                $builder->where('original_name', $operator, $like)
                    ->orWhere('notes', $operator, $like)
                    ->orWhereHas('amendment', fn ($amendment) => $amendment
                        ->where('reference', $operator, $like)
                        ->orWhere('object', $operator, $like));
            })
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (AmendmentDocument $document) => [
                'title' => $document->original_name,
                'subtitle' => $document->amendment?->reference.' · '.$document->amendment?->object,
                'meta' => 'Versão '.$document->version.' · '.$document->formattedSize(),
                'url' => route('emendas.show', $document->amendment),
            ])
            ->filter(fn (array $result): bool => filled($result['url']))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function officialDocuments(Municipality $municipality, string $query): array
    {
        return $municipality->officialDocuments()
            ->where(function ($builder) use ($query) {
                $like = $this->like($query);
                $operator = $this->operator();
                $builder->where('reference', $operator, $like)
                    ->orWhere('official_number', $operator, $like)
                    ->orWhere('protocol_number', $operator, $like)
                    ->orWhere('recipient_name', $operator, $like)
                    ->orWhere('subject', $operator, $like);
            })
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (MunicipalOfficialDocument $document) => [
                'title' => ($document->official_number ?: $document->reference).' · '.$document->subject,
                'subtitle' => $document->recipient_name ?: $document->recipient_entity,
                'meta' => $document->typeLabel().' · '.$document->statusLabel(),
                'url' => route('official-documents.show', $document),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function occurrences(Municipality $municipality, string $query): array
    {
        return SupportOccurrence::query()
            ->where(function ($builder) use ($municipality) {
                $builder->where('municipality_id', $municipality->id)->orWhereNull('municipality_id');
            })
            ->where(function ($builder) use ($query) {
                $like = $this->like($query);
                $operator = $this->operator();
                $builder->where('title', $operator, $like)
                    ->orWhere('message', $operator, $like)
                    ->orWhere('route_name', $operator, $like)
                    ->orWhere('url', $operator, $like);
            })
            ->latest('last_seen_at')
            ->limit(8)
            ->get()
            ->map(fn (SupportOccurrence $occurrence) => [
                'title' => $occurrence->title,
                'subtitle' => Str::limit($occurrence->message, 120),
                'meta' => $occurrence->sourceLabel().' · '.$occurrence->statusLabel(),
                'url' => route('occurrences.index', ['source' => $occurrence->source, 'level' => $occurrence->level]),
            ])
            ->all();
    }

    private function like(string $query): string
    {
        return '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';
    }

    private function operator(): string
    {
        return config('database.default') === 'pgsql' ? 'ilike' : 'like';
    }

    private function roleFor(Municipality $municipality, User $user): ?string
    {
        return $municipality->pivot?->role ?? $user->roleForMunicipality($municipality->id);
    }
}
