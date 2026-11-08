@php(\App\Domains\Identity\Http\Middleware\SetLocale::forErrorPage(request()))
@include('errors.layout', ['code' => 429, 'title' => __('Too many attempts'), 'message' => __('Please wait a minute before trying again.')])
