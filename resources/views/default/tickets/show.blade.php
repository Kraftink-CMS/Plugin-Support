@extends('default.layouts.layout')
@section('title', $ticket->subject)
@section('content')
    <div class="support-ticket">
        @include('support::partials.tickets.show')
    </div>
@endsection
