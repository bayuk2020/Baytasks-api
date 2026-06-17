<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceTransactionCrudTest extends TestCase
{
    use DatabaseTransactions;

    public function test_transaction_and_income_source_crud_keep_balances_consistent(): void
    {
        $source = $this->postJson('/api/finance/income-sources', [
            'name' => 'Test Salary '.Str::random(8),
            'color' => '#123456',
        ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'name', 'color', 'createdAt'])
            ->json();

        $this->patchJson("/api/finance/income-sources/{$source['id']}", [
            'name' => 'Updated Salary '.Str::random(8),
            'color' => '#654321',
        ])
            ->assertOk()
            ->assertJsonPath('color', '#654321');

        $from = $this->createAccount('From Account', 1000);
        $to = $this->createAccount('To Account', 500);

        $income = $this->postJson('/api/finance/transactions', [
            'accountId' => $from->id,
            'type' => 'income',
            'category' => 'Salary',
            'amount' => 100,
            'transactionDate' => '2026-06-06',
            'incomeSourceId' => $source['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('accountId', $from->id)
            ->assertJsonPath('incomeSourceId', $source['id'])
            ->json();

        $this->assertSame(1100.0, (float) $from->fresh()->balance);

        $this->patchJson("/api/finance/transactions/{$income['id']}", [
            'accountId' => $from->id,
            'type' => 'income',
            'category' => 'Salary',
            'amount' => 150,
            'transactionDate' => '2026-06-06',
            'incomeSourceId' => $source['id'],
        ])->assertOk();

        $this->assertSame(1150.0, (float) $from->fresh()->balance);

        $transfer = $this->postJson('/api/finance/transactions', [
            'accountId' => $from->id,
            'toAccountId' => $to->id,
            'type' => 'transfer',
            'category' => 'Transfer',
            'amount' => 200,
            'transactionDate' => '2026-06-06',
        ])
            ->assertCreated()
            ->assertJsonPath('type', 'transfer')
            ->assertJsonPath('toAccountId', $to->id)
            ->json();

        $this->assertSame(950.0, (float) $from->fresh()->balance);
        $this->assertSame(700.0, (float) $to->fresh()->balance);

        $this->patchJson("/api/finance/transactions/{$transfer['id']}", [
            'accountId' => $from->id,
            'toAccountId' => $to->id,
            'type' => 'transfer',
            'category' => 'Transfer',
            'amount' => 250,
            'transactionDate' => '2026-06-07',
        ])->assertOk();

        $this->assertSame(900.0, (float) $from->fresh()->balance);
        $this->assertSame(750.0, (float) $to->fresh()->balance);

        $visibleTransactions = $this->getJson('/api/finance/transactions')
            ->assertOk()
            ->assertJsonFragment(['id' => $income['id']])
            ->assertJsonFragment(['id' => $transfer['id']])
            ->json();

        $visibleTransferLegs = collect($visibleTransactions)
            ->where('transferGroupId', $transfer['transferGroupId'])
            ->count();
        $this->assertSame(1, $visibleTransferLegs);

        $this->deleteJson("/api/finance/transactions/{$transfer['id']}")
            ->assertOk();
        $this->assertSame(1150.0, (float) $from->fresh()->balance);
        $this->assertSame(500.0, (float) $to->fresh()->balance);

        $this->deleteJson("/api/finance/transactions/{$income['id']}")
            ->assertOk();
        $this->assertSame(1000.0, (float) $from->fresh()->balance);

        $this->deleteJson("/api/finance/income-sources/{$source['id']}")
            ->assertOk();
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
