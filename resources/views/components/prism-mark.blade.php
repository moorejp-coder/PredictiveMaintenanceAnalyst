@props(['title' => null])
{{-- Canonical Focused Split mark. Geometry is fixed: see docs/BRAND.md. Color comes from currentColor. --}}
<svg {{ $attributes->class(['prism-mark']) }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 260 100" fill="none"@if ($title) role="img" aria-label="{{ $title }}"@else aria-hidden="true" focusable="false"@endif>
    <g stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" fill="none">
        <path d="M12 50 H82"/>
        <path d="M82 50 L144 18 H246"/>
        <path d="M82 50 L144 39 H246"/>
        <path d="M82 50 L144 61 H246"/>
        <path d="M82 50 L144 82 H246"/>
    </g>
    <g fill="currentColor">
        <circle cx="246" cy="18" r="5"/>
        <circle cx="246" cy="39" r="5"/>
        <circle cx="246" cy="61" r="5"/>
        <circle cx="246" cy="82" r="5"/>
    </g>
</svg>
