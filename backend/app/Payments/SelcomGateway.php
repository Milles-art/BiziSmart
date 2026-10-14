<?php

namespace App\Payments;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB,Http};
use InvalidArgumentException;
use LogicException;

/** Transport only: never creates a sale, deducts stock, changes debt or credits a till. */
final class SelcomGateway implements MobileMoneyGateway
{
    public function __construct(private SelcomMerchant $merchant, private SelcomSignature $signature) {}

    public function createOrder(Business $business, string $orderId, mixed $amount, string $buyerName, string $buyerEmail, string $phone, mixed $itemCount): array
    {
        $this->identifier($orderId);
        Money::amount($amount);
        if ($amount <= 0 || ! is_int($itemCount) || $itemCount <= 0 || trim($buyerName) === '' || mb_strlen($buyerName) > 150
            || ! filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid payment order.');
        $this->phone($phone);
        $settings = $this->merchant->configuration($business);
        $response = $this->request($settings, 'POST', '/v1/checkout/create-order-minimal', [
            'vendor'=>$settings['vendor'], 'order_id'=>$orderId, 'buyer_email'=>$buyerEmail,
            'buyer_name'=>$buyerName, 'buyer_phone'=>$phone, 'amount'=>$amount, 'currency'=>'TZS',
            'no_of_items'=>$itemCount, 'webhook'=>base64_encode($settings['webhook_url']),
        ]);
        // SUCCESS here means order accepted, never paid.
        $accepted = $this->accepted($response);
        return ['accepted'=>$accepted, 'state'=>$accepted ? 'pending' : 'unknown'];
    }

    public function push(Business $business, string $orderId, string $transactionId, string $phone): array
    {
        $this->identifier($orderId); $this->identifier($transactionId); $this->phone($phone);
        $response = $this->request($this->merchant->configuration($business), 'POST', '/v1/checkout/wallet-payment', [
            'transid'=>$transactionId, 'order_id'=>$orderId, 'msisdn'=>$phone,
        ]);
        $accepted = $this->accepted($response);
        return ['accepted'=>$accepted, 'state'=>$accepted ? 'pending' : 'unknown'];
    }

    public function status(Business $business, string $orderId, mixed $expectedAmount): array
    {
        $this->identifier($orderId);
        Money::amount($expectedAmount);
        if ($expectedAmount <= 0) throw new InvalidArgumentException('Invalid expected payment amount.');
        $settings = $this->merchant->configuration($business);
        $response = $this->request($settings, 'GET', '/v1/checkout/order-status', ['order_id'=>$orderId]);
        if (! $this->accepted($response)) return ['state'=>'unknown'];
        $rows = $response['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) throw $this->invalidResponse();
        $row = $rows[0];
        if ((array_key_exists('currency', $row) && $row['currency'] !== 'TZS')
            || (array_key_exists('vendor', $row) && $row['vendor'] !== $settings['vendor'])) throw $this->invalidResponse();
        $rawAmount = $row['amount'] ?? null;
        if (! (is_int($rawAmount) || (is_string($rawAmount) && preg_match('/^(0|[1-9][0-9]*)$/D', $rawAmount))) || (string) $rawAmount !== (string) $expectedAmount
            || ($row['order_id'] ?? null) !== $orderId) throw $this->invalidResponse();
        $state = match ($row['payment_status'] ?? null) {
            'COMPLETED'=>'confirmed', 'PENDING','INPROGRESS'=>'pending',
            'CANCELLED','USERCANCELLED','REJECTED'=>'failed', default=>'unknown',
        };
        if ($state !== 'confirmed') return ['state'=>$state];
        foreach (['transid','reference','channel'] as $field) {
            if (! is_string($row[$field] ?? null) || trim($row[$field]) === '' || strlen($row[$field]) > 150
                || preg_match('/[\x00-\x1f\x7f]/', $row[$field])) throw $this->invalidResponse();
        }
        return ['state'=>'confirmed','amount'=>$expectedAmount,'order_id'=>$orderId,
            'transaction_id'=>$row['transid'],'reference'=>$row['reference'],'channel'=>$row['channel']];
    }

    private function request(array $settings, string $method, string $path, array $payload): array
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('Provider requests must run outside database transactions.');
        $timestamp = now('Africa/Dar_es_Salaam')->format('Y-m-d\TH:i:sP');
        try {
            // No automatic retries or redirects: an uncertain wallet push may already have charged the customer.
            $client = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects'=>false])
                ->withHeaders($this->signature->headers($payload, $settings['api_key'], $settings['api_secret'], $timestamp));
            $url = rtrim($settings['base_url'], '/').$path;
            $response = $method === 'GET' ? $client->get($url, $payload) : $client->post($url, $payload);
        } catch (ConnectionException) {
            throw new ApiException('PAYMENT_OUTCOME_UNKNOWN', 'Payment outcome is uncertain. Check the same order before trying again.', 503);
        }
        if (! $response->successful()) throw new ApiException('PAYMENT_OUTCOME_UNKNOWN', 'The payment provider could not confirm the request. Check the same order before trying again.', 503);
        $body = $response->json();
        if (! is_array($body) || ! is_string($body['result'] ?? null) || ! is_string($body['resultcode'] ?? null)) throw $this->invalidResponse();
        return $body;
    }

    private function accepted(array $response): bool
    {
        return $response['result'] === 'SUCCESS' && $response['resultcode'] === '000';
    }

    private function identifier(string $id): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) throw new InvalidArgumentException('Invalid payment identifier.');
    }

    private function phone(string $phone): void
    {
        if (! preg_match('/^255[67][0-9]{8}$/D', $phone)) throw new InvalidArgumentException('Enter a canonical Tanzania mobile number.');
    }

    private function invalidResponse(): ApiException
    {
        return new ApiException('PAYMENT_RESPONSE_INVALID', 'The provider response could not be verified. Check the same order before trying again.', 502);
    }
}
