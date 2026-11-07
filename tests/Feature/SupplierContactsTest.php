<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class SupplierContactsTest extends TestCase
{
    use QuoteFixtures, RefreshDatabase;

    public function test_orders_contacts_fill_only_empty_suppliers_and_prefer_an_orders_contact(): void
    {
        config(['promodata.token' => 't']);
        $a = $this->supplier(1, 'Empty One');
        $b = $this->supplier(2, 'Edited');
        $b->update(['orders_email' => 'mine@edited.example']);
        $this->catalogueProduct(101, 1);
        $this->catalogueProduct(102, 2, 'Cap two', 'C2');
        Http::fake(['*/suppliers*' => Http::response(['data' => [
            ['id' => 1, 'details' => ['email' => 'info@one.example', 'contacts' => [
                ['position' => 'Marketing', 'email' => 'm@one.example', 'name' => 'Mia'],
                ['position' => 'Customer Service', 'email' => 'orders@one.example', 'name' => 'Sam'],
            ]]],
            ['id' => 2, 'details' => ['email' => 'info@two.example', 'contacts' => []]],
        ]])]);

        $this->artisan('promodata:supplier-contacts --apply')->expectsOutputToContain('Filled 1.')->assertSuccessful();

        $this->assertSame(['Sam', 'orders@one.example'], [$a->fresh()->orders_contact, $a->fresh()->orders_email]);
        $this->assertSame('mine@edited.example', $b->fresh()->orders_email);
    }
}
