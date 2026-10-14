<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Business,Customer,User};
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(private TenantContext $context, private FinancialActorService $actors) {}

    public function save(User $original, array $input, ?Customer $customer = null): Customer
    {
        $customer?->assertCurrentBusiness();
        return DB::transaction(function () use ($original,$input,$customer) {
            $actor = $this->actors->lock($original,'manage_customers');
            Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
            if ($customer) { $customer->refreshForUpdate(); }
            if (isset($input['phone'])) {
                $duplicates = Customer::where('phone_lookup',Customer::phoneLookup($input['phone']));
                if ($customer) { $duplicates->whereKeyNot($customer->id); }
                if ($duplicates->exists()) { throw new ApiException('DUPLICATE_CUSTOMER_PHONE','This phone number already belongs to a customer.',409,'phone'); }
            }
            $new = ! $customer;
            $customer ??= new Customer;
            $customer->fill($input); $customer->save();
            activity('customers')->performedOn($customer)->causedBy($actor)->event($new ? 'customer_created' : 'customer_updated')
                ->withProperties(['customer_id'=>$customer->publicId(),'credit_limit'=>$customer->credit_limit])->log('Customer profile changed');
            return $customer->refresh();
        },3);
    }

    public function list(array $input): array
    {
        // Contacts stay encrypted; stream tenant rows instead of storing searchable plaintext names.
        $search = mb_strtolower($input['search'] ?? ''); $page = (int)($input['page'] ?? 1); $size = (int)($input['per_page'] ?? 30);
        $phoneSearch = $search;
        try { $phoneSearch = \App\Support\StaffPhone::normalize($search); } catch (\InvalidArgumentException) {}
        $total = 0; $items = [];
        foreach (Customer::orderBy('id')->cursor() as $customer) {
            if ($search !== '' && ! str_contains(mb_strtolower($customer->name),$search) && ! str_contains($customer->phone ?? '',$phoneSearch)) { continue; }
            if ($total >= ($page-1)*$size && count($items) < $size) { $items[] = $customer; }
            $total++;
        }
        return ['customers'=>$items,'meta'=>['page'=>$page,'per_page'=>$size,'total'=>$total]];
    }
}
