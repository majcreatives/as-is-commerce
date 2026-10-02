<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A partner listed on the public site.
 *
 * Publishing is the `active` flag and nothing else. The public pages and the
 * homepage both read through {@see self::published()}, so an unpublished
 * partner cannot appear on any public surface by being added and forgotten --
 * it has to be published deliberately.
 *
 * Display order is `sort_order` then `id`, which is deterministic even if two
 * records share a position.
 *
 * @property int $id
 * @property string $name
 * @property string|null $url
 * @property string|null $logo_path
 * @property string|null $description
 * @property int $sort_order
 * @property bool $active
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'url',
        'logo_path',
        'description',
        'sort_order',
        'active',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * The scope every public read uses: published, in display order.
     *
     * @param  Builder<Partner>  $query
     * @return Builder<Partner>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The publicly reachable URL of this partner's logo.
     *
     * Null when no logo was uploaded -- a partner is listed by name alone in
     * that case, which is honest rather than a broken image.
     */
    public function logoUrl(): ?string
    {
        if ($this->logo_path === null || $this->logo_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }
}
