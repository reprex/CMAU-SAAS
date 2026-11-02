<?php

namespace App\Staff;

use App\Models\Quote;
use App\Models\WebsiteOrder;

/**
 * Client lookup for staff on the website (docs/website-staff.md). The SaaS has no client table
 * yet (ROD Contacts is the master list), so this finds people the SaaS already knows: quote
 * clients and website order contacts, by name, organisation, email or phone; latest details win.
 */
class StaffClients
{
    /** @return list<array<string, mixed>> */
    public function search(string $q, int $limit = 12): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $found = [];
        $quotes = Quote::query()->where('kind', 'quote')->whereNotNull('client_email')
            ->where(fn ($w) => $w->where('client_email', 'like', $like)->orWhere('client_name', 'like', $like)->orWhere('client_company', 'like', $like)->orWhere('client_phone', 'like', $like))
            ->latest('id')->limit(80)->get();
        foreach ($quotes as $qt) {
            $key = mb_strtolower($qt->client_email);
            $found[$key] ??= [
                'email' => $key, 'name' => $qt->client_name, 'organisation' => $qt->client_company, 'phone' => $qt->client_phone,
                'address' => $qt->delivery_address1 ? ['street' => trim($qt->delivery_address1.' '.$qt->delivery_address2), 'suburb' => $qt->delivery_city, 'state' => $qt->delivery_state, 'postcode' => $qt->delivery_postcode, 'country' => $qt->delivery_country ?: 'AU'] : null,
                'last' => $qt->created_at, 'quotes' => 0, 'orders' => 0, 'rod_contact_id' => $qt->rod_contact_id,
            ];
            $found[$key]['quotes']++;
        }
        $orders = WebsiteOrder::query()
            ->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('contact_name', 'like', $like)->orWhere('organisation', 'like', $like)->orWhere('phone', 'like', $like))
            ->latest('id')->limit(80)->get();
        foreach ($orders as $o) {
            $key = mb_strtolower($o->email);
            $addr = ['unit' => $o->address_unit, 'street' => $o->address_street, 'suburb' => $o->address_suburb, 'state' => $o->address_state, 'postcode' => $o->address_postcode, 'country' => $o->address_country];
            if (! isset($found[$key]) || $o->created_at->gt($found[$key]['last'])) {
                $found[$key] = ['email' => $key, 'name' => $o->contact_name, 'organisation' => $o->organisation, 'phone' => $o->phone, 'address' => $addr,
                    'last' => $o->created_at, 'quotes' => $found[$key]['quotes'] ?? 0, 'orders' => $found[$key]['orders'] ?? 0, 'rod_contact_id' => $found[$key]['rod_contact_id'] ?? null];
            }
            $found[$key]['orders']++;
        }

        return collect($found)->sortByDesc('last')->take($limit)->map(fn ($c) => ['last' => $c['last']->toDateString()] + $c)->values()->all();
    }

    /** The client's quotes that are still open, newest first. */
    public function openQuotes(string $email): array
    {
        return Quote::query()->where('kind', 'quote')->where('client_email', mb_strtolower(trim($email)))
            ->whereIn('status', ['NEW', 'PHONE', 'QUOTE'])->withCount('items')->latest('id')->limit(10)->get()
            ->map(fn (Quote $q) => ['id' => $q->id, 'number' => $q->number, 'title' => $q->title, 'status' => Quote::STATUSES[$q->status] ?? $q->status,
                'items' => $q->items_count, 'total' => (float) $q->total, 'created' => $q->created_at->toDateString()])->all();
    }
}
