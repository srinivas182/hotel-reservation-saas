@php(\App\Domains\Identity\Http\Middleware\SetLocale::forErrorPage(request()))
@include('errors.layout', ['code' => 419, 'title' => __('Page expired'), 'message' => __('The page was open too long. Go back, reload it and try again.')])
