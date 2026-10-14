<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business, CatalogueImportOperation, Product, ProductBarcode, ProductBatch, ProductPackaging, User};
use App\Support\{ExactMath, Money, Quantity};
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class CatalogueImportService
{
    public function __construct(private TenantContext $context, private FinancialActorService $actors, private StockService $stock) {}
    public function preview(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input) {
            $this->authority($actor); [$review] = $this->review($input['rows']);
            return ['rows' => $review, 'review_hash' => SupervisorApprovalService::snapshotHash($review), 'row_count' => count($review)];
        });
    }
    public function commit(User $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input) {
            $current = $this->authority($actor); $operation = strtolower($input['operation_id']);
            $fingerprint = SupervisorApprovalService::snapshotHash(['rows' => $input['rows'], 'review_hash' => $input['review_hash'] ?? null]);
            $existing = CatalogueImportOperation::where('operation_id', $operation)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->user_id !== $current->id || $existing->device_id !== $current->currentAccessToken()->device_id || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new ApiException('OPERATION_ID_REUSED', 'This import reference belongs to different details or staff.', 409);
                }
                return ['import_id' => $existing->publicId(), ...$existing->result, 'replayed' => true];
            }
            [$review, $products] = $this->review($input['rows']);
            if (! hash_equals(SupervisorApprovalService::snapshotHash($review), $input['review_hash'] ?? '')) {
                throw new ApiException('IMPORT_REVIEW_CHANGED', 'The catalogue changed. Review this import again before committing.', 409);
            }
            $batches = [];
            foreach ($review as $index => $row) {
                // UUIDv5 deterministically binds each StockService receipt to this import and row.
                $result = $this->stock->receive($current, $products[$index], ['operation_id' => (string) Uuid::uuid5($operation, 'opening-stock-row-'.$index),
                    'batch_number' => $row['batch_number'], 'qty' => $row['qty'], 'cost_price' => $row['cost_price'],
                    'expiry_date' => $row['expiry_date'], 'packaging_id' => $row['packaging_id']]);
                $batches[] = ['row' => $index + 1, 'batch_id' => $result['batch']->publicId(), 'movement_id' => $result['movement']->publicId()];
            }
            $output = ['row_count' => count($review), 'batches' => $batches];
            $record = CatalogueImportOperation::create(['user_id' => $current->id, 'device_id' => $current->currentAccessToken()->device_id,
                'operation_id' => $operation, 'request_fingerprint' => $fingerprint, 'result' => $output]);
            activity('stock')->performedOn($record)->causedBy($current)->event('opening_stock_imported')
                ->withProperties(['attributes' => ['operation_id' => $operation, 'row_count' => count($review)]])->log('Opening stock imported');
            return ['import_id' => $record->publicId(), ...$output, 'replayed' => false];
        }, 3);
    }
    private function authority(User $actor): User
    {
        $current = $this->actors->lock($actor, 'receive_stock');
        if (! $current->can('view_buying_price')) { throw new AuthorizationException; }
        Business::whereKey($this->context->requireId())->where('is_active',true)->lockForUpdate()->firstOrFail();
        return $current;
    }
    private function review(array $rows): array
    {
        $review = []; $products = []; $seen = []; $aggregate = [];
        foreach ($rows as $index => $input) {
            $field = 'rows.'.$index;
            try {
                if (isset($seen[$input['barcode']])) { throw new ApiException('IMPORT_ROW_INVALID', 'A barcode appears more than once. Combine its quantity or import separate batches separately.', 422); }
                $seen[$input['barcode']] = true;
                $code = ProductBarcode::where('barcode', $input['barcode'])->lockForUpdate()->first();
                $product = $code ? Product::whereKey($code->product_id)->lockForUpdate()->first() : null;
                if (! $product || ! $product->is_active) { throw new ApiException('IMPORT_ROW_INVALID', 'The barcode does not identify an active product in this shop.', 422); }
                $pack = $code->packaging_id ? ProductPackaging::whereKey($code->packaging_id)->lockForUpdate()->first() : null;
                if (($code->packaging_id && (! $pack || $pack->archived_at || ! $pack->is_active)) || (! $code->packaging_id && $product->barcode !== $input['barcode'])) {
                    throw new ApiException('IMPORT_ROW_INVALID', 'This barcode belongs to an archived product or packaging version.', 422);
                }
                $weighted = $product->quantity_type === 'weight';
                $entered = Quantity::parse($input['qty'], weighted: $weighted && ! $pack);
                if ($entered <= 0) { throw new \InvalidArgumentException('Receive a positive quantity.'); }
                $qty = Quantity::format($entered, $weighted && ! $pack);
                $atoms = $pack ? Quantity::packageAtoms($qty, $pack->conversion_atoms) : $entered;
                $cost = Money::amount($input['cost_price']); $expiry = $input['expiry_date'] ?? null;
                if ($product->tracks_expiry && ! $expiry) { throw new \InvalidArgumentException('An expiry date is required.'); }
                if ($expiry && $expiry < now('Africa/Dar_es_Salaam')->toDateString()) { throw new \InvalidArgumentException('Already-expired goods must be returned to the supplier.'); }
                $aggregate[$product->id] = ExactMath::add($aggregate[$product->id] ?? 0, $atoms);
                $balance = (string) ProductBatch::where('product_id', $product->id)->sum('qty_remaining_atoms');
                if (strlen($balance) > strlen((string) PHP_INT_MAX) || (strlen($balance) === strlen((string) PHP_INT_MAX) && strcmp($balance, (string) PHP_INT_MAX) > 0)) { throw new \OverflowException('Stock exceeds the supported quantity.'); }
                ExactMath::add((int) $balance, $aggregate[$product->id]);
                $review[] = ['row' => $index + 1, 'barcode' => $input['barcode'], 'product_id' => $product->publicId(), 'product_name' => $product->name,
                    'packaging_id' => $pack?->publicId(), 'packaging_name' => $pack?->name, 'packaging_version' => $pack?->version,
                    'selling_price' => $pack?->selling_price ?? $product->selling_price, 'unit' => $product->unit, 'qty' => $qty,
                    'base_qty' => Quantity::format($atoms, $weighted), 'cost_price' => $cost, 'batch_number' => $input['batch_number'], 'expiry_date' => $expiry];
                $products[] = $product;
            } catch (ApiException|\InvalidArgumentException|\OverflowException $e) {
                throw new ApiException('IMPORT_ROW_INVALID', 'Row '.($index + 1).': '.$e->getMessage(), 422, $field, ['row' => $index + 1]);
            }
        }
        return [$review, $products];
    }
}
