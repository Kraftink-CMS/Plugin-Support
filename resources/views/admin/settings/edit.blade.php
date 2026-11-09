@use('Illuminate\Pagination\LengthAwarePaginator')
@use('App\Models\Notifications\NotificationDelivery')
@use('App\Kraft\Settings')
@php
    /** @var LengthAwarePaginator<int, NotificationDelivery> $deliveries */
@endphp
@extends('admin.layouts.layout')
@section('title', __('support::admin.settings.settings'))
@section('content')
    <form data-ajax="true" data-redirect-url="response" method="post"
        action="{{ route('plugins.support.admin.settings.update') }}" class="card">
        @csrf
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"
                        for="cooldown_seconds">{{ __('support::admin.settings.cooldown_seconds') }}</label>
                    <input class="form-control" type="number" id="cooldown_seconds" name="cooldown_seconds" min="0"
                        max="86400"
                        value="{{ old('cooldown_seconds', app(Settings::class)->get('support.cooldown_seconds', 60)) }}"
                        required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"
                        for="max_open_tickets">{{ __('support::admin.settings.max_open_tickets') }}</label>
                    <input class="form-control" type="number" id="max_open_tickets" name="max_open_tickets" min="1"
                        max="100"
                        value="{{ old('max_open_tickets', app(Settings::class)->get('support.max_open_tickets', 5)) }}"
                        required>
                </div>
                <div class="col-12">
                    <input type="hidden" name="members_can_reopen" value="0">
                    <label class="form-check">
                        <input class="form-check-input" name="members_can_reopen" value="1" type="checkbox"
                            @checked(old('members_can_reopen', app(Settings::class)->get('support.members_can_reopen', true)))>
                        <span class="form-check-label">{{ __('support::admin.settings.members_can_reopen') }}</span>
                    </label>
                </div>
                <div class="col-12">
                    <label class="form-label"
                        for="support-introduction">{{ __('support::admin.settings.introduction') }}</label>
                    <textarea class="form-control" id="support-introduction" name="introduction" rows="4" data-editor="tinymce">{{ old('introduction', app(Settings::class)->get('support.introduction', '')) }}</textarea>
                </div>
                <fieldset class="col-12">
                    <legend class="fs-5">{{ __('support::admin.notifications.notifications') }}</legend>
                    @foreach (['native', 'email', 'discord'] as $channel)
                        <input type="hidden" name="{{ $channel }}" value="0">
                        <label class="form-check">
                            <input class="form-check-input" name="{{ $channel }}" value="1" type="checkbox"
                                @checked(old(
                                        $channel,
                                        app(Settings::class)->get(
                                            $channel === 'discord' ? 'support.discord.enabled' : 'support.notifications.' . $channel,
                                            $channel === 'native')))>
                            <span
                                class="form-check-label">{{ __('support::admin.notifications.' . $channel) }}</span></label>
                    @endforeach
                    <p class="form-text">
                        {{ __('support::admin.notifications.notification_privacy') }}</p>
                </fieldset>
                <div class="col-12">
                    <label class="form-label"
                        for="support-webhook">{{ __('support::admin.notifications.webhook') }}</label>
                    <input class="form-control" type="password" id="support-webhook" name="webhook_url" maxlength="255"
                        autocomplete="new-password" data-announcement-webhook>
                    @if ($webhookConfigured)
                        <p class="form-text">{{ __('support::admin.notifications.webhook_configured') }}</p>
                        <label class="form-check">
                            <input class="form-check-input" name="remove_webhook" type="checkbox" value="1">
                            <span
                                class="form-check-label">{{ __('support::admin.notifications.remove_webhook') }}</span></label>
                    @endif
                </div>
                <fieldset class="d-none" data-announcement-proof hidden disabled>
                    @include('partials.forms.confirm-action', [
                        'confirmationId' => 'support-notifications',
                    ])
                </fieldset>
            </div>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('support::messages.tickets.save') }}</button>
        </div>
    </form>
    <div class="card" id="support-admin-deliveries" data-ajax-pagination data-table tabindex="-1">
        <div class="card-header">
            <h2 class="card-title">{{ __('support::admin.notifications.deliveries') }}</h2>
        </div>
        <x-tables.controls :paginator="$deliveries" container-class="card-body" />
        <div class="card-body p-0 table-responsive">
            <table class="table align-middle mb-0">
                <thead class="text-nowrap">
                    <tr>
                        <th scope="col" data-table-sort="resource">{{ __('support::messages.tickets.subject') }}</th>
                        <th scope="col" data-table-sort="type">{{ __('support::admin.notifications.event') }}</th>
                        <th scope="col" data-table-sort="channel">{{ __('support::admin.notifications.channel') }}</th>
                        <th scope="col" data-table-sort="status">{{ __('support::admin.notifications.state') }}</th>
                        <th>{{ __('support::messages.search.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deliveries as $delivery)
                        <tr>
                            <td>#{{ $delivery->getAttribute('ticket_id') }}</td>
                            <td>{{ __('support::messages.notifications.notice_' . $delivery->getAttribute('event')) }}</td>
                            <td>{{ $delivery->channel }}</td>
                            <td>{{ __('support::admin.notifications.' . $delivery->status) }}</td>
                            <td>
                                @if (in_array($delivery->status, ['failed', 'uncertain']))
                                    <form data-ajax="true" data-redirect-url="response" method="post"
                                        action="{{ route('plugins.support.admin.deliveries.retry', $delivery->getKey()) }}"
                                        data-confirm="{{ __('support::admin.notifications.retry_warning') }}">
                                        @csrf
                                        <input type="hidden" name="confirmed" value="1">
                                        <button
                                            class="btn btn-sm btn-outline-primary">{{ __('support::admin.notifications.retry') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-tables.empty :colspan="5" :message="__('support::messages.tickets.empty')" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $deliveries->links() }}
        </div>
    </div>
@endsection
