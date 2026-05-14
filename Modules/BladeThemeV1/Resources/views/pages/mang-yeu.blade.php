@extends('bladethemev1::layouts.mang-yeu')

@section('content')
<div class="error-container">
    <img style="    margin-bottom: 14px;
    margin-left: -19px;
    height: 75px;" src="{{ asset('/images/2063adbe-f82e-43f8-a457-c4f06aa87018.jpeg') }}" alt="">
    <h1 class="error-title">This site can't be reached</h1>
    <div class="error-subtitle">www.example.com took too long to respond.</div>
    <ul class="error-items">
        <li>Checking the connection</li>
        <li>Checking the proxy and the firewall</li>
        <li>Running Windows Network Diagnostics</li>
    </ul>
    <div class="error-code">ERR_CONNECTION_TIMED_OUT</div>
    <div class="button-container" style="display: flex; justify-content: space-between">
        <button class="reload-button" onclick="window.history.back()">Reload</button>
        <button class="details-button">Details</button>
    </div>
</div>
@endsection