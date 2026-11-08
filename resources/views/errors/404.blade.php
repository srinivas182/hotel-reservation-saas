@php(\App\Domains\Identity\Http\Middleware\SetLocale::forErrorPage(request()))
@include('errors.layout', ['code' => 404, 'title' => __('Page not found'), 'message' => __('This page does not exist or was moved. Check the address, or start again from the home page.')])
