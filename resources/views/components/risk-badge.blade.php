@props(['level', 'label', 'score' => null])

@php
    $class = match ($level) {
        'critical' => 'risk-critical',
        'high' => 'risk-high',
        'moderate' => 'risk-moderate',
        default => 'risk-low',
    };
@endphp

<span {{ $attributes->class(['risk-badge', $class]) }}>
    <i data-lucide="gauge" aria-hidden="true"></i>
    {{ $label }}@if ($score !== null) · {{ $score }}@endif
</span>
