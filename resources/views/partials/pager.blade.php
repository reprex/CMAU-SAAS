@if ($paginator->hasPages())
<nav class="row" aria-label="Pages" style="gap:8px">
  @if ($paginator->onFirstPage())<span class="btn small" aria-disabled="true" style="opacity:.5">Previous</span>@else<a class="btn small" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif
  <span class="small muted">Page {{ $paginator->currentPage() }}@if(method_exists($paginator, 'lastPage')) of {{ $paginator->lastPage() }}@endif</span>
  @if ($paginator->hasMorePages())<a class="btn small" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="btn small" aria-disabled="true" style="opacity:.5">Next</span>@endif
</nav>
@endif
