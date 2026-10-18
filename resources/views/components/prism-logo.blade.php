@props(['variant' => 'compact', 'tone' => 'default'])
{{--
    variant: primary (with descriptor), compact (header), stacked, symbol (mark only)
    tone: default (blue mark, light text), ink (dark, for white backgrounds), white (for dark backgrounds)
    The mark is hidden from assistive tech when visible PRISM text sits beside it.
--}}
<span {{ $attributes->class(['prism-logo', 'prism-logo--'.$variant, 'prism-logo--'.$tone => $tone !== 'default']) }}>
    @if ($variant === 'symbol')
        <x-prism-mark title="PRISM" />
    @else
        <x-prism-mark />
        <span class="prism-logo__text">
            <span class="prism-logo__word">PRISM</span>
            @if ($variant === 'primary')
                <span class="prism-logo__descriptor">Machine Risk Monitoring</span>
            @endif
        </span>
    @endif
</span>
