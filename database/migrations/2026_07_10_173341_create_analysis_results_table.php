<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('analysis_run_id')
                ->constrained('analysis_runs')
                ->cascadeOnDelete();

            /**
             * نوع النتيجة:
             * day_trade | swing
             */
            $table->string('analysis_type');

            /**
             * ترتيب النتيجة داخل القائمة
             * مثال:
             * day_trade top 10 => rank من 1 إلى 10
             * swing top 10 => rank من 1 إلى 10
             */
            $table->unsignedTinyInteger('rank')->nullable();

            /**
             * بيانات السهم الأساسية
             */
            $table->string('asset_id')->nullable();
            $table->string('market_id')->nullable();
            $table->string('symbol_code')->index();
            $table->string('reuters')->nullable()->index();
            $table->string('symbol_state')->nullable();

            $table->string('arb_name')->nullable();
            $table->string('eng_name')->nullable();
            $table->string('sector_name')->nullable();

            /**
             * Snapshot من marketwatch وقت التحليل
             */
            $table->decimal('price', 18, 6)->nullable();
            $table->decimal('change_value', 18, 6)->nullable();
            $table->decimal('change_percent', 10, 4)->nullable();

            $table->decimal('open_price', 18, 6)->nullable();
            $table->decimal('high_price', 18, 6)->nullable();
            $table->decimal('low_price', 18, 6)->nullable();
            $table->decimal('close_price', 18, 6)->nullable();
            $table->decimal('previous_close', 18, 6)->nullable();
            $table->decimal('ref_price', 18, 6)->nullable();

            $table->decimal('bid_price', 18, 6)->nullable();
            $table->decimal('ask_price', 18, 6)->nullable();
            $table->decimal('bid_volume', 18, 2)->nullable();
            $table->decimal('ask_volume', 18, 2)->nullable();

            $table->decimal('total_value', 18, 2)->nullable();
            $table->decimal('total_volume', 18, 2)->nullable();
            $table->unsignedBigInteger('total_trades')->nullable();

            $table->decimal('avg_5_day', 18, 2)->nullable();
            $table->decimal('avg_30_day', 18, 2)->nullable();
            $table->decimal('avg_90_day', 18, 2)->nullable();

            $table->decimal('high_52_week', 18, 6)->nullable();
            $table->decimal('low_52_week', 18, 6)->nullable();

            $table->decimal('eps', 18, 6)->nullable();
            $table->decimal('pe_ratio', 18, 6)->nullable();

            /**
             * نتيجة التحليل
             */
            $table->string('signal');
            $table->decimal('entry_price', 18, 6)->nullable();
            $table->decimal('entry_from', 18, 6)->nullable();
            $table->decimal('entry_to', 18, 6)->nullable();
            $table->decimal('stop_loss', 18, 6)->nullable();
            $table->decimal('target_1', 18, 6)->nullable();
            $table->decimal('target_2', 18, 6)->nullable();

            /**
             * Scores
             */
            $table->decimal('opportunity_score', 8, 2)->default(0);
            $table->decimal('risk_score', 8, 2)->default(0);
            $table->decimal('confidence_score', 8, 2)->default(0);

            /**
             * تفاصيل التحليل
             */
            $table->json('reasons')->nullable();
            $table->json('metrics')->nullable();
            $table->json('marketwatch_snapshot')->nullable();
            $table->json('depth_snapshot')->nullable();

            $table->timestamps();

            $table->index(['analysis_run_id', 'analysis_type']);
            $table->index(['analysis_run_id', 'analysis_type', 'rank']);
            $table->index(['symbol_code', 'analysis_type']);
            $table->index('signal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};