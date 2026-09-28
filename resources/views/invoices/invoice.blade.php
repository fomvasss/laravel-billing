@extends('billing::invoices.layout')

@section('document')
    @include('billing::invoices.partials.header')
    @include('billing::invoices.partials.parties')
    @include('billing::invoices.partials.summary')
    @include('billing::invoices.partials.items')
    @include('billing::invoices.partials.totals')
    @include('billing::invoices.partials.note')
    @include('billing::invoices.partials.pay')
    @include('billing::invoices.partials.footer')
@endsection
