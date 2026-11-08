@php(\App\Domains\Identity\Http\Middleware\SetLocale::forErrorPage(request()))
@include('errors.layout', ['code' => 500, 'title' => __('Something went wrong'), 'message' => __('The problem has been recorded and the team will look at it. Please try again in a few minutes.')])
