<?php

use App\Models\CategoryGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Thêm cột slug (nullable trước) nếu chưa có
        if (!Schema::hasColumn('category_groups', 'slug')) {
            Schema::table('category_groups', function (Blueprint $table) {
                $table->string('slug')->nullable()->after('name');
            });
        }

        // Tạo slug cho các record hiện có
        $categoryGroups = CategoryGroup::all();
        foreach ($categoryGroups as $categoryGroup) {
            if (empty($categoryGroup->slug)) {
                $categoryGroup->slug = $this->generateUniqueSlug($categoryGroup->name);
                $categoryGroup->saveQuietly();
            }
        }

        // Thêm unique constraint
        Schema::table('category_groups', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->unique()->change();
        });
    }

    private function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        while (CategoryGroup::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count++;
        }

        return $slug;
    }

    public function down(): void
    {
        Schema::table('category_groups', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
