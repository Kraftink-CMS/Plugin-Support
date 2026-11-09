@once
    @push('scripts')
        <script type="module"
            src="{{ route('site.extensions.assets.show', ['id' => 'support', 'asset' => 'js/support.js']) }}">
        </script>
    @endpush
@endonce
