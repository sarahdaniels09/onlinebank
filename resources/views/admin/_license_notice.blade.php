@php
    // $licenseStatus is shared by EnsureIsAdmin middleware for admin pages
    $ls = isset($licenseStatus) ? $licenseStatus : ['status' => 'missing', 'message' => 'License not verified'];
@endphp

@if(isset($ls['status']) && $ls['status'] !== 'valid')
    <div class="container-fluid mt-2">
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <strong>License notice:</strong>
            <span>
                @if(!empty($ls['message']))
                    {{ $ls['message'] }}
                @else
                    Your license status is: {{ strtoupper($ls['status']) }}. Please verify your purchase code.
                @endif
            </span>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    </div>
@endif
