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
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();

            // يمثل يوم تحليل واحد فقط
            $table->date('run_date')->unique();

            // معلومات عامة عن تشغيل التحليل
            $table->string('status')->default('completed'); // pending, completed, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // أرقام إجمالية تساعد في التقارير والمراجعة
            $table->unsignedInteger('marketwatch_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('day_candidates_count')->default(0);
            $table->unsignedInteger('swing_candidates_count')->default(0);

            // نسخة من الإعدادات المستخدمة وقت التحليل (اختياري لكنه مفيد)
            $table->json('config_snapshot')->nullable();

            // ملاحظات / خطأ إن وجد
            $table->text('notes')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
            $table->index('finished_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};