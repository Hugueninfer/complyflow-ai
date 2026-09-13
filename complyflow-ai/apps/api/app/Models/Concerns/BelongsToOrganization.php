<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::creating(function ($model): void {
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', app(CurrentOrganization::class)->id());
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->where($query->qualifyColumn('organization_id'), $organizationId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentOrganization(Builder $query): Builder
    {
        return $query->forOrganization(app(CurrentOrganization::class)->id());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWherePublicIdForCurrentOrganization(Builder $query, string $publicId): Builder
    {
        return $query->forCurrentOrganization()->where('public_id', $publicId);
    }
}
