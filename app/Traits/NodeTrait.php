<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Custom NodeTrait to replace kalnoy/nestedset NodeTrait.
 * Provides nested set functionality for hierarchical data using _lft, _rgt, parent_id columns.
 */
trait NodeTrait
{
    public static function bootNodeTrait(): void
    {
        static::creating(function ($model) {
            if (!$model->_lft) {
                $max = static::withTrashed()->max('_rgt') ?? 0;
                $model->_lft = $max + 1;
                $model->_rgt = $max + 2;
            }
        });
    }

    /**
     * Get the lft column name.
     */
    public function getLftName(): string
    {
        return '_lft';
    }

    /**
     * Get the rgt column name.
     */
    public function getRgtName(): string
    {
        return '_rgt';
    }

    /**
     * Get the parent id column name.
     */
    public function getParentIdName(): string
    {
        return 'parent_id';
    }

    /**
     * Relation to children (nested set children).
     */
    public function children()
    {
        return $this->hasMany(static::class, 'parent_id', 'id');
    }

    /**
     * Relation to parent node.
     */
    public function parent()
    {
        return $this->belongsTo(static::class, 'parent_id', 'id');
    }

    /**
     * Get ancestors of this node (from root to parent).
     */
    public function getAncestorsAttribute(): Collection
    {
        $ancestors = new Collection();
        $node = $this;

        while ($node->parent_id) {
            $node = static::withTrashed()->find($node->parent_id);
            if ($node) {
                $ancestors->prepend($node);
            } else {
                break;
            }
        }

        return $ancestors;
    }

    /**
     * Static method to get ancestors of a node by ID, ordered by _lft (defaultOrder).
     */
    public static function ancestorsOf($id): Collection
    {
        $node = static::withTrashed()->find($id);

        if (!$node) {
            return new Collection();
        }

        return $node->ancestors;
    }

    /**
     * Get all descendants using nested set _lft/_rgt range.
     */
    public function descendants(): Builder
    {
        return static::withTrashed()
            ->where('_lft', '>', $this->_lft)
            ->where('_rgt', '<', $this->_rgt);
    }

    /**
     * Scope: order by _lft (default tree order).
     */
    public function scopeDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('_lft');
    }

    /**
     * Append this node to a parent node. Sets parent_id and recalculates _lft/_rgt.
     * Returns $this for chaining with ->save().
     */
    public function appendToNode($parent): static
    {
        $this->parent_id = $parent->id;
        $this->_pendingAppendTo = $parent;

        return $this;
    }

    /**
     * Save this node as a root node (no parent).
     */
    public function saveAsRoot(): bool
    {
        $this->parent_id = null;

        // Recalculate _lft/_rgt for root position
        $max = static::withTrashed()
            ->where('id', '!=', $this->id ?? 0)
            ->max('_rgt') ?? 0;

        $width = ($this->exists && $this->_rgt && $this->_lft)
            ? $this->_rgt - $this->_lft
            : 1;

        $this->_lft = $max + 1;
        $this->_rgt = $max + 1 + $width;

        return $this->save();
    }

    /**
     * Override save to handle pending appendToNode.
     */
    public function save(array $options = []): bool
    {
        if (isset($this->_pendingAppendTo)) {
            $parent = $this->_pendingAppendTo;
            unset($this->_pendingAppendTo);

            // Shift existing nodes to make room
            $parentRgt = $parent->_rgt;

            $width = ($this->exists && $this->_rgt && $this->_lft)
                ? $this->_rgt - $this->_lft + 1
                : 2;

            // Make space in the tree
            static::withTrashed()
                ->where('_rgt', '>=', $parentRgt)
                ->increment('_rgt', $width);

            static::withTrashed()
                ->where('_lft', '>', $parentRgt)
                ->increment('_lft', $width);

            // Set this node's position
            $this->_lft = $parentRgt;
            $this->_rgt = $parentRgt + $width - 1;

            // Refresh parent's _rgt
            $parent->refresh();
        }

        return parent::save($options);
    }
}
