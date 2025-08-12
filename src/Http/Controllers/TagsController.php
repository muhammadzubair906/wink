<?php

namespace Wink\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Wink\Http\Resources\TagsResource;
use Wink\WinkTag;

class TagsController
{
    /**
     * Return posts.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $entries = WinkTag::when(request()->has('search'), function ($q) {
            $q->where('name', 'LIKE', '%'.request('search').'%');
        })
            ->orderBy('created_at', 'DESC')
            ->withCount('posts')
            ->paginate(config('wink.pagination.tags', 30));

        return TagsResource::collection($entries);
    }

    /**
     * Return a single post.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id = null)
    {
        if ($id === 'new') {
            return response()->json([
                'entry' => WinkTag::make([
                    'id' => Str::uuid(),
                ]),
            ]);
        }

        $entry = WinkTag::findOrFail($id);

        return response()->json([
            'entry' => $entry,
        ]);
    }

    /**
     * Store a single category.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */

    public function store($id)
    {
        // Normalize input
        $name = trim(request('name'));
        $slug = trim(request('slug')) ?: Str::slug($name); // fallback to slugify name

        $data = [
            'name' => $name,
            'slug' => Str::slug($slug), // ensure normalized slug
            'meta' => request('meta', (object) []),
        ];

        validator($data, [
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                Rule::unique(config('wink.database_connection') . '.wink_tags', 'slug')->ignore(request('id')),
            ],
        ])->validate();

        // Create or update tag
        $entry = $id !== 'new'
            ? WinkTag::findOrFail($id)
            : new WinkTag(['id' => request('id')]);

        $entry->fill($data);

        try {
            $entry->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'wink_tags_slug_unique')) {
                return response()->json([
                    'error' => 'A tag with the same slug already exists.',
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'entry' => $entry->fresh(),
        ]);
    }

    /**
     * Return a single tag.
     *
     * @param  string  $id
     * @return void
     */
    public function delete($id)
    {
        $entry = WinkTag::findOrFail($id);

        $entry->delete();
    }
}
