<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('sale_items', fn(Blueprint $t) => $t->unique(['business_id','sale_id','id'], 'return_source_item_unique'));
        Schema::table('product_batches', function(Blueprint $t) {
            $t->unsignedBigInteger('source_batch_id')->nullable();
            $t->foreign(['business_id','product_id','source_batch_id'], 'quarantine_source_batch_fk')->references(['business_id','product_id','id'])->on('product_batches')->restrictOnDelete();
        });
        Schema::create('sale_returns', function(Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('sale_id');
            foreach(['user_id','device_id','approved_by','till_session_id'] as $c) $t->unsignedBigInteger($c);
            $t->uuid('operation_id'); $t->char('request_fingerprint',64); $t->text('reason');
            foreach(['refund_total','debt_reduction','cash_refund','cash_collected','cash_paid'] as $c) $t->unsignedBigInteger($c);
            $t->json('lines'); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','operation_id']); $t->unique(['business_id','sale_id','id']); $t->unique(['business_id','id']);
            foreach(['sale_id'=>'sales','user_id'=>'users','device_id'=>'devices','approved_by'=>'users','till_session_id'=>'till_sessions'] as $c=>$table)
                $t->foreign(['business_id',$c])->references(['business_id','id'])->on($table)->restrictOnDelete();
        });
        Schema::create('sale_return_items', function(Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('sale_id');
            foreach(['sale_return_id','sale_item_id','product_id','batch_id'] as $c) $t->unsignedBigInteger($c);
            $t->bigInteger('qty_atoms'); $t->timestamp('created_at')->useCurrent();
            $t->foreign(['business_id','sale_id','sale_return_id'], 'return_item_parent_fk')->references(['business_id','sale_id','id'])->on('sale_returns')->restrictOnDelete();
            $t->foreign(['business_id','sale_id','sale_item_id'], 'return_item_source_fk')->references(['business_id','sale_id','id'])->on('sale_items')->restrictOnDelete();
            $t->foreign(['business_id','product_id','batch_id'], 'return_item_batch_fk')->references(['business_id','product_id','id'])->on('product_batches')->restrictOnDelete();
        });
        Schema::create('sale_exchanges',function(Blueprint $t) {
            $t->id(); $t->char('public_id',26)->unique(); $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('sale_return_id'); $t->unsignedBigInteger('replacement_sale_id'); $t->timestamp('created_at')->useCurrent();
            $t->unique(['business_id','sale_return_id']); $t->unique(['business_id','replacement_sale_id']);
            $t->foreign(['business_id','sale_return_id'])->references(['business_id','id'])->on('sale_returns')->restrictOnDelete();
            $t->foreign(['business_id','replacement_sale_id'])->references(['business_id','id'])->on('sales')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::dropIfExists('sale_exchanges'); Schema::dropIfExists('sale_return_items'); Schema::dropIfExists('sale_returns');
        Schema::table('product_batches',function(Blueprint $t){$t->dropForeign('quarantine_source_batch_fk');$t->dropColumn('source_batch_id');});
        Schema::table('sale_items',fn(Blueprint $t)=>$t->dropUnique('return_source_item_unique'));
    }
};
