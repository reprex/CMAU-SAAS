{{-- Move or copy one line. Needs $action (form URL) and $targets (same-email quotes). --}}
<details class="transfer">
  <summary class="small">Move or copy</summary>
  <div style="display:flex;flex-direction:column;gap:8px;min-width:250px;padding-top:4px">
    <div class="row" style="gap:6px">
      <form method="post" action="{{ $action }}">@csrf<input type="hidden" name="action" value="move_new"><button class="btn small" type="submit">Move to a new quote</button></form>
      <form method="post" action="{{ $action }}">@csrf<input type="hidden" name="action" value="copy_new"><button class="btn small" type="submit">Copy to a new quote</button></form>
    </div>
    @if($targets->isNotEmpty())
      <form method="post" action="{{ $action }}" class="row" style="gap:6px">
        @csrf<input type="hidden" name="action" value="move_existing">
        <select name="target_quote_id" aria-label="Quote to move to" style="width:auto;min-height:30px;padding:3px 8px;font-size:13px">
          @foreach($targets as $t)<option value="{{ $t->id }}">{{ $t->number }} · {{ \App\Models\Quote::STATUSES[$t->status] ?? $t->status }}{{ $t->title ? ' · '.\Illuminate\Support\Str::limit($t->title, 30) : '' }}</option>@endforeach
        </select>
        <button class="btn small" type="submit">Move to this quote</button>
      </form>
    @else
      <span class="small muted">{{ $quote->client_email ? 'No other quotes for '.$quote->client_email.'.' : 'Add the client\'s email to move lines to their other quotes.' }}</span>
    @endif
    <span class="small muted">New quotes keep this client and delivery address. Freight is worked out again on both quotes.</span>
  </div>
</details>
