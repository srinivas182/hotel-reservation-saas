<x-mail::message>
# {{ __('Hello :name,', ['name' => $p->lead->name]) }}

{{ __('Here is our proposal for :hotel: the plan we recommend, its price, and what the assistant can bring you each month. You can start the free trial straight from the page.', ['hotel' => $p->lead->hotel_name]) }}

<x-mail::button :url="$url">
{{ __('See the proposal') }}
</x-mail::button>
</x-mail::message>
