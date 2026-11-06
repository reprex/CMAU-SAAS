{{-- One "next step" button: a link, or a small form when the step creates something (docs/desk.md). --}}
@if($action)
  @if(! empty($action['post']))
    @if(auth()->user()->canEdit())<form method="post" action="{{ $action['url'] }}" style="display:inline">@csrf<button class="btn {{ $class ?? '' }}">{{ $action['label'] }} ›</button></form>@endif
  @else
    <a class="btn {{ $class ?? '' }}" href="{{ $action['url'] }}">{{ $action['label'] }} ›</a>
  @endif
@endif
