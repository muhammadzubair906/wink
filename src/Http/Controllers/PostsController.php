<?php

namespace Wink\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Wink\Http\Resources\PostsResource;
use Wink\WinkPost;
use Wink\WinkTag;

class PostsController
{
    /**
     * Return posts.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $entries = WinkPost::when(request()->has('search'), function ($q) {
            $q->where('title', 'LIKE', '%'.request('search').'%');
        })->when(request('status'), function ($q, $value) {
            $q->$value();
        })->when(request('author_id'), function ($q, $value) {
            $q->whereAuthorId($value);
        })->when(request('tag_id'), function ($q, $value) {
            $q->whereHas('tags', function ($query) use ($value) {
                $query->where('id', $value);
            });
        })
            ->orderBy('created_at', 'DESC')
            ->with('tags')
            ->paginate(config('wink.pagination.posts', 30));

        return PostsResource::collection($entries);
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
                'entry' => WinkPost::make([
                    'id' => Str::uuid(),
                    'publish_date' => now()->format('Y-m-d H:i:00'),
                    'markdown' => null,
                ]),
            ]);
        }

        $entry = WinkPost::with('tags')->findOrFail($id);

        return response()->json([
            'entry' => $entry,
        ]);
    }

    /**
     * Store a single post.
     *
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($id)
    {
        $data = [
            'title' => request('title'),
            'excerpt' => request('excerpt', ''),
            'slug' => request('slug'),
            'body' => request('body', ''),
            'published' => request('published'),
            'markdown' => request('markdown'),
            'author_id' => request('author_id'),
            'featured_image' => request('featured_image'),
            'featured_image_caption' => request('featured_image_caption', ''),
            'publish_date' => request('publish_date', ''),
            'meta' => request('meta', (object) []),
        ];

        validator($data, [
            'publish_date' => 'required|date',
            'author_id' => 'required|exists:wink_authors,id', // adjust if needed
            'title' => 'required|string|max:255',
            'slug' => [
                'required',
                Rule::unique(config('wink.database_connection').'.wink_posts', 'slug')
                    ->ignore(request('id')),
            ],
        ])->validate();

        $entry = $id !== 'new'
            ? WinkPost::findOrFail($id)
            : new WinkPost(['id' => request('id')]);

        $entry->fill($data);
        $entry->save();

        $tagIds = $this->collectTags(request('tags', []));
        $entry->tags()->sync($tagIds);

        return response()->json([
            'entry' => $entry->load('tags'),
        ]);
    }

    /**
     * Tags incoming from the request.
     *
     * @param  array  $incomingTags
     * @return array
     */
    private function collectTags($incomingTags)
    {
        // Normalize and deduplicate by name (case-insensitive)
        $normalizedTags = collect($incomingTags)
            ->map(function ($tag) {
                return [
                    'name' => trim(Str::lower($tag['name'] ?? '')),
                    'original' => $tag
                ];
            })
            ->filter(fn($tag) => !empty($tag['name']))
            ->unique('name');

        // Fetch existing tags by name
        $existingTags = WinkTag::whereIn('name', $normalizedTags->pluck('name'))->get();

        $tagIds = [];

        foreach ($normalizedTags as $tag) {
            $existing = $existingTags->firstWhere('name', $tag['name']);

            if ($existing) {
                $tagIds[] = $existing->id;
            } else {
                $newTag = WinkTag::create([
                    'id' => (string) Str::uuid(),
                    'name' => $tag['original']['name'],
                    'slug' => Str::slug($tag['original']['name']),
                ]);
                $tagIds[] = $newTag->id;
            }
        }

        return $tagIds;
    }



    /**
     * Return a single post.
     *
     * @param  string  $id
     * @return void
     */
    public function delete($id)
    {
        $entry = WinkPost::findOrFail($id);

        $entry->delete();
    }
}
