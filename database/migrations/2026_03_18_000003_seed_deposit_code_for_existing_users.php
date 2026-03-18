<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->whereNull('deposit_code')->orderBy('id')->get(['id']);

        foreach ($users as $user) {
            $code = $this->generateUniqueCode();
            DB::table('users')->where('id', $user->id)->update(['deposit_code' => $code]);
        }
    }

    private function generateUniqueCode(): string
    {
        $maxAttempts = 20;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = 'NAP' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = DB::table('users')->where('deposit_code', $candidate)->exists();
            if (!$exists) {
                return $candidate;
            }
        }
        // fallback: thêm timestamp để chắc chắn unique
        return 'NAP' . substr((string) time(), -6);
    }

    public function down(): void
    {
        // không rollback
    }
};
