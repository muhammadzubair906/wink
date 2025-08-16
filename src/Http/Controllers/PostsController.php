<?php

namespace Wink\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Random\RandomException;
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
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     * @throws RandomException
     */
    public function store($id)
    {
        $data = [
            'title' => request('title'),
            'excerpt' => request('excerpt', ''),
            'slug' => request('slug', $this->slugify(request('title', 'post-'. random_int(1, 10000)))),
            'body' => request('body', ''),
            'published' => request('published'),
            'markdown' => request('markdown', 0),
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
        $normalizedTags = collect($incomingTags)
            ->map(function ($tag) {
                $name = trim($tag['name'] ?? '');
                return [
                    'name' => strtolower($name),
                    'slug' => Str::slug($name),
                    'original' => $tag,
                ];
            })
            ->filter(fn($tag) => !empty($tag['name']))
            ->unique('name');

        // Fetch existing tags by slug (instead of just name)
        $existingTags = WinkTag::whereIn('slug', $normalizedTags->pluck('slug'))->get();

        $tagIds = [];

        foreach ($normalizedTags as $tag) {
            $existing = $existingTags->firstWhere('slug', $tag['slug']);

            if ($existing) {
                $tagIds[] = $existing->id;
            } else {
                // Auto-increment slug if needed
                $uniqueSlug = $this->getUniqueSlug($tag['slug']);

                $newTag = WinkTag::create([
                    'id' => (string) Str::uuid(),
                    'name' => $tag['original']['name'],
                    'slug' => $uniqueSlug,
                ]);

                $tagIds[] = $newTag->id;
            }
        }

        return $tagIds;
    }



    private function getUniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while (WinkTag::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        return $slug;
    }



    /**
     * Convert string to slug.
     *
     * @param  string  $text
     * @return string
     */
    function slugify(string $text): string
    {
        // Lowercase
        $text = strtolower($text);

        // Replace spaces with hyphens
        $text = preg_replace('/\s+/', '-', $text);

        // Remove all non-word characters except hyphens
        $text = preg_replace('/[^\w\-]+/', '', $text);

        // Replace multiple hyphens with a single one
        $text = preg_replace('/\-+/', '-', $text);

        // Trim hyphens from the start and end
        return trim($text, '-');
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
