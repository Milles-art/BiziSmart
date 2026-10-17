<x-filament-panels::page>
    <style>
        .biz-platform{--bp-line:#e2e8f0;--bp-panel:#fff;--bp-ink:#1e293b;--bp-muted:#64748b;--bp-disabled:#f1f5f9;display:grid;gap:1.5rem;min-width:0;color:var(--bp-ink)}
        .dark .biz-platform{--bp-line:#3f3f46;--bp-panel:#18181b;--bp-ink:#fafafa;--bp-muted:#a1a1aa;--bp-disabled:#27272a}
        .biz-platform *{box-sizing:border-box}
        .biz-platform .bp-intro,.biz-platform .bp-help{margin:0;color:var(--bp-muted);line-height:1.6}
        .biz-platform .bp-help{font-size:.875rem}
        .biz-platform .bp-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.625rem;min-width:0}
        .biz-platform .bp-actions>*{max-width:100%}
        .biz-platform .bp-actions .fi-btn{white-space:normal;text-align:center}
        .biz-platform .bp-card{min-width:0;padding:1.25rem;border:1px solid var(--bp-line);border-radius:.875rem;background:var(--bp-panel)}
        .biz-platform .bp-stack{display:grid;gap:1.25rem;min-width:0}
        .biz-platform h2{margin:0;font-size:1.0625rem;font-weight:600;line-height:1.5}
        .biz-platform .bp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;min-width:0}
        .biz-platform .bp-field{display:grid;gap:.375rem;min-width:0;font-size:.875rem;font-weight:500;line-height:1.5}
        .biz-platform .bp-field>input,.biz-platform .bp-field>select{display:block;width:100%;min-width:0;min-height:2.75rem;margin:0;border:1px solid var(--bp-line);border-radius:.5rem;padding:.625rem .75rem;background:var(--bp-panel);color:var(--bp-ink);font:inherit;font-weight:400;color-scheme:light}
        .dark .biz-platform .bp-field>input,.dark .biz-platform .bp-field>select{color-scheme:dark}
        .biz-platform .bp-field>input:focus,.biz-platform .bp-field>select:focus,.biz-platform input[type=checkbox]:focus-visible{border-color:#10b981;outline:2px solid #10b981;outline-offset:2px}
        .biz-platform .bp-field>input:disabled,.biz-platform .bp-field>select:disabled,.biz-platform fieldset:disabled .bp-field>input,.biz-platform fieldset:disabled .bp-field>select{background:var(--bp-disabled);color:var(--bp-muted)}
        .biz-platform .bp-features{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,10rem),1fr));gap:.5rem 1rem}
        .biz-platform .bp-checkbox{display:flex;align-items:center;gap:.625rem;min-height:2.5rem;font-size:.875rem;line-height:1.5}
        .biz-platform .bp-checkbox>input{flex:none;width:1rem;height:1rem;margin:0;accent-color:#10b981}
        .biz-platform .bp-checkbox:has(input:disabled){color:var(--bp-muted)}
        .biz-platform .bp-legend{margin-bottom:.5rem;font-size:.875rem;font-weight:600}
        .biz-platform .bp-records{display:grid;gap:.875rem;min-width:0}
        .biz-platform .bp-clients{grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr))}
        .biz-platform .bp-record{display:grid;align-content:start;gap:.75rem;min-width:0;padding:1rem;border:1px solid var(--bp-line);border-radius:.625rem}
        .biz-platform .bp-record>p{margin:0;overflow-wrap:anywhere;line-height:1.5}
        .biz-platform .bp-record-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
        .biz-platform .bp-record-row>span{min-width:0;overflow-wrap:anywhere}
        .biz-platform .bp-notice{display:grid;gap:.5rem;border-left:3px solid #10b981;line-height:1.6;overflow-wrap:anywhere}
        .biz-platform .bp-notice>p{margin:0}
        .biz-platform .bp-notice a{width:fit-content;color:#047857;text-decoration:underline;text-underline-offset:3px}
        .dark .biz-platform .bp-notice a{color:#6ee7b7}
        .biz-platform .bp-errors{border-left-color:#ef4444}
        .biz-platform .bp-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
        .biz-platform .bp-metric{display:grid;gap:.75rem;align-content:start;padding:1.25rem;border:1px solid var(--bp-line);border-radius:.875rem;background:var(--bp-panel)}
        .biz-platform .bp-metric-icon{display:grid;place-items:center;width:2.75rem;height:2.75rem;border-radius:.625rem;color:white}
        .biz-platform .bp-metric-icon svg{width:1.25rem;height:1.25rem}
        .biz-platform .bp-metric-icon.emerald{background:#10b981}.biz-platform .bp-metric-icon.blue{background:#3b82f6}.biz-platform .bp-metric-icon.amber{background:#f59e0b}.biz-platform .bp-metric-icon.violet{background:#8b5cf6}
        .biz-platform .bp-metric strong{font-size:1.5rem;line-height:1.3}.biz-platform .bp-metric p{margin:0;color:var(--bp-muted);font-size:.875rem;line-height:1.5}
        @media(max-width:900px){.biz-platform .bp-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:640px){.biz-platform{gap:1rem}.biz-platform .bp-card{padding:1rem}.biz-platform .bp-grid{grid-template-columns:minmax(0,1fr)}}
    </style>
    <div class="biz-platform">
        <p class="bp-intro">Onboard supermarket clients using one-use owner setup. Record completed subscription payments; no funds are transferred.</p>
        <div class="bp-metrics">
            @foreach([
                ['label'=>'Clients shown','value'=>count($clients),'color'=>'emerald','icon'=>'heroicon-o-building-storefront'],
                ['label'=>'Active subscriptions shown','value'=>collect($clients)->filter(fn($client)=>($client['subscription']['status']??null)==='active')->count(),'color'=>'blue','icon'=>'heroicon-o-check-circle'],
                ['label'=>'Owner setup pending','value'=>collect($clients)->where('owner_configured',false)->count(),'color'=>'amber','icon'=>'heroicon-o-clock'],
                ['label'=>'Plans','value'=>count($plans),'color'=>'violet','icon'=>'heroicon-o-squares-2x2'],
            ] as $metric)
                <div class="bp-metric"><span class="bp-metric-icon {{ $metric['color'] }}"><x-filament::icon :icon="$metric['icon']" /></span><strong>{{ $metric['value'] }}</strong><p>{{ $metric['label'] }}</p></div>
            @endforeach
        </div>
        <div class="bp-actions">
            <x-filament::button wire:click="newAction('onboard')">Create client</x-filament::button>
            <x-filament::button wire:click="newAction('plan')">Create plan</x-filament::button>
            <x-filament::button wire:click="newAction('subscription')">Manage subscription</x-filament::button>
            <x-filament::button wire:click="newAction('features')">Client features</x-filament::button>
            <x-filament::button wire:click="seedBaseline">Documented baseline plans</x-filament::button>
        </div>
        @if($result)<div role="status" class="bp-card bp-notice">Saved. @if(isset($result['setup_url']))<p>This private setup link expires in 90 minutes. Hand it to the verified owner.</p><a href="{{ $result['setup_url'] }}" rel="noreferrer">Owner setup link</a>@endif</div>@endif
        @if($errors->any())<div role="alert" class="bp-card bp-notice bp-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form wire:submit="save" wire:key="platform-form-{{ $action }}-{{ $operation }}" class="bp-card">
            <fieldset class="bp-stack" wire:loading.attr="disabled" wire:target="newAction,editPlan,editClient,save">
            <h2>{{ ucfirst($action) }}</h2>
            <div class="bp-grid">
            @foreach($form as $key=>$value)
                @if(!is_array($value))
                <label class="bp-field">{{ str_replace('_',' ',$key) }}
                    @if($key==='plan_code')<select wire:model="form.plan_code"><option value="">Select plan</option>@foreach($plans as $plan)<option value="{{ $plan->code }}">{{ $plan->name }}</option>@endforeach</select>
                    @elseif($key==='status')<select wire:model="form.status">@foreach(['active','suspended','expired'] as $status)<option>{{ $status }}</option>@endforeach</select>
                    @else<input wire:model="form.{{ $key }}" type="{{ str_ends_with($key,'_on')?'date':(in_array($key,['price_monthly','expected_revision'])?'number':'text') }}" />@endif
                </label>
                @endif
            @endforeach
            </div>
            @if(isset($form['limits']))<div class="bp-stack"><p class="bp-help">Blank limit means unlimited. Limits cannot drop below existing active usage.</p><div class="bp-grid">@foreach($form['limits'] as $key=>$value)<label class="bp-field">{{ $key }}<input type="number" min="1" wire:model="form.limits.{{ $key }}" /></label>@endforeach</div></div>@endif
            @if(isset($form['features']))<fieldset><legend class="bp-legend">Explicit feature assignments</legend><div class="bp-features">@foreach($featureKeys as $key)<label class="bp-checkbox"><input type="checkbox" wire:model="form.features.{{ $key }}" @disabled($key==='tra') />{{ str_replace('_',' ',$key) }}</label>@endforeach</div></fieldset>@endif
            @if($action==='subscription')<div class="bp-stack"><div class="bp-actions"><x-filament::button type="button" wire:click="addPayment">Add completed payment record</x-filament::button></div>@if(isset($form['payment']))<div class="bp-grid">@foreach($form['payment'] as $key=>$value)<label class="bp-field">Payment {{ $key }}<input wire:model="form.payment.{{ $key }}" type="{{ $key==='paid_on'?'date':($key==='amount'?'number':'text') }}"/></label>@endforeach</div><div class="bp-actions"><x-filament::button type="button" wire:click="removePayment">Remove payment fields</x-filament::button></div>@endif</div>@endif
            <div class="bp-actions"><x-filament::button type="submit" wire:loading.attr="disabled" :disabled="(bool)$result">Save {{ $action }}</x-filament::button></div>
            </fieldset>
        </form>
        <section class="bp-card bp-stack"><h2>Plans</h2><div class="bp-records">@foreach($plans as $plan)<div class="bp-record bp-record-row"><span>{{ $plan->name }} · TSh {{ number_format($plan->price_monthly) }}/month</span><x-filament::button wire:click="editPlan('{{ $plan->code }}')">Edit</x-filament::button></div>@endforeach</div></section>
        <section class="bp-card bp-stack"><h2>Client health</h2><label class="bp-field">Search clients<input wire:model.live.debounce.300ms="search" /></label><div class="bp-records bp-clients">@foreach($clients as $client)<div class="bp-record"><p>{{ $client['name'] }} · {{ $client['subdomain'] }} · {{ $client['plan_code'] }}</p><p class="bp-help">Owner: {{ $client['owner_configured']?'configured':'setup pending' }} · Subscription: {{ $client['subscription']['status']??'not yet managed' }}</p><div class="bp-actions"><x-filament::button wire:click="editClient('{{ $client['id'] }}','subscription')">Subscription</x-filament::button><x-filament::button wire:click="editClient('{{ $client['id'] }}','features')">Features</x-filament::button>@if(!$client['owner_configured'])<x-filament::button wire:click="reissueInvite('{{ $client['id'] }}')">Reissue owner setup</x-filament::button>@endif</div></div>@endforeach</div></section>
        <section class="bp-card bp-stack"><h2>Users search</h2><div class="bp-grid"><label class="bp-field">Client<select wire:model.live="staffClient"><option value="">Select client</option>@foreach($clients as $client)<option value="{{ $client['id'] }}">{{ $client['name'] }}</option>@endforeach</select></label><label class="bp-field">Staff name or phone<input wire:model.live.debounce.300ms="staffSearch" /></label></div><div class="bp-records">@foreach($staff as $user)<p class="bp-record">{{ $user['name'] }} · {{ $user['phone'] }} · {{ $user['role'] }} · {{ $user['active']?'active':'inactive' }}</p>@endforeach</div></section>
    </div>
</x-filament-panels::page>
