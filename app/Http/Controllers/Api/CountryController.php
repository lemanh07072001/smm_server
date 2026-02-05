<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Country::query()
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('status', $status === '1' || $status === 'true' ? 1 : 0);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $countries = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $countries,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function all(): JsonResponse
    {
        $countries = Country::where('status', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'data' => $countries,
        ]);
    }
}
