{{-- Progress line (stages reached are done; the next one is highlighted) and the "Next step" card at the top of an order or quote (docs/desk.md). Pass $state from App\Desk\JobState. --}}
<ol class="dsteps" aria-label="Progress">
  @foreach($state['steps'] as $i => $label)
    <li class="{{ $i <= $state['now'] ? 'done' : ($i === $state['now'] + 1 ? 'now' : '') }}" @if($i === $state['now']) aria-current="step" @endif>{{ $label }}</li>
  @endforeach
</ol>
@if($state['bucket'] !== 'done' || $state['action'])
<div class="dnext">
  <div>
    <div class="eyebrow">Next step</div>
    <h2>{{ $state['title'] }}</h2>
    <p>{{ $state['text'] }}</p>
    @if(! empty($state['checks']))
      <div class="dchecks">@foreach($state['checks'] as [$ok, $label])<span class="{{ $ok ? 'y' : 'x' }}">{{ $label }}</span>@endforeach</div>
    @endif
  </div>
  <div>@include('desk._action', ['action' => $state['action'], 'class' => 'primary big'])</div>
</div>
@endif
