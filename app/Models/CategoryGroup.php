<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CategoryGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'sort_order',
        'is_active',
        'group_id',
        'category_id',
        'description',
    ];

    protected static function booted(): void
    {
        static::creating(function (CategoryGroup $categoryGroup) {
            if (empty($categoryGroup->slug)) {
                $categoryGroup->slug = static::generateUniqueSlug($categoryGroup->name);
            }
        });

        static::updating(function (CategoryGroup $categoryGroup) {
            if ($categoryGroup->isDirty('name') && !$categoryGroup->isDirty('slug')) {
                $categoryGroup->slug = static::generateUniqueSlug($categoryGroup->name, $categoryGroup->id);
            }
        });
    }

    public static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $count = 1;

        $query = static::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = $originalSlug . '-' . $count++;
            $query = static::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
