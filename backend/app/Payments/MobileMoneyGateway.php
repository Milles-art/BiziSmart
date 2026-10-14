<?php
namespace App\Payments;

use App\Models\Business;

interface MobileMoneyGateway
{
    public function createOrder(Business $business, string $orderId, mixed $amount, string $buyerName, string $buyerEmail, string $phone, mixed $itemCount): array;
    public function push(Business $business, string $orderId, string $transactionId, string $phone): array;
    public function status(Business $business, string $orderId, mixed $expectedAmount): array;
}
