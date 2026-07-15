@props(['status', 'label'])

@php
    $class = match ($status) {
        'completed' => 'text-bg-success',
        'blocked' => 'text-bg-danger',
        'plan_pending', 'accountability_pending' => 'text-bg-warning',
        'executing', 'resource_received' => 'text-bg-primary',
        default => 'text-bg-secondary',
    };
@endphp

<span {{ $attributes->class(['badge', $class]) }}>{{ $label }}</span>
