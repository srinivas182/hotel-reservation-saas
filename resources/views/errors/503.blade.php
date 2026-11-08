@php(\App\Domains\Identity\Http\Middleware\SetLocale::forErrorPage(request()))
@include('errors.layout', ['code' => 503, 'title' => __('Back in a few minutes'), 'message' => __('The service is being updated. Please try again in a few minutes.')])
