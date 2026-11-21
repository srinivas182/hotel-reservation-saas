@props(['url'])
{{-- Sprint I: the product name beside an indigo mark, as in the app. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<span style="display: inline-block; width: 28px; height: 28px; line-height: 28px; border-radius: 8px; background-color: #4f46e5; color: #ffffff; font-size: 15px; text-align: center; vertical-align: middle;">&#9679;</span>
<span style="vertical-align: middle; margin-left: 8px; color: #14142b; font-size: 18px; font-weight: 800; letter-spacing: -0.02em;">{!! $slot !!}</span>
</a>
</td>
</tr>
