<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\SaleDebt;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\{ReportMath, ReportPeriod};
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Dated customer credit statement: every credit sale, return reduction and
 * collection in one chronological ledger with an exact running balance.
 * Balances are recomputed from the journals, never adjusted by hand.
 */
class CustomerStatementService
{
    public function __construct(private \App\Tenancy\TenantContext $context) {}

    public function build(Customer $customer, array $filters, bool $export = false): array
    {
        return DB::transaction(function () use ($customer, $filters, $export) {
            $customer->assertCurrentBusiness();
            $limit = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
            $from = $filters['from'] ?? null;
            $to = $filters['to'] ?? null;
            $start = $from ? (new ReportPeriod($from, $from))->start : null;
            $until = $to ? (new ReportPeriod($to, $to))->until : null;

            $entries = [];
            $sequence = 0;
            $debtQuery = SaleDebt::where('customer_id', $customer->id)->with('sale');
            if ($from) {
                $debtQuery->where('created_at', '>=', $start);
            }
            if ($to) {
                $debtQuery->where('created_at', '<', $until);
            }
            foreach ($debtQuery->orderBy('id')->limit(5001)->get() as $debt) {
                $entries[] = [
                    'date' => $debt->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('Y-m-d'),
                    'at' => $debt->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('Y-m-d H:i:s.u'),
                    'seq' => $sequence++, 'kind' => 'credit_sale', 'label' => $debt->sale?->receipt_number ?? 'Credit sale',
                    'charge' => (int) $debt->original_amount, 'payment' => 0, 'debt_reduction' => 0,
                    'reference' => $debt->sale?->publicId(),
                ];
            }

            $saleIds = Sale::where('customer_id', $customer->id)->select('id');
            $returnQuery = SaleReturn::whereIn('sale_id', $saleIds);
            if ($from) {
                $returnQuery->where('created_at', '>=', $start);
            }
            if ($to) {
                $returnQuery->where('created_at', '<', $until);
            }
            foreach ($returnQuery->where('debt_reduction', '>', 0)->orderBy('id')->limit(5001)->get() as $return) {
                if ((int) $return->debt_reduction <= 0) {
                    continue;
                }
                $original = Sale::find($return->sale_id);
                $entries[] = [
                    'date' => $return->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('Y-m-d'),
                    'at' => $return->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('Y-m-d H:i:s.u'),
                    'seq' => $sequence++, 'kind' => 'return', 'label' => 'Return '.($original?->receipt_number ?? 'sale'),
                    'charge' => 0, 'payment' => 0, 'debt_reduction' => (int) $return->debt_reduction,
                    'reference' => $return->publicId(),
                ];
            }

            $paymentQuery = CustomerPayment::where('customer_id', $customer->id);
            if ($from) {
                $paymentQuery->where('created_at', '>=', $start);
            }
            if ($to) {
                $paymentQuery->where('created_at', '<', $until);
            }
            foreach ($paymentQuery->orderBy('id')->limit(5001)->get() as $payment) {
                $entries[] = [
                    'date' => $payment->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('Y-m-d'),
                    'at' => $payment->created_at->copy()->timezone('Africa/Dar_es_Salaam')->format('Y-m-d H:i:s.u'),
                    'seq' => $sequence++, 'kind' => 'collection', 'label' => $payment->method === 'mpesa' ? 'Mobile money collection' : 'Cash collection',
                    'charge' => 0, 'payment' => (int) $payment->amount, 'debt_reduction' => (int) $payment->amount,
                    'reference' => $payment->publicId(),
                ];
            }

            if (count($entries) > 5000) { throw new ApiException('REPORT_TOO_LARGE', 'Choose a narrower statement period (at most 5000 movements).', 422); }
            usort($entries, fn ($a, $b) => [$a['at'], $a['seq']] <=> [$b['at'], $b['seq']]);

            $opening = $start ? $this->balanceBefore($customer, $start) : 0;
            $running = $opening;
            foreach ($entries as $index => $entry) {
                $running = ReportMath::add($running, $entry['charge']);
                $running = ReportMath::add($running, -$entry['debt_reduction']);
                $entries[$index]['balance'] = $running;
            }
            $closing = $running;

            $totalCharges = 0;
            $totalPayments = 0;
            foreach ($entries as $entry) {
                $totalCharges = ReportMath::add($totalCharges, $entry['charge']);
                $totalPayments = ReportMath::add($totalPayments, $entry['payment']);
            }

            return [
                'customer' => ['id' => $customer->publicId(), 'name' => $customer->name,
                    'phone' => $customer->phone, 'credit_limit' => (int) $customer->credit_limit],
                'period' => ['from' => $from, 'to' => $to, 'timezone' => 'Africa/Dar_es_Salaam'],
                'opening_balance' => $opening,
                'charges' => $totalCharges,
                'payments' => $totalPayments,
                'closing_balance' => $closing,
                'outstanding_balance' => $this->outstanding($customer->id),
                'currency' => 'TSh',
                'generated_at' => now()->toISOString(),
                'entries' => $export ? $entries : array_slice($entries, (($filters['page'] ?? 1) - 1) * $limit, $limit),
                'meta' => ['count' => count($entries), 'total' => count($entries), 'page' => $filters['page'] ?? 1, 'per_page' => $limit],
            ];
        });
    }

    /** Balance implied by every journal before the selected UTC boundary. */
    private function balanceBefore(Customer $customer, string $date): int
    {
        $balance = 0;
        SaleDebt::where('customer_id', $customer->id)->where('created_at', '<', $date)
            ->chunkById(200, function ($rows) use (&$balance) {
                foreach ($rows as $debt) {
                    $balance = ReportMath::add($balance, (int) $debt->original_amount);
                }
            });
        SaleReturn::whereIn('sale_id', Sale::where('customer_id', $customer->id)->select('id'))
            ->where('created_at', '<', $date)->chunkById(200, function ($rows) use (&$balance) {
                foreach ($rows as $return) {
                    $balance = ReportMath::add($balance, -(int) $return->debt_reduction);
                }
            });
        CustomerPayment::where('customer_id', $customer->id)->where('created_at', '<', $date)
            ->chunkById(200, function ($rows) use (&$balance) {
                foreach ($rows as $payment) {
                    $balance = ReportMath::add($balance, -(int) $payment->amount);
                }
            });

        return $balance;
    }

    private function outstanding(int $customerId): int
    {
        $balance = 0;
        SaleDebt::where('customer_id', $customerId)->where('remaining_amount', '>', 0)
            ->chunkById(200, function ($rows) use (&$balance) {
                foreach ($rows as $debt) {
                    $balance = ReportMath::add($balance, (int) $debt->remaining_amount);
                }
            });

        return $balance;
    }
}
