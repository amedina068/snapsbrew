<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        DB::table('usage_logs')
            ->whereNull('company_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) {
                foreach ($logs as $log) {
                    $companyId = DB::table('users')
                        ->where('id', $log->user_id)
                        ->value('company_id');

                    if ($companyId) {
                        DB::table('usage_logs')
                            ->where('id', $log->id)
                            ->update(['company_id' => $companyId]);
                    }
                }
            });

        if (Schema::hasColumn('usage_logs', 'user_id')) {
            Schema::table('usage_logs', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasColumn('subscriptions', 'user_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
            });

            DB::table('subscriptions')
                ->whereNull('company_id')
                ->orderBy('id')
                ->chunkById(500, function ($subscriptions) {
                    foreach ($subscriptions as $subscription) {
                        $companyId = DB::table('users')
                            ->where('id', $subscription->user_id)
                            ->value('company_id');

                        if ($companyId) {
                            DB::table('subscriptions')
                                ->where('id', $subscription->id)
                                ->update(['company_id' => $companyId]);
                        }
                    }
                });

            Schema::table('subscriptions', function (Blueprint $table) {
                // Laravel 11's SQLite grammar will not drop the dependent index
                // automatically; drop it before the column or the alter errors.
                if (Schema::hasIndex('subscriptions', ['user_id', 'stripe_status'])) {
                    $table->dropIndex(['user_id', 'stripe_status']);
                }
                $table->dropColumn('user_id');
            });
        }

        DB::table('users')
            ->whereNotNull('company_id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $values = [];

                    if (! is_null($user->stripe_id)) {
                        $values['stripe_id'] = $user->stripe_id;
                    }

                    if (! is_null($user->pm_type)) {
                        $values['pm_type'] = $user->pm_type;
                    }

                    if (! is_null($user->pm_last_four)) {
                        $values['pm_last_four'] = $user->pm_last_four;
                    }

                    if (! is_null($user->trial_ends_at)) {
                        $values['trial_ends_at'] = $user->trial_ends_at;
                    }

                    if (! empty($values)) {
                        DB::table('companies')
                            ->where('id', $user->company_id)
                            ->update($values);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id');
        });

        DB::table('usage_logs')
            ->whereNotNull('company_id')
            ->chunkById(100, function ($logs) {
                foreach ($logs as $log) {
                    $userId = DB::table('users')
                        ->where('company_id', $log->company_id)
                        ->value('id');

                    DB::table('usage_logs')
                        ->where('id', $log->id)
                        ->update(['user_id' => $userId]);
                }
            });

        if (Schema::hasColumn('usage_logs', 'company_id')) {
            Schema::table('usage_logs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_id');
            });
        }

        if (Schema::hasColumn('subscriptions', 'company_id')) {
            DB::table('subscriptions')
                ->chunkById(100, function ($subscriptions) {
                    foreach ($subscriptions as $subscription) {
                        $userId = DB::table('users')
                            ->where('company_id', $subscription->company_id)
                            ->value('id');

                        DB::table('subscriptions')
                            ->where('id', $subscription->id)
                            ->update(['company_id' => $userId]);
                    }
                });

            Schema::table('subscriptions', function (Blueprint $table) {
                $table->renameColumn('company_id', 'user_id');
            });
        }

        DB::table('companies')
            ->chunkById(100, function ($companies) {
                foreach ($companies as $company) {
                    $values = [];

                    if (! is_null($company->stripe_id)) {
                        $values['stripe_id'] = $company->stripe_id;
                    }

                    if (! is_null($company->pm_type)) {
                        $values['pm_type'] = $company->pm_type;
                    }

                    if (! is_null($company->pm_last_four)) {
                        $values['pm_last_four'] = $company->pm_last_four;
                    }

                    if (! is_null($company->trial_ends_at)) {
                        $values['trial_ends_at'] = $company->trial_ends_at;
                    }

                    if (! empty($values)) {
                        DB::table('users')
                            ->where('company_id', $company->id)
                            ->update($values);
                    }
                }
            });

        $companyColumns = collect([
            'stripe_id',
            'pm_type',
            'pm_last_four',
            'trial_ends_at',
        ])->filter(fn ($column) => Schema::hasColumn('companies', $column))->values()->all();

        if (! empty($companyColumns)) {
            Schema::table('companies', function (Blueprint $table) {
                // stripe_id is indexed; Laravel 11's SQLite grammar will not drop
                // the dependent index automatically, so drop it before the column.
                if (Schema::hasIndex('companies', ['stripe_id'])) {
                    $table->dropIndex(['stripe_id']);
                }
            });

            Schema::table('companies', function (Blueprint $table) use ($companyColumns) {
                $table->dropColumn($companyColumns);
            });
        }
    }
};
