<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteExtra;
use App\Models\QuoteItem;
use App\Quotes\LineTransfer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class LineTransferController extends Controller
{
    public function __construct(private readonly LineTransfer $transfer) {}

    public function item(Request $request, QuoteItem $item)
    {
        return $this->handle($request, $item, $item->name);
    }

    public function extra(Request $request, QuoteExtra $extra)
    {
        return $this->handle($request, $extra, $extra->description);
    }

    private function handle(Request $request, QuoteItem|QuoteExtra $line, string $label)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['move_new', 'copy_new', 'move_existing'])],
            'target_quote_id' => 'required_if:action,move_existing|nullable|integer',
        ]);
        $source = $line->quote;

        try {
            $target = match ($data['action']) {
                'move_new' => $this->transfer->moveToNew($line, $request->user()->id),
                'copy_new' => $this->transfer->copyToNew($line, $request->user()->id),
                'move_existing' => $this->transfer->moveToExisting($line, Quote::findOrFail($data['target_quote_id'])),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['line' => $e->getMessage()]);
        }

        $verb = $data['action'] === 'copy_new' ? 'Copied' : 'Moved';

        return redirect()->route('quotes.show', $source)
            ->with('status', "{$verb} \"{$label}\" to {$target->number}.")
            ->with('status_link', ['url' => route('quotes.show', $target), 'text' => 'Open '.$target->number]);
    }
}
