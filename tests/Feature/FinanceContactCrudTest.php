<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceContactCrudTest extends TestCase
{
    use DatabaseTransactions;

    public function test_contacts_support_transaction_relationships_and_analytics(): void
    {
        $family = $this->postJson('/api/finance/contacts', [
            'name' => 'Family '.Str::random(8),
            'type' => 'family',
            'phone' => '+628123456789',
            'notes' => 'Family contact',
        ])
            ->assertCreated()
            ->assertJsonStructure([
                'id',
                'name',
                'type',
                'phone',
                'notes',
                'createdAt',
                'updatedAt',
            ])
            ->json();

        $employee = $this->postJson('/api/finance/contacts', [
            'name' => 'Employee '.Str::random(8),
            'type' => 'employee',
        ])
            ->assertCreated()
            ->json();

        $vendor = $this->postJson('/api/finance/contacts', [
            'name' => 'Vendor '.Str::random(8),
            'type' => 'vendor',
        ])
            ->assertCreated()
            ->json();

        $this->patchJson("/api/finance/contacts/{$vendor['id']}", [
            'phone' => '+628987654321',
            'notes' => 'Updated vendor',
        ])
            ->assertOk()
            ->assertJsonPath('phone', '+628987654321')
            ->assertJsonPath('notes', 'Updated vendor');

        $from = $this->createAccount('Primary', 5000);
        $to = $this->createAccount('Family Wallet', 1000);

        $expense = $this->postJson('/api/finance/transactions', [
            'accountId' => $from->id,
            'type' => 'expense',
            'category' => 'Shopping',
            'amount' => 300,
            'transactionDate' => '2026-06-06',
            'contactId' => $vendor['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('contactId', $vendor['id'])
            ->json();

        $salary = $this->postJson('/api/finance/transactions', [
            'accountId' => $from->id,
            'type' => 'expense',
            'category' => 'Salary',
            'amount' => 500,
            'transactionDate' => '2026-06-06',
            'contactId' => $employee['id'],
        ])
            ->assertCreated()
            ->json();

        $transfer = $this->postJson('/api/finance/transactions', [
            'accountId' => $from->id,
            'toAccountId' => $to->id,
            'type' => 'transfer',
            'category' => 'Transfer',
            'amount' => 200,
            'transactionDate' => '2026-06-06',
            'contactId' => $family['id'],
        ])
            ->assertCreated()
            ->json();

        $this->getJson("/api/finance/contacts/{$vendor['id']}/transactions")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $expense['id'],
                'contactId' => $vendor['id'],
            ]);

        $analytics = $this->getJson('/api/finance/contacts/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'contacts',
                'topContacts',
                'totalFamilyTransfers',
                'totalEmployeeSalaries',
            ])
            ->json();

        $byContact = collect($analytics['contacts'])->keyBy('contactId');
        $this->assertSame(300.0, (float) $byContact[$vendor['id']]['totalExpense']);
        $this->assertSame(500.0, (float) $byContact[$employee['id']]['totalExpense']);
        $this->assertSame(200.0, (float) $byContact[$family['id']]['totalTransfer']);
        $this->assertGreaterThanOrEqual(200.0, (float) $analytics['totalFamilyTransfers']);
        $this->assertGreaterThanOrEqual(500.0, (float) $analytics['totalEmployeeSalaries']);
        $this->assertContains(
            $vendor['id'],
            collect($analytics['topContacts'])->pluck('contactId')->all()
        );

        $this->deleteJson("/api/finance/contacts/{$vendor['id']}")
            ->assertOk();
        $this->assertNull(Transaction::findOrFail($expense['id'])->contact_id);

        $this->deleteJson("/api/finance/transactions/{$salary['id']}")->assertOk();
        $this->deleteJson("/api/finance/transactions/{$transfer['id']}")->assertOk();
        $this->deleteJson("/api/finance/contacts/{$employee['id']}")->assertOk();
        $this->deleteJson("/api/finance/contacts/{$family['id']}")->assertOk();
    }

    private function createAccount(string $name, float $balance): Account
    {
        return Account::create([
            'id' => (string) Str::uuid(),
            'name' => $name.' '.Str::random(8),
            'type' => 'bank',
            'balance' => $balance,
            'opening_balance' => $balance,
            'icon' => 'Wallet',
            'color' => '#123456',
            'is_active' => true,
        ]);
    }
}
