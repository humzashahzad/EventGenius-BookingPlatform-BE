<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * List all categories (admin).
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * List active categories (public — no auth required).
     */
    public function publicIndex(): JsonResponse
    {
        $categories = Category::active()->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * Create a new category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'slug'      => 'required|string|max:255|unique:categories,slug',
            'emoji'     => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'data'    => $category,
            'message' => 'Category created.',
        ], 201);
    }

    /**
     * Show a single category.
     */
    public function show(string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        return response()->json(['success' => true, 'data' => $category]);
    }

    /**
     * Update a category.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'slug'      => ['sometimes', 'string', 'max:255', Rule::unique('categories')->ignore($id)],
            'emoji'     => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $category,
            'message' => 'Category updated.',
        ]);
    }

    /**
     * Delete a category (soft delete).
     */
    public function destroy(string $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }
}
