<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Queries;

use App\Models\Partner;
use App\Models\Post;
use App\Models\SuccessStory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Finding partners and success stories, for the public site.
 *
 * READ ONLY, and the same discipline as {@see ProductDiscoveryQuery}: this
 * assembles what a page needs to show and decides nothing. Every read starts
 * from a model scope that requires `active`, so visibility is decided by the
 * scope rather than by remembering to filter at each call site.
 *
 * BOUNDED. The homepage versions take a limit rather than a count, because a
 * section of a front page is a fixed amount of space and reading the whole
 * table to decide which six of several hundred to render would be the wrong
 * trade. Both default limits are generous relative to what fits.
 *
 * There is no search, no filter and no sort selector for this content. That is
 * deliberate rather than pending: a partners strip and a page of short quotes
 * are things a person reads down, not things anybody interrogates, and every
 * filter added here is a query a crafted URL can aim at the database.
 */
class ContentDiscoveryQuery
{
    /** How many partners a page of the listing holds. */
    public const PARTNERS_PER_PAGE = 24;

    public const STORIES_PER_PAGE = 9;

    /** The homepage partner strip. Six fits three columns on a wide screen and two on a phone. */
    public const HOME_PARTNER_LIMIT = 6;

    /** The homepage story block. Three is a row of cards, or a scroll of them on a phone. */
    public const HOME_STORY_LIMIT = 3;

    /** The homepage blog strip. Four is a row across, or a scroll of cards on a phone. */
    public const HOME_POST_LIMIT = 4;

    /**
     * @return LengthAwarePaginator<int, Partner>
     */
    public function partners(int $perPage = self::PARTNERS_PER_PAGE): LengthAwarePaginator
    {
        return Partner::published()->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, SuccessStory>
     */
    public function stories(int $perPage = self::STORIES_PER_PAGE): LengthAwarePaginator
    {
        return SuccessStory::published()->paginate($perPage);
    }

    /**
     * Published partners for the homepage strip, in administrator-set order.
     *
     * @return EloquentCollection<int, Partner>
     */
    public function homepagePartners(int $limit = self::HOME_PARTNER_LIMIT): EloquentCollection
    {
        return Partner::published()->limit($limit)->get();
    }

    /**
     * Published AND featured stories for the homepage block.
     *
     * `featured` on its own is not enough: the flag records that an
     * administrator wanted this story promoted, and it should not survive the
     * story being unpublished.
     *
     * @return EloquentCollection<int, SuccessStory>
     */
    public function homepageStories(int $limit = self::HOME_STORY_LIMIT): EloquentCollection
    {
        return SuccessStory::featured()->limit($limit)->get();
    }

    /**
     * Published posts for the homepage strip, newest first.
     *
     * @return EloquentCollection<int, Post>
     */
    public function homepagePosts(int $limit = self::HOME_POST_LIMIT): EloquentCollection
    {
        return Post::published()
            ->with(['category', 'tags'])
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Posts worth reading next to this one, newest first.
     *
     * Same category first (a reader inside a topic wants more of it), then
     * posts sharing any of its tags -- deterministic, built from the taxonomy
     * an administrator already maintains, and read-only like everything here.
     * The opened post is never among them, drafts never are, and the count is
     * bounded by `$limit` so the section stays a fixed amount of space.
     *
     * @return EloquentCollection<int, Post>
     */
    public function relatedPosts(Post $post, int $limit = self::HOME_POST_LIMIT): EloquentCollection
    {
        $query = Post::published()
            ->with(['category', 'tags'])
            ->whereKeyNot($post->id)
            ->latest('published_at')
            ->latest('id');

        $sameCategory = new EloquentCollection;

        if ($post->category_id !== null) {
            $sameCategory = (clone $query)
                ->where('category_id', $post->category_id)
                ->limit($limit)
                ->get();
        }

        if ($sameCategory->count() >= $limit || $post->tags->isEmpty()) {
            return $sameCategory;
        }

        $tagIds = $post->tags->pluck('id')->all();

        $byTag = (clone $query)
            ->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.id', $tagIds))
            ->whereNotIn('id', $sameCategory->modelKeys())
            ->limit($limit - $sameCategory->count())
            ->get();

        return $sameCategory->concat($byTag);
    }
}
