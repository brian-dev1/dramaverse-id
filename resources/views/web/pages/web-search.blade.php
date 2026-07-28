@extends('web.layouts.app')

@section('title','Cari Drama')

@section('content')

<x-web.web-search-box/>

<x-web.web-search-filter

    :genres="$genres"

    :countries="$countries"

/>

<div id="web-search-result">

    @include(
        'web.components.web-search-empty'
    )

</div>

@endsection