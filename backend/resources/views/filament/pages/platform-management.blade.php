<x-filament-panels::page>
    <div class="space-y-6">
        <p>Onboard supermarket clients using one-use owner setup. Record completed subscription payments; no funds are transferred.</p>
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="newAction('onboard')">Create client</x-filament::button>
            <x-filament::button wire:click="newAction('plan')">Create plan</x-filament::button>
            <x-filament::button wire:click="newAction('subscription')">Manage subscription</x-filament::button>
            <x-filament::button wire:click="newAction('features')">Client features</x-filament::button>
            <x-filament::button wire:click="seedBaseline">Documented baseline plans</x-filament::button>
        </div>
        @if($result)<div role="status" class="rounded-lg border p-4">Saved. @if(isset($result['setup_url']))<p>This private setup link expires in 90 minutes. Hand it to the verified owner.</p><a href="{{ $result['setup_url'] }}" rel="noreferrer">Owner setup link</a>@endif</div>@endif
        @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form wire:submit="save" wire:key="platform-form-{{ $action }}-{{ $operation }}" class="rounded-xl border p-5">
            <fieldset class="space-y-4" wire:loading.attr="disabled" wire:target="newAction,editPlan,editClient,save">
            <h2 class="text-lg font-bold">{{ ucfirst($action) }}</h2>
            @foreach($form as $key=>$value)
                @if(!is_array($value))
                <label class="block">{{ str_replace('_',' ',$key) }}
                    @if($key==='plan_code')<select wire:model="form.plan_code" class="block w-full rounded border p-2"><option value="">Select plan</option>@foreach($plans as $plan)<option value="{{ $plan->code }}">{{ $plan->name }}</option>@endforeach</select>
                    @elseif($key==='status')<select wire:model="form.status" class="block w-full rounded border p-2">@foreach(['active','suspended','expired'] as $status)<option>{{ $status }}</option>@endforeach</select>
                    @else<input wire:model="form.{{ $key }}" type="{{ str_ends_with($key,'_on')?'date':(in_array($key,['price_monthly','expected_revision'])?'number':'text') }}" class="block w-full rounded border p-2" />@endif
                </label>
                @endif
            @endforeach
            @if(isset($form['limits']))<p>Blank limit means unlimited. Limits cannot drop below existing active usage.</p>@foreach($form['limits'] as $key=>$value)<label class="block">{{ $key }}<input type="number" min="1" wire:model="form.limits.{{ $key }}" class="block w-full rounded border p-2" /></label>@endforeach @endif
            @if(isset($form['features']))<fieldset><legend>Explicit feature assignments</legend>@foreach($featureKeys as $key)<label class="mr-4 inline-flex gap-2"><input type="checkbox" wire:model="form.features.{{ $key }}" @disabled($key==='tra') />{{ str_replace('_',' ',$key) }}</label>@endforeach</fieldset>@endif
            @if($action==='subscription')<x-filament::button type="button" wire:click="addPayment">Add completed payment record</x-filament::button>@if(isset($form['payment']))@foreach($form['payment'] as $key=>$value)<label class="block">Payment {{ $key }}<input wire:model="form.payment.{{ $key }}" type="{{ $key==='paid_on'?'date':($key==='amount'?'number':'text') }}" class="block w-full rounded border p-2"/></label>@endforeach<x-filament::button type="button" wire:click="removePayment">Remove payment fields</x-filament::button>@endif @endif
            <x-filament::button type="submit" wire:loading.attr="disabled" :disabled="(bool)$result">Save {{ $action }}</x-filament::button>
            </fieldset>
        </form>
        <section><h2 class="text-lg font-bold">Plans</h2>@foreach($plans as $plan)<div class="flex justify-between border-b py-3"><span>{{ $plan->name }} · TSh {{ number_format($plan->price_monthly) }}/month</span><x-filament::button wire:click="editPlan('{{ $plan->code }}')">Edit</x-filament::button></div>@endforeach</section>
        <section><h2 class="text-lg font-bold">Client health</h2><label>Search clients<input wire:model.live.debounce.300ms="search" class="block w-full rounded border p-2" /></label>@foreach($clients as $client)<div class="space-y-2 border-b py-3"><p>{{ $client['name'] }} · {{ $client['subdomain'] }} · {{ $client['plan_code'] }}</p><p>Owner: {{ $client['owner_configured']?'configured':'setup pending' }} · Subscription: {{ $client['subscription']['status']??'not yet managed' }}</p><x-filament::button wire:click="editClient('{{ $client['id'] }}','subscription')">Subscription</x-filament::button><x-filament::button wire:click="editClient('{{ $client['id'] }}','features')">Features</x-filament::button>@if(!$client['owner_configured'])<x-filament::button wire:click="reissueInvite('{{ $client['id'] }}')">Reissue owner setup</x-filament::button>@endif</div>@endforeach</section>
        <section><h2 class="text-lg font-bold">Users search</h2><label>Client<select wire:model.live="staffClient" class="block w-full rounded border p-2"><option value="">Select client</option>@foreach($clients as $client)<option value="{{ $client['id'] }}">{{ $client['name'] }}</option>@endforeach</select></label><label>Staff name or phone<input wire:model.live.debounce.300ms="staffSearch" class="block w-full rounded border p-2" /></label>@foreach($staff as $user)<p class="border-b py-2">{{ $user['name'] }} · {{ $user['phone'] }} · {{ $user['role'] }} · {{ $user['active']?'active':'inactive' }}</p>@endforeach</section>
    </div>
</x-filament-panels::page>
