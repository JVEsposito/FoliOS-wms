@props([
    'id',
    'label' => 'Secciones disponibles',
    'panels' => [],
    'default' => null,
])

@php
    $defaultPanel = $default ?: array_key_first($panels);
@endphp

<nav
    {{ $attributes->class(['office-panel-switcher']) }}
    data-office-panel-switcher="{{ $id }}"
    data-default-panel="{{ $defaultPanel }}"
    role="tablist"
    aria-label="{{ $label }}"
>
    @foreach ($panels as $panelId => $panel)
        @php
            $isSelected = $panelId === $defaultPanel;
        @endphp
        <button
            class="office-panel-tab{{ $isSelected ? ' is-active' : '' }}"
            id="{{ $id }}-tab-{{ $panelId }}"
            type="button"
            role="tab"
            aria-selected="{{ $isSelected ? 'true' : 'false' }}"
            aria-controls="{{ $id }}-panel-{{ $panelId }}"
            tabindex="{{ $isSelected ? '0' : '-1' }}"
            data-office-panel-target="{{ $panelId }}"
        >
            @if (!empty($panel['icon']))
                <span class="office-panel-tab__icon" aria-hidden="true">{{ $panel['icon'] }}</span>
            @endif
            <span>{{ $panel['label'] }}</span>
            @if (!empty($panel['badge_id']))
                <strong class="office-panel-tab__badge" id="{{ $panel['badge_id'] }}" @if (!empty($panel['badge_hidden'])) hidden @endif>{{ $panel['badge'] ?? 0 }}</strong>
            @endif
        </button>
    @endforeach
</nav>
