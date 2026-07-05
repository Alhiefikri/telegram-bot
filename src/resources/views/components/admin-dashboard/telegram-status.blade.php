@props([
    'details',
])

<x-admin-dashboard.card padding="sm" shadow="none" {{ $attributes->class(['rounded-xl text-xs font-semibold']) }}>
    <p>Resolved chat ID: <strong>{{ $details['chat_id_label'] }}</strong></p>
    <p>Last test: <strong>{{ $details['last_tested_label'] }}</strong></p>
    <p>Message ID: <strong>{{ $details['message_id_label'] }}</strong></p>
    @if ($details['last_test_error'])
        <p class="text-neo-danger-strong">Reason: {{ $details['last_test_error'] }}</p>
    @endif
</x-admin-dashboard.card>
