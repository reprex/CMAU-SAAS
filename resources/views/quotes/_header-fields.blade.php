@php($q = $quote ?? null)
<div class="fgrid">
  <label class="f">Title on the PDF<input name="title" value="{{ old('title', $q?->title) }}" placeholder="e.g. Branded tees and stubby coolers"></label>
  <label class="f">Company<input name="client_company" value="{{ old('client_company', $q?->client_company) }}"></label>
  <label class="f">Contact name<input name="client_name" value="{{ old('client_name', $q?->client_name) }}"></label>
  <label class="f">Email<input type="email" name="client_email" value="{{ old('client_email', $q?->client_email) }}"></label>
  <label class="f">Phone<input name="client_phone" value="{{ old('client_phone', $q?->client_phone) }}"></label>
  <label class="f">ROD contact id<input name="rod_contact_id" inputmode="numeric" value="{{ old('rod_contact_id', $q?->rod_contact_id) }}"></label>
</div>
<div class="fgrid">
  <label class="f">Deliver to (name)<input name="delivery_name" value="{{ old('delivery_name', $q?->delivery_name) }}"></label>
  <label class="f">Address<input name="delivery_address1" value="{{ old('delivery_address1', $q?->delivery_address1) }}"></label>
  <label class="f">Address line 2<input name="delivery_address2" value="{{ old('delivery_address2', $q?->delivery_address2) }}"></label>
  <label class="f">Suburb<input name="delivery_city" value="{{ old('delivery_city', $q?->delivery_city) }}"></label>
  <label class="f">State<input name="delivery_state" value="{{ old('delivery_state', $q?->delivery_state) }}"></label>
  <label class="f">Postcode<input name="delivery_postcode" value="{{ old('delivery_postcode', $q?->delivery_postcode) }}"></label>
</div>
<div class="fgrid">
  <label class="f">Client deadline<input type="date" name="deadline" value="{{ old('deadline', $q?->deadline?->toDateString()) }}"></label>
  <label class="f">Valid until<input type="date" name="valid_until" value="{{ old('valid_until', $q?->valid_until?->toDateString()) }}"></label>
  <label class="f" style="grid-column: span 2">Timing line<input name="lead_time" value="{{ old('lead_time', $q?->lead_time ?? config('quotes.default_lead_time')) }}"></label>
</div>
<label class="f">Internal notes (not on the PDF)<textarea name="internal_notes">{{ old('internal_notes', $q?->internal_notes) }}</textarea></label>
