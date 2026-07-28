@extends('web.layouts.app')

@section('title', 'Cari Drama')

@section('content')

<x-web.search.search-box />

<x-web.search.search-filter
    :genres="$genres"
    :countries="$countries"
/>

<div id="web-search-result">
    @include('components.web.search.search-empty')
</div>

@endsection