<?php

namespace App\Support;

class FinanceTransformer
{
    public static function account($item)
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'type' => $item->type,
            'balance' => (float) $item->balance,
            'icon' => $item->icon,
            'color' => $item->color,
            'notes' => $item->notes,
            'createdAt' => strtotime($item->created_at) * 1000,
            'updatedAt' => strtotime($item->updated_at) * 1000,
        ];
    }

    public static function incomeSource($item)
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'color' => $item->color,
            'createdAt' => strtotime($item->created_at) * 1000,
        ];
    }

    public static function transaction($item)
    {
        return [
            'id' => $item->id,
            'accountId' => $item->account_id,
            'type' => $item->type,
            'category' => $item->category,
            'amount' => (float) $item->amount,
            'description' => $item->description,
            'transactionDate' => strtotime($item->transaction_date) * 1000,
            'incomeSourceId' => $item->income_source_id,
            'contactId' => $item->contact_id,
            'transferGroupId' => $item->transfer_group_id,
            'toAccountId' => $item->to_account_id,
            'createdAt' => strtotime($item->created_at) * 1000,
            'updatedAt' => strtotime($item->updated_at) * 1000,
        ];
    }

    public static function budget($item)
    {
        return [
            'id' => $item->id,
            'category' => $item->category,
            'monthlyLimit' => (float) $item->monthly_limit,
            'notes' => $item->notes,
            'createdAt' => strtotime($item->created_at) * 1000,
            'updatedAt' => strtotime($item->updated_at) * 1000,
        ];
    }

    public static function debt($item)
    {
        return [
            'id' => $item->id,
            'creditor' => $item->creditor,
            'totalDebt' => (float) $item->total_debt,
            'remainingDebt' => (float) $item->remaining_debt,
            'monthlyPayment' => (float) $item->monthly_payment,
            'dueDate' => $item->due_date
                ? strtotime($item->due_date) * 1000
                : null,
            'notes' => $item->notes,
            'status' => $item->status,
            'createdAt' => strtotime($item->created_at) * 1000,
            'updatedAt' => strtotime($item->updated_at) * 1000,
        ];
    }

    public static function debtPayment($item)
    {
        return [
            'id' => $item->id,
            'debtId' => $item->debt_id,
            'accountId' => $item->account_id,
            'amount' => (float) $item->amount,
            'paidAt' => strtotime($item->paid_at) * 1000,
            'notes' => $item->notes,
        ];
    }

    public static function trade($item)
    {
        return [
            'id' => $item->id,
            'accountId' => $item->account_id,
            'symbol' => $item->symbol,
            'side' => $item->side,
            'quantity' => (float) $item->quantity,
            'entryPrice' => (float) $item->entry_price,
            'exitPrice' => $item->exit_price
                ? (float) $item->exit_price
                : null,
            'fees' => $item->fees
                ? (float) $item->fees
                : null,
            'status' => $item->status,
            'openedAt' => strtotime($item->opened_at) * 1000,
            'closedAt' => $item->closed_at
                ? strtotime($item->closed_at) * 1000
                : null,
            'notes' => $item->notes,
        ];
    }
}
