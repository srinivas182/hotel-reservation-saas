@php(\App\Domains\Identity\Http\Middleware\SetLocale::forErrorPage(request()))
@include('errors.layout', ['code' => 403, 'title' => __('You cannot open this page'), 'message' => __('Your role does not give access to this page. Ask the hotel owner or manager if you need it.')])
