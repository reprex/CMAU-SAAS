@extends('layouts.app')
@section('title', 'New quote')
@section('content')
<div><div class="eyebrow">Quote desk</div><h1>New quote</h1></div>
<form method="post" action="{{ route('quotes.store') }}" class="card">
  @csrf
  <p class="muted" style="margin:0">For phone and email enquiries. Website requests arrive by themselves from Shopify.</p>
  @include('quotes._header-fields')
  <div><button class="btn primary" type="submit">Create quote</button></div>
</form>
@endsection
